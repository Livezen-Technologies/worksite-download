#!/usr/bin/env node
/**
 * Publish a build to the WorkSuite Update Center, as far as a pipeline may.
 *
 * Creates the release, writes its changelog, signs and posts the CI build
 * attestation, uploads every installer (tus, resumable), waits until each is
 * scanned and checked, and submits the release for testing. QA, release-manager
 * approval and publishing stay with people: production requires two different
 * staff members with MFA for STABLE, and a step-up for every publish.
 *
 * No dependencies; Node 20+.
 *
 *   UC_URL=https://download.worksuitecloud.com \
 *   UC_CI_TOKEN_FILE=~/.config/worksuite-release/ci-token \
 *   UC_CI_KEY_FILE=~/.config/worksuite-release/ci-attestation.key \
 *   node scripts/publish-release.mjs \
 *     --app desktop --app-id lk.worksuite.desktop --channel BETA \
 *     --version 0.2.114 --build 114 --commit abc1234 --pipeline "mac local" \
 *     --summary "WorkSuite Desktop 0.2.114" --notes notes.md \
 *     --file windows:x64:exe:release/WorkSuite-Setup-0.2.114-x64.exe \
 *     --file macos:arm64:dmg:release/WorkSuite-0.2.114-arm64.dmg
 *
 * The token is "<client_id>.<secret>" of an API client of kind CI with
 * release.view, release.create, release.upload, release.file.upload,
 * changelog.manage and release.edit. The key is the base64 libsodium Ed25519
 * secret key whose public half is pinned as a CI_ATTESTATION key (ws-ci-1).
 *
 * Sparkle (apps that update on macOS through Sparkle, such as WorkSuite Office):
 * put the archive's EdDSA signature, as Sparkle's sign_update prints it
 * (sparkle:edSignature), in <archive>.edsig beside it. It goes into the
 * attestation as files[].sparkle_ed_signature, where the app's appcast takes it
 * from. With UC_SPARKLE_PUBLIC_KEY (the app's SUPublicEDKey) it is checked
 * against the archive first, so a build signed with the wrong key stops here
 * rather than in every app that would refuse it.
 */
import { createHash, createPrivateKey, createPublicKey, sign, verify } from 'node:crypto';
import { createReadStream, existsSync, readFileSync, statSync } from 'node:fs';
import { basename } from 'node:path';
import { homedir } from 'node:os';

const args = parseArgs(process.argv.slice(2));
const URL_BASE = (process.env.UC_URL || 'https://download.worksuitecloud.com').replace(/\/$/, '');
const TOKEN = readSecret(process.env.UC_CI_TOKEN_FILE, 'UC_CI_TOKEN');
const KEY = readSecret(process.env.UC_CI_KEY_FILE, 'UC_CI_KEY');
const KEY_ID = process.env.UC_CI_KEY_ID || 'ws-ci-1';
const CHUNK = 8 * 1024 * 1024;

for (const k of ['app', 'app-id', 'version', 'build']) if (!args[k]) die(`--${k} is required`);
if (!args.file?.length) die('at least one --file platform:arch:type:path is required');

const files = args.file.map((spec) => {
  const [platform, architecture, packageType, ...rest] = spec.split(':');
  const path = rest.join(':').replace(/^~/, homedir());
  if (!platform || !architecture || !packageType || !path) die(`bad --file ${spec}`);
  const size = statSync(path).size;
  return { platform, architecture, package_type: packageType, path, name: basename(path), size };
});

const build = Number.parseInt(args.build, 10);
if (!(build > 0)) die('--build must be a positive integer');

log(`hashing ${files.length} file(s)…`);
for (const f of files) f.sha256 = await sha256(f.path);

// Sparkle signatures, from <archive>.edsig.
const SPARKLE_PUBLIC = process.env.UC_SPARKLE_PUBLIC_KEY?.trim();
for (const f of files) {
  if (!existsSync(`${f.path}.edsig`)) {
    if (f.platform === 'macos' && SPARKLE_PUBLIC) die(`${f.name}: no ${f.name}.edsig, and Sparkle refuses an update without one`);
    continue;
  }
  f.sparkle = readFileSync(`${f.path}.edsig`, 'utf8').trim();
  if (Buffer.from(f.sparkle, 'base64').length !== 64) die(`${f.name}.edsig is not a base64 Ed25519 signature`);
  if (SPARKLE_PUBLIC && !verifySparkle(readFileSync(f.path), f.sparkle, SPARKLE_PUBLIC)) die(`${f.name}: the Sparkle signature does not match UC_SPARKLE_PUBLIC_KEY`);
  log(`${f.name}: Sparkle signature${SPARKLE_PUBLIC ? ' checked' : ''}`);
}

