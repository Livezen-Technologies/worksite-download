import type { BugTraceClient } from './client';
import type { ReportInput, ReportReceipt } from './types';

/**
 * Framework-free "Report a bug" dialog (B4, FR-BT-REP-01..05): title,
 * description, severity, category, optional steps, screenshot of the current
 * tab via getDisplayMedia (only with the user's permission, never automatic)
 * with in-dialog annotation — arrow, box, blur and redact —, technical details
 * with "What is sent?", optional diagnostic logs and contact consent.
 * Rendered in a shadow root so the host app's CSS cannot interfere.
 */

type Tool = 'arrow' | 'box' | 'blur' | 'redact';
interface Op { tool: Tool; x0: number; y0: number; x1: number; y1: number }

const SEVERITIES: [ReportInput['severity'], string][] = [['MINOR', 'Minor'], ['ANNOYING', 'Annoying'], ['BLOCKS_WORK', 'Blocks my work'], ['DATA_WRONG', 'Data looks wrong']];
const CATEGORIES: [ReportInput['category'], string][] = [['BUG', 'Bug'], ['PERFORMANCE', 'Performance'], ['DATA', 'Data'], ['UI', 'Layout / UI'], ['FEATURE_REQUEST', 'Feature request']];

const CSS = `:host{all:initial}*{box-sizing:border-box;font:13px/1.4 system-ui,-apple-system,"Segoe UI",sans-serif;color:#1b1f2e}
.o{position:fixed;inset:0;background:rgba(15,20,40,.45);display:flex;align-items:center;justify-content:center;z-index:2147483646}
.d{background:#fff;border-radius:10px;width:min(560px,94vw);max-height:92vh;overflow:auto;box-shadow:0 12px 40px rgba(0,0,0,.25);padding:18px 20px}
h2{font-size:16px;font-weight:600;margin:0}.s{color:#667;font-size:12px;margin:2px 0 12px}label{display:block;font-weight:600;font-size:12px;margin:10px 0 4px}
input[type=text],textarea,select{width:100%;border:1px solid #cfd3e0;border-radius:6px;padding:7px 9px;background:#fff}textarea{min-height:64px;resize:vertical}
.seg{display:flex;border:1px solid #cfd3e0;border-radius:6px;overflow:hidden}.seg button{flex:1;border:0;background:#fff;padding:7px 4px;cursor:pointer;font-size:12px}
.seg button[aria-pressed=true]{background:#1f2d8f;color:#fff;font-weight:600}.box{border:1px dashed #b8bfd8;border-radius:8px;padding:10px;margin-top:12px;display:flex;gap:10px;align-items:center;background:#f6f7fc}
.box img{width:72px;height:44px;object-fit:cover;border-radius:4px;border:1px solid #ccd}.grow{flex:1}.muted{color:#667;font-size:12px}
.chk{display:flex;gap:8px;align-items:flex-start;margin-top:10px;font-weight:400}.chk input{margin-top:2px}
.f{display:flex;align-items:center;gap:8px;margin-top:16px;border-top:1px solid #eceef5;padding-top:12px}.f a{color:#1f2d8f;cursor:pointer;text-decoration:underline;font-size:12px}
.btn{border:1px solid #cfd3e0;background:#fff;border-radius:6px;padding:7px 14px;cursor:pointer}.pri{background:#1f2d8f;border-color:#1f2d8f;color:#fff;font-weight:600}
.btn:disabled{opacity:.6;cursor:default}pre{background:#0f1428;color:#d6dcff;font:11px/1.4 ui-monospace,monospace;padding:10px;border-radius:6px;max-height:180px;overflow:auto;white-space:pre-wrap}
.ed canvas{width:100%;border-radius:6px;cursor:crosshair;touch-action:none;border:1px solid #ccd}.tools{display:flex;gap:6px;margin:8px 0;flex-wrap:wrap}
.tools button[aria-pressed=true]{background:#1f2d8f;color:#fff}.err{color:#b3261e;font-size:12px;margin-top:8px}.ok{padding:8px 0}.ok b{font-size:15px}`;

function el<K extends keyof HTMLElementTagNameMap>(tag: K, attrs: Record<string, string | boolean | ((e: Event) => void)> = {}, ...kids: (Node | string | null | undefined)[]): HTMLElementTagNameMap[K] {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (typeof v === 'function') n.addEventListener(k.replace(/^on/, ''), v);
    else if (v === true) n.setAttribute(k, '');
    else if (v !== false) n.setAttribute(k, v);
  }
  for (const k of kids) if (k != null) n.append(k);
  return n;
}

