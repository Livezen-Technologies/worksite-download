import { describe, expect, it } from 'vitest';
import { compareSemver, isSemver } from '@/core/semver';
import { formatBytes, shortHash } from '@/core/format';
import { appTitle, releaseTitle, renderHeadHtml } from '@/core/seo';
import { uploadFingerprint } from '@/services/upload';

describe('semver', () => {
  it('validates and orders versions including pre-releases', () => {
    expect(isSemver('2.8.0-beta.2')).toBe(true);
    expect(isSemver('2.7')).toBe(false);
    const sorted = ['2.6.5', '2.8.0-beta.2', '2.7.0', '2.8.0', '2.8.0-beta.10'].sort(compareSemver);
    expect(sorted).toEqual(['2.6.5', '2.7.0', '2.8.0-beta.2', '2.8.0-beta.10', '2.8.0']);
  });
});

describe('format', () => {
  it('formats sizes like the designs', () => {
    expect(formatBytes(320_400_000)).toBe('320 MB');
    expect(formatBytes(2_800_000_000, 1)).toBe('2.8 GB');
    expect(shortHash('9f2c4e00000000a41e')).toBe('9f2c…a41e');
  });
});

describe('SEO (§5.5)', () => {
  it('uses the title patterns', () => {
    expect(appTitle({ name: 'WorkSuite Desktop', platforms: [{ platform: 'windows' }, { platform: 'macos' }, { platform: 'linux' }] as never })).toBe('WorkSuite Desktop Download | Windows, macOS & Linux');
    expect(releaseTitle('WorkSuite Desktop', '2.7.0')).toBe('WorkSuite Desktop 2.7.0 Release Notes');
  });
  it('renders canonical, OpenGraph, noindex and escaped JSON-LD', () => {
    const html = renderHeadHtml({ title: 'A & B', description: 'd', path: '/apps/x', noindex: true, jsonLd: { name: '</script>' } });
    expect(html).toContain('<title>A &amp; B</title>');
    expect(html).toContain('rel="canonical" href="https://download.worksuitecloud.com/apps/x"');
    expect(html).toContain('property="og:title"');
    expect(html).toContain('content="noindex"');
    expect(html).not.toContain('</script>"');
  });
});

describe('upload fingerprint', () => {
  it('is stable per slot + file so a reload resumes the same upload', () => {
    const slot = { releaseId: 'rel_1', platform: 'windows' as const, architecture: 'x64' as const, packageType: 'exe' as const };
    const f = { name: 'worksuite.exe', size: 10, lastModified: 1 };
    expect(uploadFingerprint(slot, f)).toBe(uploadFingerprint({ ...slot }, { ...f }));
    expect(uploadFingerprint(slot, f)).not.toBe(uploadFingerprint({ ...slot, packageType: 'msi' }, f));
  });
});