// 1. Release (idempotent for the same app/channel/version/build).
const release = await api('POST', '/api/v1/admin/releases', {
  app_id: args.app, version: args.version, build, channel: args.channel || 'BETA',
  release_type: args.type || 'RECOMMENDED',
  ...(args['min-version'] ? { minimum_supported_version: args['min-version'] } : { minimum_no_change: true }),
  ...(args.notes ? { notes: readFileSync(args.notes, 'utf8').slice(0, 20000) } : {}),
}, [201, 200]);
log(`release ${release.id} (${release.status})`);

// 2. Changelog.
const markdown = args.notes ? readFileSync(args.notes, 'utf8') : `## Improvements\n- ${args.summary || `Build ${build}`}`;
await api('PUT', `/api/v1/admin/releases/${release.id}/changelog`, {
  summary: (args.summary || `${args['app-id']} ${args.version}`).slice(0, 280), highlights: [], markdown,
});

// 3. Attestation before upload, so processing matches every file against it.
const payload = {
  app_id: args['app-id'], version: args.version.replace(/^v/, ''), build,
  files: files.map((f) => ({ name: f.name, sha256: f.sha256, size: f.size, ...(f.sparkle ? { sparkle_ed_signature: f.sparkle } : {}) })),
  pipeline: args.pipeline || 'local', commit: args.commit || 'unknown',
};
const att = await api('POST', `/api/v1/admin/releases/${release.id}/attestation`, {
  payload, signature: signEd25519(canonicalJson(payload), KEY), key_id: KEY_ID,
});
if (!att.verified) die(`attestation not verified: ${att.error}`);
log('attestation verified');

// 4. Upload each installer.
const packages = [];
for (const f of files) {
  const reg = await api('POST', `/api/v1/admin/releases/${release.id}/packages`, {
    platform: f.platform, architecture: f.architecture, package_type: f.package_type,
    file_name: f.name, size: f.size, protocol: 'tus',
  }, [201]);
  await tusUpload(reg.upload.id, f);
  packages.push({ id: reg.package.id, name: f.name });
  log(`uploaded ${f.name} (${(f.size / 1048576).toFixed(1)} MB)`);
}

// 5. Wait for scan, hash, metadata, signature and attestation checks.
for (const p of packages) {
  for (let i = 0; ; i++) {
    const pkg = await api('GET', `/api/v1/admin/releases/${release.id}/packages/${p.id}`);
    if (pkg.status === 'READY') { log(`ready ${p.name}`); break; }
    if (pkg.status === 'REJECTED') die(`${p.name} rejected: ${pkg.reject_code || ''} ${JSON.stringify(pkg.active_revision?.pipeline ?? pkg.processing_revision?.pipeline ?? '')}`);
    if (i > 360) die(`${p.name} still processing after 30 minutes`);
    await new Promise((r) => setTimeout(r, 5000));
  }
}

// 6. Submit for testing (runs the checklist; approvals come from people).
if (args['no-submit']) { log('done (not submitted)'); process.exit(0); }
const submitted = await api('POST', `/api/v1/admin/releases/${release.id}/submit-test`, {});
log(`submitted for testing: ${submitted.status}. QA and release-manager approval, then publish, at ${URL_BASE}/admin/releases/${release.id}`);

// ---------------------------------------------------------------------------

