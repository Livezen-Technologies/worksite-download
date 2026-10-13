# WorkSuite APT and DNF repositories

worksuite-desktop#351 (the follow-up to #345). WorkSuite Desktop's `.deb` and `.rpm` builds are also published as
package repositories, so a Linux computer gets WorkSuite updates with its system updates:

| | URL | Contents |
|---|---|---|
| APT | `https://download.worksuitecloud.com/apt` | suites `stable`, `beta`, `dev`; component `main`; `amd64`, `arm64`. `dists/<suite>/InRelease` and `Release.gpg` signed. |
| DNF / YUM | `https://download.worksuitecloud.com/rpm/<channel>` | `packages/*.rpm` and `repodata/` (`repomd.xml.asc` is the signature). |
| Key | `/apt/worksuite.gpg` (binary, for `Signed-By`), `/apt/worksuite.asc` and `/rpm/worksuite.gpg` (ASCII-armoured, for `rpm --import`) | the repository's public key only |
| Source files | `/apt/worksuite-<suite>.sources`, `/rpm/worksuite-<channel>.repo` | ready to drop into `/etc/apt/sources.list.d/` or `/etc/yum.repos.d/` |

> **Order matters.** A computer that lists a package source that does not answer gets errors from every
> `apt update`. So the desktop packages add the source **only** when they are built with the switch on
> (`WORKSUITE_LINUX_REPO=1`, see [Turning on the desktop switch](#6-turning-on-the-desktop-switch)), and that switch
> is flipped only after the repository below is live and verified.

## Pieces

| File | Role |
|---|---|
| `scripts/publish-linux-repos.sh` | Adds `.deb` files to the reprepro suite and `.rpm` files to the channel's directory (createrepo_c), signs `Release`/`InRelease` and `repomd.xml`, exports the public key and the source files. Idempotent; `--dry-run` changes nothing. |
| `scripts/linux-repo-sign.sh` | reprepro's `SignWith` hook, and the `repomd.xml` signer (gpg, passphrase from a 0600 file). |
| `docker/linux-repo.Dockerfile` | reprepro, createrepo_c, gpg, dpkg-deb, rpm and flock, with the script as entrypoint. |
| `nginx/site.conf` | `location ^~ /apt/` and `/rpm/`: static files from `/srv/linux-repos/public`, no listings, no PHP, no SPA fallback, MIME types for `.deb`/`.rpm`/keys, packages cached for a year (`immutable`, they never change under their name), indexes and signatures for 1–5 minutes. |
| `docker-compose.yml` | the `linux-repos` volume, read-only in `update-web`; the `linux-repo` service (profile `linux-repo`) to publish into it locally. |
| `.github/workflows/linux-repos.yml` | the release job: fetch the published packages from the Update Center, check their SHA-256, update the tree on the server, verify what clients see. |
| `scripts/tests/publish-linux-repos.test.mjs` | `node --test scripts/tests/*.test.mjs`: a throwaway key made at test time, a real `.deb` built with dpkg-deb, fakes for reprepro/createrepo_c (the real ones are used where installed — CI), signature checks with `gpgv`, idempotency, refusals, and the nginx locations. |

Tree on the server (`/srv/linux-repos`, owned by the deploy user, readable by nginx):

```
/srv/linux-repos/
  state/reprepro/conf/distributions   written by the script on every run (three suites, SignWith the hook)
  state/reprepro/db/                  reprepro's database — never served
  public/apt/dists/ pool/ worksuite.gpg worksuite.asc worksuite-<suite>.sources
  public/rpm/<channel>/packages/*.rpm  public/rpm/<channel>/repodata/  public/rpm/worksuite.gpg  worksuite-<channel>.repo
```

## 1. The signing key (offline)

One OpenPGP key signs both repositories. Its primary key never touches CI or the server; CI holds a **signing
subkey** only, which can be replaced without any change on clients (they trust the primary key).

On an offline machine (air-gapped laptop or a live USB), keeping the results on two encrypted USB sticks stored apart:

```bash
export GNUPGHOME=$(mktemp -d)                 # a fresh keyring on the offline machine
gpg --quick-generate-key 'WorkSuite Package Repository <packages@worksuite.lk>' ed25519 cert 5y
FPR=$(gpg --with-colons --list-keys packages@worksuite.lk | awk -F: '$1=="fpr"{print $10; exit}')
gpg --quick-add-key "$FPR" ed25519 sign 2y    # the subkey CI signs with
gpg --armor --export "$FPR"                > worksuite-repo-public.asc         # public: safe to publish
gpg --armor --export-secret-subkeys "$FPR" > ci-signing-subkey.asc             # for the CI secret
gpg --armor --export-secret-keys "$FPR"    > OFFLINE-primary-and-subkeys.asc   # the backup: never online
gpg --output revoke-primary.asc --gen-revoke "$FPR"                            # kept with the backup
```

`ci-signing-subkey.asc` holds the signing subkey with a stub for the primary (`gpg --list-secret-keys` shows
`sec#`). Give the key a passphrase when gpg asks (or `gpg --passwd "$FPR"` before exporting). ed25519 signatures are accepted by apt
(Ubuntu 20.04+, Debian 11+) and dnf (RHEL 9+, Fedora). `Signed-By` and `gpgkey` trust the **primary** key, so a new
subkey is accepted with no change on clients once the public key file on the server is refreshed.

Never commit any of these files, and never paste the private key anywhere but the GitHub secret.

### Rotation

* **Subkey (every two years, or at once if CI may have leaked it):** on the offline machine, import
  `OFFLINE-primary-and-subkeys.asc`, `gpg --quick-add-key "$FPR" ed25519 sign 2y`, revoke the old subkey if it
  leaked (`gpg --edit-key "$FPR"`, `key N`, `revkey`), export the public key and the new CI subkey as above, replace
  the `WORKSUITE_REPO_GPG_PRIVATE_KEY`/`_PASSPHRASE` secrets, and run the workflow once (any version already
  published): the script re-exports `worksuite.gpg`/`.asc` and re-signs. Clients pick up the new public key with
  their next WorkSuite package (it ships the key) or Diagnostics › Fix; the old subkey's signatures stay valid until
  it is revoked.
* **Primary key (compromise or expiry):** a new key means a new `Signed-By` keyring on every client. Publish the new
  public key next to the old one for one release cycle, ship a desktop build carrying the new key (its after-install
  rewrites `/usr/share/keyrings/worksuite.gpg`), and only then start signing with the new key.

## 2. Secrets

GitHub environment **linux-repositories** in this repository (add required reviewers if a person should approve
each publish):

| Secret | Value |
|---|---|
| `WORKSUITE_REPO_GPG_PRIVATE_KEY` | `ci-signing-subkey.asc` (ASCII-armoured; the subkey only) |
| `WORKSUITE_REPO_GPG_PASSPHRASE` | its passphrase |
| `LINUX_REPO_SSH_KEY` | a dedicated ed25519 deploy key (below) |
| `LINUX_REPO_SSH_KNOWN_HOSTS` | `ssh-keyscan download.worksuitecloud.com` output, checked against the server |
| `LINUX_REPO_SSH_TARGET` | `linux-repo@download.worksuitecloud.com` |
| `UC_DOWNLOAD_TOKEN` | optional: a token that may download from non-public channels (Dev) |

Variable `LINUX_REPO_PATH` if the tree is not at `/srv/linux-repos`. The script imports the key into a temporary
`GNUPGHOME` and deletes it on exit; nothing private is written into the tree (the tests check this).

The **desktop** repository needs only the public key, as a *variable* `WORKSUITE_REPO_GPG_PUBLIC_KEY` (contents of
`worksuite-repo-public.asc`), plus `WORKSUITE_LINUX_REPO` once it is time — see §6.

## 3. First-time setup on the server

Production is native (nginx, PHP-FPM; see `docs/operations.md`). As root, once:

```bash
useradd --system --create-home --shell /bin/sh linux-repo
install -d -o linux-repo -g linux-repo -m 755 /srv/linux-repos /srv/linux-repos/public /srv/linux-repos/state
# CI may only rsync inside the tree (rrsync ships with rsync):
install -d -o linux-repo -m 700 ~linux-repo/.ssh
echo 'command="/usr/bin/rrsync /srv/linux-repos",restrict ssh-ed25519 AAAA… linux-repo-ci' > ~linux-repo/.ssh/authorized_keys
chown linux-repo ~linux-repo/.ssh/authorized_keys && chmod 600 ~linux-repo/.ssh/authorized_keys
```

(With `rrsync` the remote path is relative: set `LINUX_REPO_PATH` to `/` — rrsync maps it to `/srv/linux-repos`.)

Deploy this release's `nginx/site.conf` (the two new locations), `nginx -t && systemctl reload nginx`. Until the
first publish, `/apt/…` answers 404 — which is why no desktop build may point at it yet.

Create the three empty suites and publish the key, from the latest published release on Stable:

```
Actions › Linux repositories › Run workflow: version <latest stable>, channel stable, dry run ✓   → check the log
Actions › Linux repositories › Run workflow: version <latest stable>, channel stable, dry run ✗
```

Then once each for the newest Beta and Dev releases on their channels.

With Docker instead (staging, or a server without the CI path): put the packages in `./incoming` and

```bash
docker compose --profile linux-repo run --rm linux-repo --channel stable --root /srv/linux-repos /incoming/WorkSuite-0.2.249-amd64.deb
```

with `WORKSUITE_REPO_GPG_PRIVATE_KEY` and `WORKSUITE_REPO_GPG_PASSPHRASE` exported in the shell.

## 4. Every release

When a desktop release is **published** on a channel in the Update Center (after QA and approval — never before,
so the repository never runs ahead of the appcast and update answers), run **Linux repositories** with its version
and channel, or send the `desktop-release-published` dispatch from the release tooling:

```bash
gh workflow run linux-repos.yml -R Livezen-Technologies/worksite-download -f version=0.2.249 -f channel=stable -f dry_run=false
```

The job downloads each `linux/{x64,arm64}/{deb,rpm}` of that version through the public resolver, checks it against
the `X-WorkSuite-SHA256` the Update Center sends, adds it, signs, uploads packages before indexes, and verifies
`InRelease` and `repomd.xml.asc` as served. Running it twice is harmless. A package missing from the release is
skipped with a notice. apt keeps the newest version per suite; dnf keeps the newest three per architecture
(`--keep`).

To pull a bad version out of a suite: `reprepro -b /srv/linux-repos/state/reprepro --outdir /srv/linux-repos/public/apt remove <suite> worksuite`
then publish the previous version again (as `linux-repo`, with the key in a temporary `GNUPGHOME`), or simply
publish a fixed newer version.

## 5. Verifying with a clean container

Ubuntu 24.04 (same for `ubuntu:22.04` and `debian:12`):

```bash
docker run --rm -it ubuntu:24.04 bash -c '
  set -e
  apt-get update && apt-get install -y curl ca-certificates
  curl -fsSL https://download.worksuitecloud.com/apt/worksuite.gpg -o /usr/share/keyrings/worksuite.gpg
  curl -fsSL https://download.worksuitecloud.com/apt/worksuite-stable.sources -o /etc/apt/sources.list.d/worksuite.sources
  apt-get update                                   # must show "Get: … download.worksuitecloud.com/apt stable InRelease", no warnings
  apt-cache policy worksuite                       # Candidate: the newest stable version, from …/apt stable/main
  apt-get install -y worksuite && dpkg -s worksuite | grep Version
  cat /etc/apt/sources.list.d/worksuite.sources    # after a WORKSUITE_LINUX_REPO=1 build: the package rewrote it (same text)
'
```

Fedora / RHEL 9:

```bash
docker run --rm -it fedora:40 bash -c '
  set -e
  curl -fsSL https://download.worksuitecloud.com/rpm/worksuite-stable.repo -o /etc/yum.repos.d/worksuite.repo
  curl -fsSL https://download.worksuitecloud.com/rpm/worksuite.gpg -o /etc/pki/rpm-gpg/RPM-GPG-KEY-worksuite
  dnf -y makecache --repo worksuite                # imports and checks the key against repomd.xml.asc
  dnf -y install worksuite && rpm -q worksuite
'
```

Also check by hand: `curl -sI https://download.worksuitecloud.com/apt/dists/stable/InRelease` (200, `text/plain`,
`max-age=60`), `curl -sI https://download.worksuitecloud.com/apt/` (404, no listing),
`curl -sI https://download.worksuitecloud.com/apt/pool/main/w/worksuite/<file>.deb`
(`application/vnd.debian.binary-package`, `immutable`).

## 6. Turning on the desktop switch

Only after §3–§5 pass for **all three** suites:

1. In the desktop repository (worksuite-desktop), *Settings › Secrets and variables › Actions › Variables*:
   `WORKSUITE_REPO_GPG_PUBLIC_KEY` = the contents of `https://download.worksuitecloud.com/apt/worksuite.asc`, and
   `WORKSUITE_LINUX_REPO` = `1`. (Locally: the same two environment variables; the key may be a file path.)
2. Build and release a desktop version as usual. Its `.deb`/`.rpm` carry `resources/linux-repo`, the key and the
   helper; installing or updating to it adds the source for the channel the build is on (an existing source keeps
   its suite), the channel switch in Settings rewrites it through pkexec, Diagnostics shows the three repository
   checks with **Fix**, and Update now on a `.deb` uses `apt-get install --only-upgrade` when apt already has the
   version. The desktop's `docs/RELEASING.md` describes that side.
3. Publish that release to the repositories (§4) like any other.

To turn it off again, unset `WORKSUITE_LINUX_REPO` in the desktop repository. Builds after that add nothing; sources
already written stay (they keep working as long as the repository does) until the package is removed. **Do not take
the repository offline while any build with the switch on is installed anywhere.**
