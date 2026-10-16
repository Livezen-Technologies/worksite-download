// scripts/publish-linux-repos.sh and the nginx locations that serve its output (worksuite-desktop#351).
//
//   node --test scripts/tests/
//
// A throwaway signing key is generated in a temporary GNUPGHOME for each run and deleted afterwards; no key is
// ever read from or written to the repository. reprepro and createrepo_c are replaced by the small fakes in
// scripts/tests/fakes (rpm answers from the file name; same list output, same SignWith hook call), so the signing, idempotency and refusal logic run
// everywhere. The one test that needs the real tools runs only where both are installed (CI installs them).
import { execFileSync, spawnSync } from 'node:child_process';
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, before, describe, it } from 'node:test';
import assert from 'node:assert/strict';

const here = dirname(fileURLToPath(import.meta.url));
const SCRIPT = join(here, '..', 'publish-linux-repos.sh');
const FAKES = join(here, 'fakes');
const SITE = readFileSync(join(here, '..', '..', 'nginx', 'site.conf'), 'utf8');
const URL = 'https://download.worksuitecloud.com';
const HEADER = '# Written by WorkSuite (worksuite-desktop#351). Changing the update channel in WorkSuite rewrites this file.';

const has = (bin) => spawnSync('sh', ['-c', `command -v ${bin}`]).status === 0;
const scratchDirs = [];
const scratch = (name = 'ws-linux-repo-') => {
  const d = mkdtempSync(join(tmpdir(), name));
  scratchDirs.push(d);
  return d;
};
after(() => scratchDirs.forEach((d) => rmSync(d, { recursive: true, force: true })));

/** A throwaway key with a passphrase: { armoredPrivate, fpr, publicFile }. */
function throwawayKey(passphrase) {
  const home = scratch('ws-gnupg-');
  chmodSync(home, 0o700);
  const env = { ...process.env, GNUPGHOME: home };
  execFileSync('gpg', ['--batch', '--pinentry-mode', 'loopback', '--passphrase', passphrase, '--quick-generate-key', 'WorkSuite Test Repository <repo-test@example.invalid>', 'ed25519', 'sign', 'never'], { env, stdio: 'ignore' });
  const fpr = execFileSync('gpg', ['--batch', '--with-colons', '--list-secret-keys'], { env, encoding: 'utf8' }).split('\n').find((l) => l.startsWith('fpr:')).split(':')[9];
  const armoredPrivate = execFileSync('gpg', ['--batch', '--pinentry-mode', 'loopback', '--passphrase', passphrase, '--armor', '--export-secret-keys', fpr], { env, encoding: 'utf8' });
  spawnSync('gpgconf', ['--kill', 'gpg-agent'], { env }); // the agent this key's keyring started
  rmSync(home, { recursive: true, force: true });
  return { armoredPrivate, fpr };
}

/** A minimal worksuite .deb, built with dpkg-deb. */
function makeDeb(dir, version, arch = 'amd64', pkg = 'worksuite') {
  const tree = join(dir, `${pkg}-${version}-${arch}`);
  mkdirSync(join(tree, 'DEBIAN'), { recursive: true });
  mkdirSync(join(tree, 'opt', 'WorkSuite'), { recursive: true });
  writeFileSync(join(tree, 'opt', 'WorkSuite', 'version'), `${version}\n`);
  writeFileSync(join(tree, 'DEBIAN', 'control'), `Package: ${pkg}\nVersion: ${version}\nSection: editors\nPriority: optional\nArchitecture: ${arch}\nMaintainer: Livezen Technologies <noreply@worksuite.lk>\nDescription: test package\n`);
  const out = join(dir, `WorkSuite-${version}-${arch}.deb`.replace('WorkSuite', pkg === 'worksuite' ? 'WorkSuite' : pkg));
  execFileSync('dpkg-deb', ['--build', '--root-owner-group', tree, out], { stdio: 'ignore' });
  return out;
}