async function api(method, path, body, ok = [200]) {
  const res = await fetch(URL_BASE + path, {
    method,
    headers: { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json = null;
  try { json = text ? JSON.parse(text) : null; } catch { /* not JSON */ }
  if (!ok.includes(res.status) && !(res.status >= 200 && res.status < 300 && ok.includes(200))) {
    die(`${method} ${path} → ${res.status} ${json?.error ? `${json.error.code}: ${json.error.message} ${JSON.stringify(json.error.details ?? '')}` : text.slice(0, 300)}`);
  }
  return json;
}

async function tusUpload(uploadId, f) {
  const create = await fetch(`${URL_BASE}/api/v1/uploads/tus`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${TOKEN}`, 'Tus-Resumable': '1.0.0', 'Upload-Length': String(f.size), 'Upload-Metadata': `upload_id ${Buffer.from(uploadId).toString('base64')}` },
  });
  if (create.status !== 201) die(`tus create ${f.name} → ${create.status} ${await create.text()}`);
  let offset = Number(create.headers.get('Upload-Offset') || 0);
  while (offset < f.size) {
    const chunk = await readRange(f.path, offset, Math.min(offset + CHUNK, f.size));
    const res = await fetch(`${URL_BASE}/api/v1/uploads/tus/${uploadId}`, {
      method: 'PATCH',
      headers: { Authorization: `Bearer ${TOKEN}`, 'Tus-Resumable': '1.0.0', 'Upload-Offset': String(offset), 'Content-Type': 'application/offset+octet-stream' },
      body: chunk,
    });
    if (res.status === 409) { // resume from the server's offset
      const head = await fetch(`${URL_BASE}/api/v1/uploads/tus/${uploadId}`, { method: 'HEAD', headers: { Authorization: `Bearer ${TOKEN}`, 'Tus-Resumable': '1.0.0' } });
      offset = Number(head.headers.get('Upload-Offset') || offset);
      continue;
    }
    if (res.status !== 204) die(`tus patch ${f.name} @${offset} → ${res.status} ${await res.text()}`);
    offset = Number(res.headers.get('Upload-Offset'));
  }
}

function readRange(path, start, end) {
  return new Promise((resolve, reject) => {
    const parts = [];
    createReadStream(path, { start, end: end - 1 }).on('data', (d) => parts.push(d)).on('end', () => resolve(Buffer.concat(parts))).on('error', reject);
  });
}

function sha256(path) {
  return new Promise((resolve, reject) => {
    const h = createHash('sha256');
    createReadStream(path).on('data', (d) => h.update(d)).on('end', () => resolve(h.digest('hex'))).on('error', reject);
  });
}

/** Same bytes as the server's CanonicalJson: keys sorted recursively, lists in order, compact. */
function canonicalJson(v) {
  if (Array.isArray(v)) return `[${v.map(canonicalJson).join(',')}]`;
  if (v && typeof v === 'object') {
    return `{${Object.keys(v).sort().map((k) => `${JSON.stringify(k)}:${canonicalJson(v[k])}`).join(',')}}`;
  }
  return JSON.stringify(v);
}

/** Ed25519 over the UTF-8 bytes, from a libsodium secret key (seed ‖ public), as base64url. */
function signEd25519(message, libsodiumSecretB64) {
  const sk = Buffer.from(libsodiumSecretB64.trim(), 'base64');
  if (sk.length !== 64) die('the CI key must be a 64-byte libsodium Ed25519 secret key (base64)');
  const key = createPrivateKey({ key: { kty: 'OKP', crv: 'Ed25519', d: b64url(sk.subarray(0, 32)), x: b64url(sk.subarray(32)) }, format: 'jwk' });
  return b64url(sign(null, Buffer.from(message, 'utf8'), key));
}

/** Sparkle's check: Ed25519 over the whole archive, against SUPublicEDKey (base64 of the raw 32-byte key). */
function verifySparkle(bytes, signatureB64, publicB64) {
  const raw = Buffer.from(publicB64, 'base64');
  if (raw.length !== 32) die('UC_SPARKLE_PUBLIC_KEY must be the base64 SUPublicEDKey (32 bytes)');
  const key = createPublicKey({ key: { kty: 'OKP', crv: 'Ed25519', x: b64url(raw) }, format: 'jwk' });
  return verify(null, bytes, key, Buffer.from(signatureB64, 'base64'));
}

function b64url(buf) { return Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }

function readSecret(file, envName) {
  if (file) return readFileSync(file.replace(/^~/, homedir()), 'utf8').trim();
  if (process.env[envName]) return process.env[envName].trim();
  die(`set ${envName}_FILE or ${envName}`);
}

function parseArgs(argv) {
  const out = { file: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) die(`unexpected ${a}`);
    const k = a.slice(2);
    if (k === 'no-submit') { out[k] = true; continue; }
    const v = argv[++i];
    if (k === 'file') out.file.push(v); else out[k] = v;
  }
  return out;
}

function log(m) { console.log(`[uc] ${m}`); }
function die(m) { console.error(`[uc] ${m}`); process.exit(1); }
