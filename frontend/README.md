# WorkSuite Update Center — frontend

React 19 + TypeScript (strict) + Tailwind CSS v4 + Vite 7, React Router 7 (library mode), TanStack Query,
`tus-js-client` for resumable uploads. It covers three surfaces from SRS v1.2 §3.3:

| Surface | Routes | Bundle |
|---|---|---|
| Public site (P1–P3) | `/`, `/apps`, `/apps/:slug`, `/apps/:slug/releases/:version`, `/apps/:slug/versions`, `/apps/:slug/changelog`, `/apps/:slug/requirements`, `/changelog`, `/channels`, `/subscriptions/confirm\|unsubscribe` | main entry, which is kept light and prerendered |
| Admin portal (A1–A5, W1–W2, B1–B5) | `/admin/*` | lazy `AdminApp` chunk; the release screen, `tus-js-client` and `qrcode` are split out further |
| User portal | `/portal/*` | lazy `PortalApp` chunk |

The API client is typed against `docs/api-contract.md` (`src/types/api.ts`, `src/services/*.ts`).

## Scripts

```bash
npm ci                 # .npmrc sets legacy-peer-deps (npm 10 crashes resolving vitest 4.1's optional peers)
npm run dev            # Vite on :5173; /api, /download, /_dev-cdn, feeds, /sitemap.xml proxied to :8080
npm run dev:mock       # same, with the in-browser mock API (no backend needed)
npm run build          # tsc -b + client build (dist/) + SSR bundle (dist/server/entry-server.js)
npm run prerender -- --api http://localhost:8080/api/v1   # static HTML for every public route
npm run prerender -- --mock                                # …or from the built-in fixtures
npm run typecheck      # tsc -b --noEmit
npm run test           # Vitest + Testing Library (jsdom)
npm run lint           # ESLint 9 flat config
npm run preview        # serve dist/ (same proxy as dev)
```

## Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `VITE_API_MOCK` | `false` | `true` switches on the in-browser mock API (`src/services/mock/`). This is a literal build-time check, so the mock chunk is left out of normal builds. |
| `VITE_API_BASE` | `/api/v1` | Base path of the JSON API (same origin). |
| `VITE_DEV_PROXY_TARGET` | `http://localhost:8080` | Backend that the dev/preview server proxies to. |
| `VITE_PUBLIC_ORIGIN` | `https://download.worksuitecloud.com` | Used for canonical URLs, OpenGraph and JSON-LD. |
| `UC_API_BASE` (prerender) | `http://localhost:8080/api/v1` | API the prerender script reads from (or pass `--api`). |

## Mock mode

`VITE_API_MOCK=true npm run dev` answers every contract endpoint in the browser. State is kept in memory and resets on reload; the session survives reloads through `sessionStorage`.

The fixtures include:

- **WorkSuite Desktop:** 2.7.0 Stable (published, rollout stage 3 at 50%), 2.7.1 approved and ready to publish, 2.8.0-beta.2 in testing, 2.6.5 supported, 2.6.4 and 2.5.3 deprecated, 2.4.9 unsupported.
- **WorkSuite Server:** 3.4.0 with Free/Business/Enterprise editions (Enterprise still verifying) and a 3.4.1 draft for trying uploads.
- **Other apps:** Installer, Quick Support (1.1.1 withdrawn) and Sync Agent (0.9.5 paused).
- **Bug Trace:** issues and user reports; also installations, audit log, users, API clients, storage and notifications.

Signing in:

- **Staff:** any `…@livezen.lk` email with any password, then any 6-digit code (`000000` is rejected as an invalid code). You get Super Admin plus Release Manager.
- **Viewer:** `viewer@livezen.lk` gets the read-only Viewer role, which is useful for checking permission-filtered navigation.
- **Portal user:** any other email (for example `amali@acme.lk`) signs in as a portal user with beta opt-in.

Critical actions need step-up MFA. The step-up is valid for 10 minutes after sign-in or after any verified code. When it has lapsed, the API returns `401 MFA_REQUIRED` and `<MfaPrompt>` asks for a code.

