/**
 * The slice of the Electron main-process API the SDK uses. The host passes its
 * own `electron` module, so the SDK carries no Electron dependency and tests
 * can inject fakes.
 */
export interface Emitter {
  on(event: string, listener: (...args: any[]) => void): unknown;
  removeListener(event: string, listener: (...args: any[]) => void): unknown;
}

export interface ElectronApp extends Emitter {
  getPath(name: 'crashDumps' | 'userData'): string;
  getVersion(): string;
}

export interface ElectronCrashReporter {
  start(options: { submitURL: string; uploadToServer: boolean; compress?: boolean; productName?: string; ignoreSystemCrashHandler?: boolean; extra?: Record<string, string> }): void;
}

export interface ElectronLike {
  app: ElectronApp;
  crashReporter: ElectronCrashReporter;
}

/** `render-process-gone` / `child-process-gone` details. */
export interface GoneDetails {
  reason: 'clean-exit' | 'abnormal-exit' | 'killed' | 'crashed' | 'oom' | 'launch-failed' | 'integrity-failure' | string;
  exitCode?: number;
  type?: string;
  name?: string;
  serviceName?: string;
}
