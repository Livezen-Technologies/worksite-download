/**
 * Tiny, safe Markdown → HTML renderer used ONLY by the mock API (the real backend renders and sanitises
 * server-side). Escapes all HTML first, then supports headings, lists, bold/italic, inline code, links.
 */
function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function inline(s: string): string {
  return esc(s)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|\W)\*([^*]+)\*(?=\W|$)/g, '$1<em>$2</em>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/g, '<a href="$2" rel="nofollow noopener">$1</a>');
}

export function renderMarkdown(md: string): string {
  const out: string[] = [];
  let list: 'ul' | 'ol' | null = null;
  let para: string[] = [];
  const flushPara = () => {
    if (para.length) out.push(`<p>${inline(para.join(' '))}</p>`);
    para = [];
  };
  const closeList = () => {
    if (list) out.push(`</${list}>`);
    list = null;
  };
  for (const raw of md.replace(/\r\n/g, '\n').split('\n')) {
    const line = raw.trimEnd();
    const h = /^(#{1,4})\s+(.*)$/.exec(line);
    const ul = /^\s*[-*]\s+(.*)$/.exec(line);
    const ol = /^\s*\d+[.)]\s+(.*)$/.exec(line);
    if (!line.trim()) {
      flushPara();
      closeList();
    } else if (h) {
      flushPara();
      closeList();
      const level = Math.min(h[1].length + 1, 5);
      out.push(`<h${level}>${inline(h[2])}</h${level}>`);
    } else if (ul || ol) {
      flushPara();
      const kind = ul ? 'ul' : 'ol';
      if (list !== kind) {
        closeList();
        out.push(`<${kind}>`);
        list = kind;
      }
      out.push(`<li>${inline((ul ?? ol)![1])}</li>`);
    } else {
      closeList();
      para.push(line.trim());
    }
  }
  flushPara();
  closeList();
  return out.join('\n');
}

const SECTION_HEADINGS: Record<string, string> = {
  'new features': 'new_features',
  features: 'new_features',
  improvements: 'improvements',
  'bug fixes': 'bug_fixes',
  fixes: 'bug_fixes',
  performance: 'performance',
  security: 'security',
  'known issues': 'known_issues',
  deprecated: 'deprecated',
  removed: 'removed',
};

/** Parse "## New Features" style markdown into sections (mirrors the server behaviour in the contract). */
export function parseSections(md: string): Record<string, string[]> {
  const sections: Record<string, string[]> = {};
  let current: string | null = null;
  for (const line of md.split('\n')) {
    const h = /^#{1,4}\s+(.*)$/.exec(line.trim());
    if (h) {
      current = SECTION_HEADINGS[h[1].trim().toLowerCase()] ?? null;
      continue;
    }
    const li = /^\s*[-*]\s+(.*)$/.exec(line);
    if (current && li) (sections[current] ??= []).push(li[1].trim());
  }
  return sections;
}