/** "h••••@example.lk" */
export function maskEmail(e: string): string {
  const [u, d] = e.split('@');
  return d ? `${u.slice(0, 1)}${'•'.repeat(Math.max(3, Math.min(6, u.length - 1)))}@${d}` : e;
}

/** Capture the current tab once, with the user's permission. */
export async function captureScreen(): Promise<HTMLCanvasElement | null> {
  const md = navigator.mediaDevices as MediaDevices & { getDisplayMedia?: (o: unknown) => Promise<MediaStream> };
  if (!md?.getDisplayMedia) return null;
  const stream = await md.getDisplayMedia({ video: { displaySurface: 'browser' }, audio: false, preferCurrentTab: true, selfBrowserSurface: 'include' });
  try {
    const v = el('video');
    v.muted = true;
    v.srcObject = stream;
    await v.play();
    await new Promise((r) => setTimeout(r, 120));
    const c = el('canvas');
    c.width = v.videoWidth;
    c.height = v.videoHeight;
    c.getContext('2d')!.drawImage(v, 0, 0);
    return c;
  } finally {
    stream.getTracks().forEach((t) => t.stop());
  }
}

/** Arrow / box / blur (pixelate) / redact (solid) annotations over a captured image. */
export class Annotator {
  ops: Op[] = [];
  tool: Tool = 'box';
  readonly view: HTMLCanvasElement;
  private draft: Op | null = null;

  constructor(private base: HTMLCanvasElement) {
    this.view = el('canvas');
    this.view.width = base.width;
    this.view.height = base.height;
    const pos = (e: PointerEvent) => {
      const r = this.view.getBoundingClientRect();
      return [((e.clientX - r.left) * this.view.width) / (r.width || 1), ((e.clientY - r.top) * this.view.height) / (r.height || 1)];
    };
    this.view.addEventListener('pointerdown', (e) => {
      const [x, y] = pos(e);
      this.draft = { tool: this.tool, x0: x, y0: y, x1: x, y1: y };
      this.view.setPointerCapture?.(e.pointerId);
    });
    this.view.addEventListener('pointermove', (e) => {
      if (!this.draft) return;
      [this.draft.x1, this.draft.y1] = pos(e);
      this.render();
    });
    this.view.addEventListener('pointerup', () => {
      if (this.draft && (Math.abs(this.draft.x1 - this.draft.x0) > 4 || Math.abs(this.draft.y1 - this.draft.y0) > 4)) this.ops.push(this.draft);
      this.draft = null;
      this.render();
    });
    this.render();
  }

  get redactedRegions(): number {
    return this.ops.filter((o) => o.tool === 'blur' || o.tool === 'redact').length;
  }

  undo(): void {
    this.ops.pop();
    this.render();
  }

  render(target = this.view): void {
    const g = target.getContext('2d');
    if (!g) return;
    g.drawImage(this.base, 0, 0);
    for (const o of this.draft ? [...this.ops, this.draft] : this.ops) Annotator.apply(g, this.base, o);
  }

  static apply(g: CanvasRenderingContext2D, base: HTMLCanvasElement, o: Op): void {
    const x = Math.min(o.x0, o.x1), y = Math.min(o.y0, o.y1), w = Math.abs(o.x1 - o.x0), h = Math.abs(o.y1 - o.y0);
    const lw = Math.max(3, base.width / 400);
    if (o.tool === 'redact') {
      g.fillStyle = '#111';
      g.fillRect(x, y, w, h);
    } else if (o.tool === 'blur') {
      const block = Math.max(12, Math.round(base.width / 120));
      const tmp = document.createElement('canvas');
      tmp.width = Math.max(1, Math.ceil(w / block));
      tmp.height = Math.max(1, Math.ceil(h / block));
      const t = tmp.getContext('2d');
      if (!t) return;
      t.drawImage(base, x, y, w, h, 0, 0, tmp.width, tmp.height);
      g.imageSmoothingEnabled = false;
      g.drawImage(tmp, 0, 0, tmp.width, tmp.height, x, y, w, h);
      g.imageSmoothingEnabled = true;
    } else {
      g.strokeStyle = '#f59e0b';
      g.fillStyle = '#f59e0b';
      g.lineWidth = lw;
      if (o.tool === 'box') g.strokeRect(x, y, w, h);
      else {
        const a = Math.atan2(o.y1 - o.y0, o.x1 - o.x0), head = lw * 5;
        g.beginPath();
        g.moveTo(o.x0, o.y0);
        g.lineTo(o.x1, o.y1);
        g.stroke();
        g.beginPath();
        g.moveTo(o.x1, o.y1);
        g.lineTo(o.x1 - head * Math.cos(a - 0.45), o.y1 - head * Math.sin(a - 0.45));
        g.lineTo(o.x1 - head * Math.cos(a + 0.45), o.y1 - head * Math.sin(a + 0.45));
        g.closePath();
        g.fill();
      }
    }
  }

