# @worksuite/bugtrace-web

Bug Trace SDK for the WorkSuite web client and Electron renderers (SRS §18.7).
It captures crashes and errors, records privacy-safe breadcrumbs, tracks sessions for
crash-free rates, queues everything offline, and follows the tenant's reporting policy.
It also includes the framework-free **Report a bug** dialog (B4).

* Bundle: about 13 KB gzip for everything, including the dialog. The build fails above 25 KB (NFR-C19).
* No runtime dependencies. Written in TypeScript and shipped as ESM plus an IIFE (`window.WorkSuiteBugTrace`).
* Every call is fire-and-forget: the SDK never throws into the app, and a Bug Trace outage never affects WorkSuite (NFR-C21).

## Integration (web/src)

```ts
// main.tsx — before render
import * as bugtrace from '@worksuite/bugtrace-web';

bugtrace.init({
  key: 'pk_web_prod_…',                    // per-project public ingest key
  release: import.meta.env.VITE_RELEASE,   // "3.4.0+34012" — must match the Update Center release
  tpt: () => auth.tenantPolicyToken,       // Tenant Policy Token (carries the `bugtrace` policy claim)
  endpoint: 'https://download.worksuitecloud.com',
  installationRef: tenant.installationRef, // HMAC-pseudonymised by the tenant
  userRef: auth.userRefHmac,               // "u_hmac:…" — never a real user id or e-mail
  edition: tenant.edition,
  contactEmail: auth.email,                // only shown (masked) in the contact-consent checkbox
});

// The existing unhandledrejection listener keeps reloading on stale chunks. The SDK records those
// as breadcrumbs and reports every other rejection.
```

```tsx
// components/ErrorBoundary.tsx — report before rendering the fallback (WF-C30)
componentDidCatch(error: Error, info: React.ErrorInfo) {
  this.setState({ eventId: bugtrace.reportBoundaryError(error, info, this.props.area) });
}
// Fallback button "Report this problem":
<button onClick={() => bugtrace.showReportDialog({ title: `Problem in ${area}` })}>Report this problem</button>
```

Help menu: `bugtrace.showReportDialog()`. **Ctrl/Cmd+Shift+B** opens the dialog anywhere.
Set `reportShortcut: false` to turn the shortcut off.

The fetch wrapper adds HTTP breadcrumbs automatically. Each one records the method, the path
with numeric and UUID segments templated, the status and the duration. It never records query
strings or bodies, and 5xx responses are flagged as errors. `lib/api.ts` needs no changes.
To record other events, use `bugtrace.addBreadcrumb({category, data})`.

Vite: set `build.sourcemap = 'hidden'`. CI then uploads the maps per release:

```sh
curl -fsS -X POST -H "Authorization: Bearer $UC_CI_TOKEN" -H 'Content-Type: application/json' \
  --data-binary @dist/assets/mail-8c1.js.map \
  "https://download.worksuitecloud.com/api/v1/admin/bugtrace/symbols?release_id=$REL_ID&file_name=mail-8c1.js.map&artifact=assets/mail-8c1.js"
```

## What is captured

| Source | Event type | Sampling (default) |
|---|---|---|
| `reportBoundaryError` (ErrorBoundary) and `captureException(e, {type:'crash'})` | `crash` | 100 % |
| `window.onerror`, `unhandledrejection` (not stale-chunk reloads) | `error`, handled=false | 100 % (`sampleRates.unhandled`) |
| `captureException(e)` (handled) | `error` | 25 % (`sampleRates.error`) |
| `captureMessage(msg)` | `message` | 100 % |

Every event carries: app, platform, release, environment, installation ref, edition, user ref,
session id, OS, browser, coarse device info, locale, templated route, area, and up to 50 breadcrumbs.
Breadcrumbs cover navigation, HTTP, UI element labels and lifecycle. They never include input
values, message bodies, file names or the clipboard.

* **De-duplication:** an event with the same fingerprint within 60 s is counted, not re-sent.
* **Client-side scrubbing:** e-mail addresses, JWTs, bearer tokens, password/token key=value pairs,
  API keys, card numbers (Luhn-checked), Sri Lankan NIC numbers (old and new), phone numbers,
  IPv4 addresses and high-entropy tokens become `[Filtered]`. Sensitive keys and content keys are
  filtered, and URLs lose their query strings. Add your own rules with `scrubPatterns`.
  The server scrubs everything again.
* **Offline queue:** IndexedDB (`worksuite-bugtrace`), with a memory fallback. The cap is 200
  items and 20 MB, and the oldest items are dropped first. Retries use exponential backoff from
  1 s to 1 h and give up after 12 attempts. Requests are gzip-compressed with `CompressionStream`
  where available. When the page is hidden, pending data goes out through `sendBeacon`.
* **Sessions:** a session is `started` on init and ends as `crashed` or `exited`. The Update
  Center uses these for crash-free users and sessions.

## Policy and consent (§18.9)

The effective policy comes from the TPT `bugtrace` claim, read locally without a network call.
If the claim is missing, the SDK uses `GET /api/v1/bugtrace/policy` and caches the answer for 24 h.

* `OFF`: no automatic event leaves the device, and queued events are discarded (WF-C38).
  User reports still work if `user_reports` is on.
* `CRASHES_ONLY`: only `crash` and `hang` events are sent.
* `CRASHES_AND_ERRORS`: everything is sent, with sampling.
* Until a policy is known, nothing is sent. Events wait in the local queue.
* `bugtrace.setOptOut(true)` is the user's own opt-out. It is ignored when the tenant contract
  requires crash reporting (`canOptOut()` returns false).
* Envelope responses carry the current policy, so online clients pick up a change right away.

## Report a bug dialog

The dialog collects:

* Title and description ("what happened / what did you expect").
* Severity: Minor, Annoying, Blocks my work or Data looks wrong.
* Category and optional steps.
* An optional screenshot of the current tab. The user must grant `getDisplayMedia` permission;
  the SDK never takes one automatically. The user can annotate it with **arrow** and **box**,
  and hide private details with **blur** (pixelate) or **redact** (solid).
* Technical details, on by default. **What is sent?** shows the exact JSON.
* Optional diagnostic logs, if you pass `getLogs` and the policy allows logs. They are scrubbed
  before upload.
* Contact consent (the e-mail address is shown masked).

When a report is accepted it gets a `BR-…` reference. An offline report is queued and receives
its reference once it is delivered; `onReportAccepted` then fires. `myReports()` lists the
references stored on this device, and `client.reportStatus(receipt)` fetches the reporter
status for "My bug reports".

## API

`init(options)`, `captureException(error, {area, type, handled, tags, extra})`,
`captureMessage(msg, level)`, `addBreadcrumb(b)`, `reportBoundaryError(error, info, area)`,
`setArea(area)`, `setUser(ref)`, `setOptOut(bool)`, `isOptOut()`, `canOptOut()`,
`lastEventId()`, `showReportDialog(opts)`, `sendReport(input)`, `myReports()`, `flush()` and
`close()`. The lower-level building blocks `BugTraceClient`, `Scrubber`, `Queue`,
`MemoryStore`, `IdbStore`, `Annotator`, `captureScreen` and `parseStack` are exported too.

## Development

```sh
npm ci
npm test        # vitest (jsdom + fake-indexeddb): scrubbing, dedup, sampling, queue, policy, client, dialog
npm run build   # esbuild → dist/ (+ .d.ts); fails if a bundle exceeds 25 KB gzip
```
