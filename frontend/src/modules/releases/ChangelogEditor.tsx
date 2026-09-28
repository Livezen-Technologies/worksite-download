import { useEffect, useMemo, useState } from 'react';
import { Plus, X } from 'lucide-react';
import { adminApi } from '@/services/admin';
import { ApiError } from '@/services/api';
import type { AdminReleaseDetail, ChangelogSections } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import { cx } from '@/core/format';
import { Alert, Button, Card, CardHeader, TextArea } from '@/components/ui';
import { Tabs } from '@/components/ui/Tabs';
import { SECTION_TITLES } from '@/components/public/blocks';
import { useReleaseMutation } from './hooks';

export const SUMMARY_MAX = 280;
export const HIGHLIGHTS_MAX = 5;

export function sectionsToMarkdown(s: ChangelogSections): string {
  return SECTION_TITLES.filter(([k]) => s[k]?.length)
    .map(([k, title]) => `## ${title}\n${s[k].map((i) => `- ${i}`).join('\n')}`)
    .join('\n\n');
}

const toLines = (s: string) => s.split('\n').map((l) => l.replace(/^\s*[-*]\s+/, '').trim()).filter(Boolean);

/** Changelog editor (§8.6): summary ≤ 280, highlights ≤ 5, categorised items or raw Markdown, live preview. */
export function ChangelogEditor({ release }: { release: AdminReleaseDetail }) {
  const { user } = useAuth();
  const editable = can(user, 'changelog.manage');
  const cl = release.changelog;
  const [mode, setMode] = useState<'categories' | 'markdown'>('categories');
  const [summary, setSummary] = useState(cl.summary ?? '');
  const [highlights, setHighlights] = useState<string[]>(cl.highlights.length ? cl.highlights : ['']);
  const [text, setText] = useState<Record<keyof ChangelogSections, string>>(() => Object.fromEntries(SECTION_TITLES.map(([k]) => [k, (cl.sections[k] ?? []).join('\n')])) as Record<keyof ChangelogSections, string>);
  const [markdown, setMarkdown] = useState(cl.markdown || sectionsToMarkdown(cl.sections));
  const [html, setHtml] = useState(cl.html);
  const [previewError, setPreviewError] = useState(false);

  const sections = useMemo(() => Object.fromEntries(SECTION_TITLES.map(([k]) => [k, toLines(text[k] ?? '')])) as unknown as ChangelogSections, [text]);
  const md = mode === 'markdown' ? markdown : sectionsToMarkdown(sections);

  // Live preview via POST /admin/content/preview (debounced).
  useEffect(() => {
    const t = setTimeout(() => {
      adminApi
        .previewMarkdown(md)
        .then((r) => (setHtml(r.html), setPreviewError(false)))
        .catch(() => setPreviewError(true));
    }, 400);
    return () => clearTimeout(t);
  }, [md]);

  const save = useReleaseMutation(
    release.id,
    () =>
      adminApi.saveChangelog(release.id, {
        locale: cl.locale || 'en',
        summary: summary.trim(),
        highlights: highlights.map((h) => h.trim()).filter(Boolean),
        ...(mode === 'markdown' ? { markdown, sections: undefined as unknown as ChangelogSections } : { sections, markdown: md }),
      }),
    'Changelog saved',
  );
  const errs = save.error instanceof ApiError ? save.error.fieldErrors : {};
  const hasCategory = mode === 'markdown' ? /^##\s+/m.test(markdown) : Object.values(sections).some((s) => s.length > 0);
  const valid = summary.trim().length > 0 && summary.length <= SUMMARY_MAX && highlights.filter((h) => h.trim()).length <= HIGHLIGHTS_MAX && hasCategory;

  return (
    <div className="grid items-start gap-4 xl:grid-cols-2">
      <Card>
        <CardHeader title="Changelog" sub={`locale ${cl.locale || 'en'} · v${cl.version}`} />
        <form
          className="space-y-4 px-4 pb-4"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate(undefined);
          }}
        >
          {!editable && <Alert>You can view but not edit changelogs (changelog.manage).</Alert>}
          <div>
            <TextArea label="Summary" rows={2} maxLength={SUMMARY_MAX + 50} value={summary} onChange={(e) => setSummary(e.target.value)} disabled={!editable} error={errs.summary ?? (summary.length > SUMMARY_MAX ? `Keep it to ${SUMMARY_MAX} characters` : null)} required />
            <p className={cx('mt-0.5 text-right text-[11px]', summary.length > SUMMARY_MAX ? 'text-bad' : 'text-subtle')} aria-live="polite">
              {summary.length}/{SUMMARY_MAX}
            </p>
          </div>
          <fieldset>
            <legend className="mb-1 text-[12px] font-semibold">Highlights (max {HIGHLIGHTS_MAX}, shown on the app page)</legend>
            <ul className="space-y-1.5">
              {highlights.map((h, i) => (
                <li key={i} className="flex gap-2">
                  <label className="sr-only" htmlFor={`hl-${i}`}>
                    Highlight {i + 1}
                  </label>
                  <input id={`hl-${i}`} value={h} disabled={!editable} onChange={(e) => setHighlights((x) => x.map((v, j) => (j === i ? e.target.value : v)))} className="h-8 flex-1 rounded-lg border border-line px-3 text-[13px] focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none" />
                  <Button size="sm" variant="ghost" aria-label={`Remove highlight ${i + 1}`} disabled={!editable} onClick={() => setHighlights((x) => (x.length > 1 ? x.filter((_, j) => j !== i) : ['']))}>
                    <X className="size-3.5" aria-hidden />
                  </Button>
                </li>
              ))}
            </ul>
            {highlights.length < HIGHLIGHTS_MAX && editable && (
              <Button size="sm" variant="ghost" className="mt-1" icon={<Plus className="size-3.5" aria-hidden />} onClick={() => setHighlights((x) => [...x, ''])}>
                Add highlight
              </Button>
            )}
            {errs.highlights && <p className="text-[11.5px] text-bad">{errs.highlights}</p>}
          </fieldset>
          <Tabs
            label="Editor mode"
            active={mode}
            onSelect={(id) => {
              if (id === 'markdown') setMarkdown(sectionsToMarkdown(sections));
              setMode(id as 'categories' | 'markdown');
            }}
            items={[
              { id: 'categories', label: 'Categories' },
              { id: 'markdown', label: 'Markdown' },
            ]}
          />
          {mode === 'categories' ? (
            <div className="grid gap-3 sm:grid-cols-2">
              {SECTION_TITLES.map(([k, title]) => (
                <TextArea key={k} label={title} rows={3} hint="One item per line" value={text[k]} disabled={!editable} onChange={(e) => setText((t) => ({ ...t, [k]: e.target.value }))} />
              ))}
            </div>
          ) : (
            <TextArea label="Markdown" mono rows={14} value={markdown} disabled={!editable} onChange={(e) => setMarkdown(e.target.value)} hint="Use “## New Features”, “## Improvements”, “## Bug Fixes”, “## Security”, “## Known Issues”… headings with “- item” lists. Raw HTML is not allowed." />
          )}
          {!hasCategory && <p className="text-[11.5px] text-warn">Add at least one category item — the checklist requires summary + one category.</p>}
          {editable && (
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!valid}>
              Save changelog
            </Button>
          )}
        </form>
      </Card>
      <Card>
        <CardHeader title="Preview" sub="Rendered and sanitised by the server" />
        <div className="px-4 pb-4">
          {summary && <p className="mb-2 text-[13px] text-muted">{summary}</p>}
          {highlights.some((h) => h.trim()) && (
            <div className="mb-3 rounded-lg bg-brand-50 px-3 py-2">
              <div className="label-caps mb-1">Highlights</div>
              <ul className="list-disc pl-5 text-[12.5px]">
                {highlights.filter((h) => h.trim()).map((h, i) => (
                  <li key={i}>{h}</li>
                ))}
              </ul>
            </div>
          )}
          {previewError && <Alert tone="warn">Preview unavailable.</Alert>}
          <div className="prose-uc" dangerouslySetInnerHTML={{ __html: html }} />
        </div>
      </Card>
    </div>
  );
}