Uploads go through a mock tus server. Files larger than 16 MB get one simulated network drop so you can see the resume. The processing pipeline then advances about one step every 1.3 s.

## Prerender (SSR)

`src/entry-server.tsx` exports these functions:

- `configureApi(origin)` or `useMockApi()` points the API client at the backend or at the fixtures.
- `listRoutes()` returns every public route from `/site` and `/apps/{slug}/versions`.
- `loadRoute(url)` returns the react-query entries the page needs, using the same keys as the client.
- `render(url, queries)` returns `{ html, head, state }`.

`scripts/prerender.mjs` writes `dist/prerender/<route>/index.html` (`/` → `dist/prerender/index.html`). It fills in the `<!--app-head-->`, `<!--app-html-->` and `<!--app-state-->` placeholders of `dist/index.html`.

When `window.__UC_STATE__` is present, the client hydrates with `hydrateRoot`. In nginx, serve the prerendered file first: `try_files /prerender$uri/index.html $uri /index.html;`.

Head tags follow §5.5:

- Titles: `WorkSuite Desktop Download | Windows, macOS & Linux` and `WorkSuite Desktop 2.7.0 Release Notes`.
- Meta description, canonical URL, OpenGraph and Twitter tags.
- `noindex` for releases outside the Stable channel.
- JSON-LD `SoftwareApplication` with `softwareVersion`, `operatingSystem`, `downloadUrl`, `datePublished` and `fileSize`.
- RSS and Atom `<link rel="alternate">`.

Download buttons are plain `<a href="/download/…">` links, so they work without JavaScript.

## Layout (§3.4)

```
src/
  app/          routes (AppRoutes), providers, query client
  core/         config, formatting, platform detection, SEO/head, packages, semver, permissions
  components/   ui primitives (Modal, Tabs, Toast, fields, tables, bars), status badges, MfaPrompt,
                TypeToConfirm, ReleaseChecklist, OtpInput, public page blocks, admin widgets
  layouts/      PublicLayout, AdminLayout (+ adminNav per §7.1)
  pages/        public/, portal/, admin/, auth/
  modules/      releases/ (release screen, tabs, upload dialog, publish/withdraw), applications/ (wizard,
                app detail), server/ (campaign panel), bugtrace/
  services/     api.ts (fetch + CSRF + MFA retry), typed endpoint modules, upload.ts (tus), webauthn.ts, mock/
  hooks/  types/  test/
scripts/prerender.mjs
```

## Assumptions about the contract

These are the points where `docs/api-contract.md` was ambiguous. Each one describes what this frontend sends or expects, so the backend can match it.

1. **CSRF.** The token is taken from any JSON response with a top-level string `csrf` field: `/auth/login`, `/auth/mfa`, `/auth/me` and `/auth/passkey/verify`. If none has been seen yet, the readable `uc_csrf` cookie is used. `X-CSRF-Token` is sent on every non-GET request, including the tus `POST`/`PATCH`/`DELETE`, but not on `HEAD`.
2. **Step-up with a passkey.** `/auth/passkey/options` and `/auth/passkey/verify` are also called when the user is already signed in. A successful verify must refresh `step_up_valid_until`. The UI then retries the original request **without** `X-MFA-Token`.
3. **MFA errors.** A mutation that returns `401 MFA_INVALID` or `MFA_REQUIRED` while it carried `X-MFA-Token` is prompted again, up to 3 times. The publish and withdraw modals send the code they collected directly (`skipMfaPrompt`) and handle the errors inline.
4. **WebAuthn options.** The request and creation options may be bare or wrapped in `{publicKey: …}`. `challenge`, `user.id`, `allowCredentials[].id` and `excludeCredentials[].id` are base64url strings. Passkey verify also sends `userHandle` (base64url or null). Passkey register also sends the credential `id`.
5. **Approval** (not typed in the contract). The UI reads `{kind: 'QA'|'RELEASE_MANAGER', decision: 'PASS'|'FAIL'|'APPROVED', platform|null, user: string, comment|null, created_at}`.
6. **Release groups in the nav.**
   - Draft = `DRAFT,UPLOADING,READY_FOR_TEST`
   - Testing = `TESTING,APPROVED`
   - Published = `PUBLISHED,SUPERSEDED`
   - Paused and Withdrawn map to their own status.

   These are sent as `?status=A,B`. The UI reads `counts` from `GET /admin/releases` for **all** statuses, regardless of the `status` filter.