function run(args, { env = {}, fakes = true } = {}) {
  const r = spawnSync('bash', [SCRIPT, ...args], {
    encoding: 'utf8',
    env: { PATH: `${fakes ? `${FAKES}:` : ''}${process.env.PATH}`, HOME: process.env.HOME ?? '/tmp', ...env },
  });
  return { status: r.status, out: r.stdout, err: r.stderr };
}

/** Every file under a directory, relative, with its bytes. */
function snapshot(dir) {
  const out = {};
  const walk = (d) => {
    for (const n of readdirSync(d)) {
      const p = join(d, n);
      if (statSync(p).isDirectory()) walk(p);
      else out[p.slice(dir.length + 1)] = readFileSync(p).toString('base64');
    }
  };
  walk(dir);
  return out;
}

const gpgOk = has('gpg') && has('dpkg-deb') && has('flock');

describe('publish-linux-repos.sh: usage', () => {
  it('refuses a missing or unknown channel, and no files', () => {
    assert.equal(run(['--root', scratch(), 'x.deb']).status, 2);
    assert.equal(run(['--channel', 'nightly', '--root', scratch(), 'x.deb']).status, 2);
    assert.equal(run(['--channel', 'stable', '--root', scratch()]).status, 2);
  });
});

describe('publish-linux-repos.sh', { skip: gpgOk ? false : 'needs gpg, dpkg-deb and flock' }, () => {
  const pass = 'correct horse battery staple';
  let key;
  let pkgs;
  before(() => {
    key = throwawayKey(pass);
    pkgs = scratch('ws-pkgs-');
  });
  const env = (log) => ({ WORKSUITE_REPO_GPG_PRIVATE_KEY: key.armoredPrivate, WORKSUITE_REPO_GPG_PASSPHRASE: pass, FAKE_LOG: log });

  it('refuses a package that is not worksuite', () => {
    const other = makeDeb(pkgs, '1.0.0', 'amd64', 'something-else');
    const r = run(['--channel', 'stable', '--root', scratch(), '--dry-run', other]);
    assert.equal(r.status, 1);
    assert.match(r.err, /not worksuite/);
  });

  it('a dry run needs no key, says what it would do and changes nothing', () => {
    const root = scratch();
    const deb = makeDeb(pkgs, '0.2.249');
    const rpm = join(pkgs, 'WorkSuite-0.2.249-x86_64.rpm');
    writeFileSync(rpm, 'not really an rpm, but named like one');
    const r = run(['--channel', 'beta', '--root', root, '--dry-run', deb, rpm]);
    assert.equal(r.status, 0, r.err);
    assert.match(r.out, /apt beta\/main amd64: worksuite 0\.2\.249 — would add/);
    assert.match(r.out, /rpm beta x86_64: WorkSuite-0\.2\.249-x86_64\.rpm — would add/);
    assert.match(r.out, /dry run: nothing changed/);
    assert.deepEqual(readdirSync(root), []);
  });

  it('adds a .deb and an .rpm, signs Release/InRelease and repomd.xml, and publishes the public key only', () => {
    const root = scratch();
    const log = join(scratch(), 'calls.log');
    const deb = makeDeb(pkgs, '0.2.250');
    const rpm = join(pkgs, 'WorkSuite-0.2.250-x86_64.rpm');
    writeFileSync(rpm, 'rpm bytes 0.2.250');
    const r = run(['--channel', 'stable', '--root', root, deb, rpm], { env: env(log) });
    assert.equal(r.status, 0, r.err);

    const apt = join(root, 'public', 'apt');
    // Every suite exists and is signed; apt's own verifier accepts InRelease and Release.gpg with the published key.
    for (const s of ['stable', 'beta', 'dev']) {
      assert.ok(existsSync(join(apt, 'dists', s, 'InRelease')), `${s}/InRelease`);
      execFileSync('gpgv', ['--keyring', join(apt, 'worksuite.gpg'), join(apt, 'dists', s, 'InRelease')], { stdio: 'ignore' });
      execFileSync('gpgv', ['--keyring', join(apt, 'worksuite.gpg'), join(apt, 'dists', s, 'Release.gpg'), join(apt, 'dists', s, 'Release')], { stdio: 'ignore' });
    }
    assert.match(readFileSync(join(apt, 'dists', 'stable', 'Release'), 'utf8'), /stable\|main\|amd64: worksuite 0\.2\.250/);
    const conf = readFileSync(join(root, 'state', 'reprepro', 'conf', 'distributions'), 'utf8');
    for (const s of ['stable', 'beta', 'dev']) assert.match(conf, new RegExp(`Codename: ${s}\\nArchitectures: amd64 arm64\\nComponents: main\\n`));
    assert.match(conf, /SignWith: ! .*linux-repo-sign\.sh/);

    // The rpm repository: the package, its metadata, and a detached signature over repomd.xml.
    const repodata = join(root, 'public', 'rpm', 'stable', 'repodata');
    assert.ok(existsSync(join(root, 'public', 'rpm', 'stable', 'packages', 'WorkSuite-0.2.250-x86_64.rpm')));
    execFileSync('gpgv', ['--keyring', join(apt, 'worksuite.gpg'), join(repodata, 'repomd.xml.asc'), join(repodata, 'repomd.xml')], { stdio: 'ignore' });
    assert.match(readFileSync(join(root, 'public', 'rpm', 'worksuite.gpg'), 'utf8'), /BEGIN PGP PUBLIC KEY BLOCK/);

    // Ready-made source files, the same text the desktop writes.
    assert.equal(readFileSync(join(apt, 'worksuite-beta.sources'), 'utf8'), `${HEADER}\nTypes: deb\nURIs: ${URL}/apt\nSuites: beta\nComponents: main\nSigned-By: /usr/share/keyrings/worksuite.gpg\n`);
    assert.match(readFileSync(join(root, 'public', 'rpm', 'worksuite-dev.repo'), 'utf8'), /baseurl=https:\/\/download\.worksuitecloud\.com\/rpm\/dev\nenabled=1\ngpgcheck=0\nrepo_gpgcheck=1\n/);

    // Nothing private anywhere in the tree, nor the passphrase.
    for (const [name, b64] of Object.entries(snapshot(root))) {
      const text = Buffer.from(b64, 'base64').toString('latin1');
      assert.ok(!text.includes('PRIVATE KEY'), `${name} holds a private key`);
      assert.ok(!text.includes(pass), `${name} holds the passphrase`);
    }
  });

  it('is idempotent: the same files again change nothing and sign nothing', () => {
    const root = scratch();
    const log = join(scratch(), 'calls.log');
    const deb = makeDeb(pkgs, '0.2.251');
    const rpm = join(pkgs, 'WorkSuite-0.2.251-x86_64.rpm');
    writeFileSync(rpm, 'rpm bytes 0.2.251');
    assert.equal(run(['--channel', 'beta', '--root', root, deb, rpm], { env: env(log) }).status, 0);
    const first = snapshot(join(root, 'public'));
    const calls = readFileSync(log, 'utf8');
    const again = run(['--channel', 'beta', '--root', root, deb, rpm], { env: env(log) });
    assert.equal(again.status, 0, again.err);
    assert.match(again.err, /worksuite 0\.2\.251 is already there/);
    assert.deepEqual(snapshot(join(root, 'public')), first);
    const added = readFileSync(log, 'utf8').slice(calls.length);
    assert.doesNotMatch(added, /includedeb|createrepo_c|export/);
    // A dry run now says so too.
    assert.match(run(['--channel', 'beta', '--root', root, '--dry-run', rpm]).out, /already there/);
  });

  it('keeps the newest .rpm files per architecture, and refuses other bytes under a published name', () => {
    const root = scratch();
    for (const v of ['0.2.240', '0.2.241', '0.2.242', '0.2.243']) {
      const f = join(pkgs, `WorkSuite-${v}-x86_64.rpm`);
      writeFileSync(f, `rpm ${v}`);
      assert.equal(run(['--channel', 'dev', '--root', root, '--keep', '2', f], { env: env('/dev/null') }).status, 0);
    }
    assert.deepEqual(readdirSync(join(root, 'public', 'rpm', 'dev', 'packages')).sort(), ['WorkSuite-0.2.242-x86_64.rpm', 'WorkSuite-0.2.243-x86_64.rpm']);
    const changed = join(scratch(), 'WorkSuite-0.2.243-x86_64.rpm');
    writeFileSync(changed, 'different bytes');
    const r = run(['--channel', 'dev', '--root', root, changed], { env: env('/dev/null') });
    assert.equal(r.status, 1);
    assert.match(r.err, /already published with different contents/);
  });

  it('stops before changing anything without a key, or with the wrong passphrase', () => {
    const deb = makeDeb(pkgs, '0.2.252');
    const none = scratch();
    const r1 = run(['--channel', 'stable', '--root', none, deb], { env: { GNUPGHOME: scratch() } });
    assert.equal(r1.status, 1);
    assert.match(r1.err, /no signing key/);
    const wrong = scratch();
    const r2 = run(['--channel', 'stable', '--root', wrong, deb], { env: { WORKSUITE_REPO_GPG_PRIVATE_KEY: key.armoredPrivate, WORKSUITE_REPO_GPG_PASSPHRASE: 'wrong' } });
    assert.equal(r2.status, 1);
    assert.match(r2.err, /cannot sign/);
    assert.ok(!existsSync(join(wrong, 'public', 'apt', 'dists')));
  });

  const real = has('reprepro') && has('createrepo_c');
  it('works with the real reprepro and createrepo_c', { skip: real ? false : 'reprepro and createrepo_c are not installed here (CI installs them)' }, () => {
    const root = scratch();
    const deb = makeDeb(pkgs, '0.2.260');
    const r = run(['--channel', 'stable', '--root', root, deb], { env: env('/dev/null'), fakes: false });
    assert.equal(r.status, 0, r.err);
    execFileSync('gpgv', ['--keyring', join(root, 'public', 'apt', 'worksuite.gpg'), join(root, 'public', 'apt', 'dists', 'stable', 'InRelease')], { stdio: 'ignore' });
    assert.match(readFileSync(join(root, 'public', 'apt', 'dists', 'stable', 'main', 'binary-amd64', 'Packages'), 'utf8'), /Package: worksuite\nVersion: 0\.2\.260/);
    const again = run(['--channel', 'stable', '--root', root, deb], { env: env('/dev/null'), fakes: false });
    assert.equal(again.status, 0, again.err);
    assert.match(again.err, /already there/);
  });
});

