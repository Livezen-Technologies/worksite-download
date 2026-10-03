# @worksuite/bugtrace-react-native

Bug Trace for the **WorkSuite mobile app** (SRS §18.7, FR-BT-SDK-03).

| Captured | How |
|---|---|
| JS errors | `ErrorUtils.setGlobalHandler`. Fatal errors are crashes. The previous handler is always called. |
| Native crashes | The `WorkSuiteBugTrace` native module records iOS signals/exceptions, Android uncaught exceptions and NDK tombstones. They are reported on the next launch, then cleared. |
| ANR / app hangs > 5 s | Native watchdog (`anr`, `app_hang`), plus a JS-thread watchdog |
| Out-of-memory terminations | Session marker: the previous run of the same release ended in the foreground with no clean exit and no recorded crash |
| User reports | Shake-to-report (`onShake`), post-crash sheet (`onPreviousCrash`), `bt.sendReport()` |

```ts
import { ErrorUtils, NativeModules, AppState, DeviceEventEmitter, Platform } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { initMobile } from '@worksuite/bugtrace-react-native';

const bt = await initMobile({
  rn: { ErrorUtils, NativeModules, AppState, DeviceEventEmitter, Platform },
  storage: AsyncStorage,
  key: 'pk_mobile_prod_…', release: '1.4.0+140', dist: '140',
  tpt: () => session.tpt,
  onShake: () => navigation.navigate('ReportBug'),
  onPreviousCrash: (c) => showCrashSheet(c),
});
```

**Native module contract** (`NativeModules.WorkSuiteBugTrace`): `start({ hangThresholdMs })`,
`getPendingCrashes(): Promise<NativeCrash[]>`, `clearPendingCrashes(ids)`. It emits
`WorkSuiteBugTraceShake` on shake. The full shape is in `NativeCrash` in `src/index.ts`.

**Symbols.** CI uploads Hermes source maps, dSYMs and R8 mapping files with the symbols API
(`POST /api/v1/admin/bugtrace/symbols`) for `release` + `dist`.

**Privacy.** If the policy is `OFF` or the user opted out, nothing is sent and pending native
crashes are cleared. If the policy is unknown, they are kept for a later launch. Call
`markCleanExit()` on an orderly shutdown.