7. **Download links.** The home CTA and the app page use the latest-stable resolver `/download/{slug}/{platform}/{arch}/{type}`, built from package fields. Release pages use each package's `download_url`, which is version-specific.
8. **Publish rollout.** When `publish-summary.rollout` is missing, the UI shows "staged 25 → 50 → 100".
9. **Server edition packages.**
   - The package slot is `platform: 'server', architecture: 'any', package_type: 'zip', edition`.
   - `metadata.package_json.modules` drives the module list on the edition cards.
   - `metadata.validation[].status` is one of `pass|fail|pending` (anything else shows as pending).
   - A card reads **VERIFYING** while the package status is `PROCESSING`.
10. **Pipeline.** `GET …/packages/{pkg}` is polled every 2 s while the last revision is `UPLOADING` or `PROCESSING`. `revisions[last]` is taken as the revision being processed. `pipeline[].detail` is shown verbatim, right-aligned.
11. **Upload recovery.** The tus fingerprint is `uc-tus|release|platform|arch|type|edition|name|size|lastModified`. The `{packageId, uploadId, tusEndpoint, chunkSize}` for each fingerprint is kept in `localStorage` (`uc.uploads.pending`). When the same file is picked again, the upload skips `POST …/packages` and continues from the server offset (tus `HEAD`). `Upload-Metadata` contains only `upload_id`.
12. **Changelog in Markdown mode.** The UI sends `{summary, highlights, markdown}` with no `sections`, so the server parses the headings. In Categories mode it sends both `sections` and the generated `markdown`.
13. **Settings page.** `GET /admin/settings` is rendered generically:
    - booleans ending in `_set` are shown read-only as Set / Not set;
    - other booleans become checkboxes;
    - numbers, strings and arrays become inputs.
14. **Bug Trace.**
    - `status=UNRESOLVED` means NEW, TRIAGED, IN_PROGRESS and REGRESSED.
    - The issues Export button builds a CSV in the browser, because there is no export endpoint yet.
    - `issue.tags` may be a map or a list of `{key, value}`.
    - Distribution counts are displayed as percentages.
    - `PATCH /reports/{ref}` with `issue_id` links the report and sets its status to LINKED.
    - Alerts use `{id, name, condition, threshold, window, channels[], enabled}`.
15. **Subscriptions.** `GET /portal/subscriptions` may return `{data}` or `{items}`. `PUT` sends `{items}`.
16. **Health page permission.** Any of `storage.manage`, `audit.view` or `security.manage` shows Health, because the contract gives it no permission of its own.
17. **Managed Fleet.** Fleet actions are offered for SERVER releases in APPROVED, PUBLISHED or PAUSED; Start requires PUBLISHED.

## Accessibility (WCAG 2.2 AA)

- **Semantics:** labelled form fields with `aria-invalid` and `aria-describedby`; a skip link; `role="dialog"` + `aria-modal` with a focus trap, Esc to close and focus restore.
- **Keyboard:** ARIA tablists with roving arrow-key focus; visible focus rings.
- **Assistive text:** progress bars carry `aria-valuenow`; checklist states have screen-reader text; charts carry text alternatives.
- **Colour and motion:** colour contrast of at least 4.5:1 on text tokens; reduced-motion is respected.

## Tests

The Vitest suites under `src/test/` cover:

- platform detection: UA fallback, client hints, never blocking;
- the API client: CSRF, MFA prompt/retry, cancel, passkey retry, error class;
- `ReleaseChecklist`, `TypeToConfirm` and `OtpInput`;
- semver, format, SEO and upload fingerprint helpers;
- mock API rules: MFA-gated publish, withdraw type-to-confirm, CSRF, `allowed_actions`.