  toBlob(): Promise<Blob | null> {
    const out = document.createElement('canvas');
    out.width = this.base.width;
    out.height = this.base.height;
    const g = out.getContext('2d');
    if (!g) return Promise.resolve(null);
    g.drawImage(this.base, 0, 0);
    for (const o of this.ops) Annotator.apply(g, this.base, o);
    return new Promise((r) => out.toBlob((b) => r(b), 'image/png'));
  }
}

let open: Promise<ReportReceipt | { queued: true } | null> | null = null;

export interface DialogOptions {
  title?: string;
  description?: string;
  category?: ReportInput['category'];
  /** Where to mount (default document.body). */
  container?: HTMLElement;
}

export function openReportDialog(client: BugTraceClient, o: DialogOptions = {}): Promise<ReportReceipt | { queued: true } | null> {
  if (open) return open;
  open = new Promise((resolve) => {
    const host = el('div', { 'data-worksuite-bugtrace': '' });
    const root = host.attachShadow({ mode: 'open' });
    (o.container ?? document.body).append(host);
    const prevFocus = document.activeElement as HTMLElement | null;
    const close = (v: ReportReceipt | { queued: true } | null) => {
      host.remove();
      document.removeEventListener('keydown', onKey, true);
      open = null;
      prevFocus?.focus?.();
      resolve(v);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        e.stopPropagation();
        close(null);
      }
    };
    document.addEventListener('keydown', onKey, true);

    const policy = client.policy;
    const box = el('div', { class: 'd', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'bt-h' });
    root.append(el('style', {}, CSS), el('div', { class: 'o', onclick: (e) => e.target === e.currentTarget && close(null) }, box));
    box.append(el('h2', { id: 'bt-h' }, 'Report a bug'), el('p', { class: 's' }, "Goes to the WorkSuite team. You'll get a reference number and updates."));

    if (policy && !policy.user_reports) {
      box.append(el('p', {}, 'Bug reports are turned off by your organisation. Please contact your administrator.'), el('div', { class: 'f' }, el('span', { class: 'grow' }), el('button', { class: 'btn', onclick: () => close(null) }, 'Close')));
      return;
    }

    const title = el('input', { type: 'text', id: 'bt-t', maxlength: '255', required: true, value: o.title ?? '' });
    const desc = el('textarea', { id: 'bt-d', placeholder: 'What happened, and what did you expect instead?' });
    desc.value = o.description ?? '';
    const steps = el('textarea', { id: 'bt-st', placeholder: 'One step per line (optional)', style: 'min-height:44px' });
    let severity: ReportInput['severity'] = 'ANNOYING';
    const seg = el('div', { class: 'seg', role: 'group', 'aria-label': 'How bad is it?' });
    for (const [v, label] of SEVERITIES) {
      const b = el('button', { type: 'button', 'aria-pressed': String(v === severity), onclick: () => {
        severity = v;
        seg.querySelectorAll('button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
      } }, label);
      seg.append(b);
    }
    const cat = el('select', { id: 'bt-c' }, ...CATEGORIES.map(([v, l]) => el('option', { value: v, selected: v === (o.category ?? 'BUG') }, l)));

    // Screenshot (user-initiated only).
    let annot: Annotator | null = null;
    const shotBox = el('div', { class: 'box' });
    const editor = el('div', { class: 'ed', hidden: true });
    const renderShot = () => {
      shotBox.replaceChildren();
      if (!annot) {
        shotBox.append(el('div', { class: 'grow' }, el('b', {}, 'Screenshot'), el('div', { class: 'muted' }, 'Capture this page, then draw on it or blur private details.')), el('button', { type: 'button', class: 'btn', onclick: shoot }, 'Add screenshot'));
        return;
      }
      const img = el('img', { alt: 'Screenshot preview', src: annot.view.toDataURL('image/png') });
      shotBox.append(img, el('div', { class: 'grow' }, el('b', {}, 'Screenshot attached'), el('div', { class: 'muted' }, `Draw on it or blur private details before sending.${annot.redactedRegions ? ` ${annot.redactedRegions} region(s) hidden.` : ''}`)),
        el('button', { type: 'button', class: 'btn', onclick: () => { editor.hidden = false; } }, 'Edit'), el('button', { type: 'button', class: 'btn', 'aria-label': 'Remove screenshot', onclick: () => { annot = null; editor.hidden = true; renderShot(); } }, '✕'));
    };
    const shoot = async () => {
      const overlay = root.querySelector('.o') as HTMLElement;
      overlay.style.visibility = 'hidden';
      try {
        const c = await captureScreen();
        if (c) {
          annot = new Annotator(c);
          const tools = el('div', { class: 'tools' });
          for (const [t, l] of [['arrow', 'Arrow'], ['box', 'Box'], ['blur', 'Blur'], ['redact', 'Redact']] as [Tool, string][]) {
            const b = el('button', { type: 'button', class: 'btn', 'aria-pressed': String(t === 'box'), onclick: () => {
              annot!.tool = t;
              tools.querySelectorAll('[aria-pressed]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
            } }, l);
            tools.append(b);
          }
          tools.append(el('button', { type: 'button', class: 'btn', onclick: () => annot!.undo() }, 'Undo'), el('span', { class: 'grow' }), el('button', { type: 'button', class: 'btn pri', onclick: () => { editor.hidden = true; renderShot(); } }, 'Done'));
          editor.replaceChildren(tools, annot.view);
          editor.hidden = false;
        }
      } catch {
        /* permission denied or cancelled */
      } finally {
        overlay.style.visibility = '';
        renderShot();
      }
    };
    renderShot();

    const tech = el('input', { type: 'checkbox', checked: true });
    const lastErr = client.lastIssueTitle ? ` and the last error (${client.lastIssueTitle.slice(0, 60)})` : '';
    const logs = el('input', { type: 'checkbox' });
    const consent = el('input', { type: 'checkbox' });
    const what = el('pre', { hidden: true });
    const err = el('div', { class: 'err', role: 'alert' });
    const send = el('button', { type: 'button', class: 'btn pri' }, 'Send report');

    box.append(...([
      el('label', { for: 'bt-t' }, 'Title'), title,
      el('label', { for: 'bt-d' }, 'What happened, and what did you expect?'), desc,
      el('label', {}, 'How bad is it?'), seg,
      el('label', { for: 'bt-c' }, 'Category'), cat,
      el('label', { for: 'bt-st' }, 'Steps to reproduce'), steps,
      policy?.screenshots === false || !(navigator.mediaDevices as { getDisplayMedia?: unknown } | undefined)?.getDisplayMedia ? null : shotBox,
      editor,
      el('label', { class: 'chk' }, tech, el('span', {}, el('b', {}, 'Include technical details'), ` — app version, browser, page${lastErr}. No messages, files or passwords.`)),
      client.opts.getLogs && policy?.logs !== false ? el('label', { class: 'chk' }, logs, 'Include diagnostic logs from the last 30 minutes (private data removed)') : null,
      client.opts.contactEmail ? el('label', { class: 'chk' }, consent, `The WorkSuite team may contact me at ${maskEmail(client.opts.contactEmail)}`) : null,
      what,
      err,
      el('div', { class: 'f' },
        el('a', { role: 'button', tabindex: '0', onclick: () => {
          what.hidden = !what.hidden;
          what.textContent = JSON.stringify(tech.checked ? client.technicalDetails() : { note: 'Technical details are not included.' }, null, 2);
        } }, 'What is sent?'),
        el('span', { class: 'grow' }),
        el('button', { type: 'button', class: 'btn', onclick: () => close(null) }, 'Cancel'),
        send),
    ] as (HTMLElement | null)[]).filter((n): n is HTMLElement => n !== null));

    send.addEventListener('click', async () => {
      if (!title.value.trim()) {
        err.textContent = 'Please add a short title.';
        title.focus();
        return;
      }
      send.disabled = true;
      err.textContent = '';
      try {
        const res = await client.sendReport({
          title: title.value.trim(),
          description: desc.value,
          steps: steps.value.split('\n').map((s) => s.trim()).filter(Boolean),
          severity,
          category: cat.value as ReportInput['category'],
          includeTechnical: tech.checked,
          screenshot: annot ? await annot.toBlob() : null,
          screenshotMeta: annot ? { annotated: annot.ops.length > 0, redacted_regions: annot.redactedRegions } : undefined,
          logs: logs.checked && client.opts.getLogs ? await client.opts.getLogs() : null,
          contactConsent: consent.checked,
        });
        box.replaceChildren(el('h2', {}, 'Thank you'), el('div', { class: 'ok' }, 'reference' in res ? el('span', {}, 'Your reference is ', el('b', {}, res.reference), '. You can follow it under Help › My bug reports.') : 'You are offline — the report is saved and will be sent automatically. You will get a reference number then.'),
          el('div', { class: 'f' }, el('span', { class: 'grow' }), el('button', { type: 'button', class: 'btn pri', onclick: () => close(res) }, 'Close')));
      } catch (e) {
        send.disabled = false;
        err.textContent = e instanceof Error ? e.message : 'Could not send the report.';
      }
    });
    title.focus();
  });
  return open;
}
