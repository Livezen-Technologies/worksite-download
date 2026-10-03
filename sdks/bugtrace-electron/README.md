# @worksuite/bugtrace-electron

Bug Trace for the **WorkSuite Desktop main process** (SRS §18.7, FR-BT-SDK-02). Renderers
keep using [`@worksuite/bugtrace-web`](../bugtrace-web) for window errors, the ErrorBoundary
and the **Report a bug** dialog (Ctrl/Cmd+Shift+B).

| Captured | How |
|---|---|
| Main-process uncaught exceptions / unhandled rejections | `process.on(...)`. Crashes are flushed before the process exits. |
| Native crashes | Crashpad minidumps (`crashReporter.start({ uploadToServer: false })`). The SDK uploads them to `POST /api/v1/bugtrace/minidump` on the next start, after the policy check. |
| GPU / renderer / utility process crashes | `render-process-gone`, `child-process-gone` (`clean-exit` ignored) |
| Hangs > 5 s | window `unresponsive` events, plus a main event-loop watchdog |
| Updater failures | `captureUpdaterFailure('UPD-E04', err)` with the ENH-02 error code |

```ts
import * as electron from 'electron';
import { initMain } from '@worksuite/bugtrace-electron';

const bt = initMain({
  electron,
  key: 'pk_desktop_prod_…',
  release: `${app.getVersion()}+${BUILD}`,
  tpt: () => licenseService.tenantPolicyToken(),
  installationRef, edition,
});
updateAuditService.on('failure', (code, err) => bt.captureUpdaterFailure(code, err));
```

**Privacy (FR-BT-SDK-10).** If the effective policy is `OFF`, nothing leaves the device and
leftover minidumps are deleted unsent. The same applies when the user opted out
(`bt.setOptOut(true)`) and the tenant allows opting out. If the policy is unknown (offline,
no TPT), minidumps stay on disk until a later start.

The offline queue and the opt-out flag live in `userData/bugtrace/`. Run `npm test` to test and `npm run build` to build.