describe('nginx serves the repositories (nginx/site.conf)', () => {
  const block = (path) => {
    const start = SITE.indexOf(`location ^~ ${path} {`);
    assert.ok(start >= 0, `no location ^~ ${path}`);
    return SITE.slice(start, SITE.indexOf('\n    }\n', start));
  };

  it('serves /apt/ and /rpm/ from the public tree only, with no listings and no PHP', () => {
    for (const p of ['/apt/', '/rpm/']) {
      const b = block(p);
      assert.match(b, /root \/srv\/linux-repos\/public;/);
      assert.match(b, /autoindex off;/);
      assert.match(b, /try_files \$uri =404;/);
      assert.doesNotMatch(b, /fastcgi_pass/);
    }
    const directives = SITE.split('\n').filter((l) => !l.trim().startsWith('#')).join('\n');
    assert.doesNotMatch(directives, /linux-repos\/state/);
  });

  it('caches packages for long and indexes and signatures briefly, with the right types', () => {
    const apt = block('/apt/');
    assert.match(apt, /location ~ \^\/apt\/pool\/.*\.deb\$/);
    assert.match(apt, /application\/vnd\.debian\.binary-package/);
    assert.match(apt, /max-age=300/);
    assert.match(apt, /InRelease/);
    const rpm = block('/rpm/');
    assert.match(rpm, /application\/x-rpm/);
    assert.match(rpm, /location ~ \^\/rpm\/\[a-z\]\+\/repodata\/repomd/);
  });
});
