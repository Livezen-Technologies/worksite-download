import type { ExceptionValue, Frame } from './types';

const CHROME = /^\s*at (?:(.+?) )?\(?((?:[a-z-]+:\/\/|\/|\w:\\)[^\s)]*?|<anonymous>)(?::(\d+))?(?::(\d+))?\)?\s*$/i;
const GECKO = /^\s*(.*?)(?:\((.*?)\))?(?:^|@)((?:[a-z-]+:\/\/|\/)[^\s]*?)(?::(\d+))?(?::(\d+))?\s*$/i;

/**
 * Parse Error.stack (V8, SpiderMonkey, JavaScriptCore) into frames ordered
 * outermost → innermost (crashing frame last), as the envelope expects.
 */
export function parseStack(stack: string | undefined, origin?: string): Frame[] {
  if (!stack) return [];
  const frames: Frame[] = [];
  for (const line of stack.split('\n').slice(0, 60)) {
    let m = CHROME.exec(line);
    let f: Frame | null = null;
    if (m && line.includes(' at ')) {
      f = { function: m[1] || undefined, file: m[2], line: m[3] ? +m[3] : undefined, col: m[4] ? +m[4] : undefined };
    } else if ((m = GECKO.exec(line))) {
      f = { function: m[1] || undefined, file: m[3], line: m[4] ? +m[4] : undefined, col: m[5] ? +m[5] : undefined };
    }
    if (!f || !f.file) continue;
    if (f.function) f.function = f.function.replace(/^(async |new |Object\.)/, '');
    f.in_app = !/node_modules|chrome-extension:|moz-extension:|<anonymous>|\/bugtrace-web\//.test(f.file) && (!origin || f.file.startsWith(origin) || !/^https?:/.test(f.file));
    frames.push(f);
  }
  return frames.reverse();
}

export function toException(err: unknown, handled: boolean, mechanism: string): ExceptionValue {
  const e = err instanceof Error ? err : null;
  const origin = typeof location !== 'undefined' ? location.origin : undefined;
  return {
    type: e ? e.name || 'Error' : typeof err === 'object' && err ? 'NonErrorObject' : 'Error',
    value: e ? String(e.message) : safeString(err),
    mechanism: { type: mechanism, handled },
    stacktrace: { frames: parseStack(e?.stack, origin) },
  };
}

function safeString(v: unknown): string {
  try {
    return typeof v === 'string' ? v : JSON.stringify(v)?.slice(0, 500) ?? String(v);
  } catch {
    return String(v);
  }
}

/** Stale-chunk errors after a deploy (the app reloads itself — §18.2). */
export function isChunkLoadError(err: unknown): boolean {
  const s = err instanceof Error ? `${err.name} ${err.message}` : String(err);
  return /ChunkLoadError|Loading (CSS )?chunk [\w-]+ failed|Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Unable to preload CSS/i.test(s);
}

/** Grouping key used for client-side de-duplication (type + top 5 frames, no line/hash). */
export function fingerprint(ex: ExceptionValue | undefined, message = ''): string {
  if (!ex) return 'msg:' + message.replace(/\d+/g, '0');
  const top = ex.stacktrace.frames.slice(-5).map((f) => `${(f.file || '').split(/[?#]/)[0].replace(/[-.][A-Za-z0-9_]*\d[A-Za-z0-9_]*(?=\.m?js$)/, '')}:${f.function || ''}`);
  return [ex.type, top.length ? top.join('|') : ex.value.replace(/\d+/g, '0')].join('#');
}
