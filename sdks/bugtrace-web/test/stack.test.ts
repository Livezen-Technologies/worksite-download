import { describe, expect, it } from 'vitest';
import { fingerprint, isChunkLoadError, parseStack, toException } from '../src/stack';

describe('stack parsing', () => {
  it('parses V8 stacks outermost → innermost', () => {
    const stack = `TypeError: Cannot read properties of undefined (reading 'folderId')
    at r (https://mail.tenant.lk/assets/mail-8c1.js:1:40211)
    at e (https://mail.tenant.lk/assets/mail-8c1.js:1:40101)
    at https://mail.tenant.lk/node_modules/react-dom.js:3:999`;
    const frames = parseStack(stack, 'https://mail.tenant.lk');
    expect(frames).toHaveLength(3);
    expect(frames[2]).toMatchObject({ function: 'r', file: 'https://mail.tenant.lk/assets/mail-8c1.js', line: 1, col: 40211, in_app: true });
    expect(frames[0].in_app).toBe(false);
  });

  it('parses Firefox/Safari stacks', () => {
    const frames = parseStack('r@https://x.lk/assets/app-1a2.js:1:500\n@https://x.lk/assets/app-1a2.js:1:20');
    expect(frames.map((f) => f.col)).toEqual([20, 500]);
  });

  it('recognises stale chunk errors', () => {
    expect(isChunkLoadError(new TypeError('Failed to fetch dynamically imported module: https://x/a.js'))).toBe(true);
    expect(isChunkLoadError(new Error('Loading chunk 12 failed.'))).toBe(true);
    expect(isChunkLoadError(new Error('boom'))).toBe(false);
  });

  it('fingerprints ignore bundle hashes and line numbers', () => {
    const a = toException(Object.assign(new Error('x'), { stack: 'Error: x\n    at f (https://x/assets/mail-8c1.js:1:10)' }), true, 'generic');
    const b = toException(Object.assign(new Error('y'), { stack: 'Error: y\n    at f (https://x/assets/mail-9d2.js:1:99)' }), true, 'generic');
    expect(fingerprint(a)).toBe(fingerprint(b));
  });
});
