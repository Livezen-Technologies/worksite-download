#!/usr/bin/env bash
# Publish WorkSuite Desktop .deb / .rpm builds into the WorkSuite APT and DNF repositories
# (worksuite-desktop#351; docs/linux-repositories.md).
#
#   scripts/publish-linux-repos.sh --channel stable|beta|dev --root /srv/linux-repos [options] FILE.deb|FILE.rpm …
#
#   --root DIR      the repository tree (default $LINUX_REPO_ROOT, else /srv/linux-repos):
#                     DIR/state/reprepro/{conf,db}   reprepro's own state — never served
#                     DIR/public/apt/                dists/, pool/, worksuite.gpg (binary), worksuite.asc
#                     DIR/public/rpm/<channel>/      the .rpm files and repodata/ (repomd.xml + repomd.xml.asc)
#                     DIR/public/rpm/worksuite.gpg   the same key, ASCII-armoured (rpm --import takes that)
#                   nginx serves DIR/public at /apt and /rpm (nginx/site.conf).
#   --channel CH    the suite (APT) and directory (DNF) to add the packages to.
#   --keep N        .rpm files kept per channel and architecture (default 3). APT keeps the newest version per suite.
#   --dry-run       check the files and say what would change; needs no key and changes nothing.
#
# The signing key comes from the environment, never from the repository:
#   WORKSUITE_REPO_GPG_PRIVATE_KEY   the ASCII-armoured private key (a CI secret). It is imported into a temporary
#                                    GNUPGHOME that is deleted on exit. Without it, the key must already be in
#                                    $GNUPGHOME and WORKSUITE_REPO_GPG_KEY_ID must name it.
#   WORKSUITE_REPO_GPG_PASSPHRASE    its passphrase, if it has one (kept in a 0600 file in that temporary home).
#   WORKSUITE_REPO_GPG_KEY_ID        optional: the fingerprint to sign with (default: the imported key).
#
# Needs reprepro (for .deb), createrepo_c (for .rpm), gpg, dpkg-deb and flock — the CI job installs them, and
# docker/linux-repo.Dockerfile has them all. Idempotent: a package already in the suite (same version and
# architecture, or the same .rpm bytes) is skipped, nothing is re-signed when nothing changed, and a second run
# with the same files changes nothing. A different file under a name already published is refused.
#
# Exit codes: 0 done (or nothing to do), 1 failure, 2 wrong usage.
set -euo pipefail

HERE=$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
SIGN_HOOK="$HERE/linux-repo-sign.sh"
SUITES=(stable beta dev)
ARCHS_DEB='amd64 arm64'
URL_BASE=${LINUX_REPO_PUBLIC_URL:-https://download.worksuitecloud.com}
URL_BASE=${URL_BASE%/}
HEADER='# Written by WorkSuite (worksuite-desktop#351). Changing the update channel in WorkSuite rewrites this file.'

root=${LINUX_REPO_ROOT:-/srv/linux-repos}
channel=''
keep=3
dry=0
files=()

log() { printf '%s\n' "publish-linux-repos: $*" >&2; }
die() { log "error: $*"; exit 1; }
usage() { sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2; exit 2; }

while [ $# -gt 0 ]; do
  case "$1" in
    --channel) channel=${2:-}; shift 2 ;;
    --channel=*) channel=${1#*=}; shift ;;
    --root) root=${2:-}; shift 2 ;;
    --root=*) root=${1#*=}; shift ;;
    --keep) keep=${2:-}; shift 2 ;;
    --keep=*) keep=${1#*=}; shift ;;
    --dry-run) dry=1; shift ;;
    -h | --help) usage ;;
    --) shift; files+=("$@"); break ;;
    -*) log "unknown option $1"; usage ;;
    *) files+=("$1"); shift ;;
  esac
done

case "$channel" in stable | beta | dev) ;; *) log "--channel must be stable, beta or dev"; usage ;; esac
[[ "$keep" =~ ^[1-9][0-9]*$ ]] || { log "--keep must be a positive number"; usage; }
[ -n "$root" ] || usage
[ ${#files[@]} -gt 0 ] || { log "no .deb or .rpm given"; usage; }

STATE="$root/state/reprepro"
APT="$root/public/apt"
RPM="$root/public/rpm"

# ── The packages: only WorkSuite's own, and each readable ────────────────────────────────────────────────
debs=()
rpms=()
for f in "${files[@]}"; do
  [ -f "$f" ] || die "$f does not exist"
  case "$f" in
    *.deb)
      command -v dpkg-deb >/dev/null || die "dpkg-deb is needed to read $f"
      pkg=$(dpkg-deb -f "$f" Package 2>/dev/null) || die "$f is not a Debian package"
      [ "$pkg" = worksuite ] || die "$f is the package '$pkg', not worksuite"
      debs+=("$f")
      ;;
    *.rpm)
      if command -v rpm >/dev/null; then
        name=$(rpm -qp --nosignature --qf '%{NAME}' "$f" 2>/dev/null) || die "$f is not an RPM package"
        [ "$name" = worksuite ] || die "$f is the package '$name', not worksuite"
      else
        [[ "$(basename -- "$f")" =~ ^WorkSuite-[0-9][0-9A-Za-z.~+-]*\.(x86_64|aarch64)\.rpm$|^WorkSuite-[0-9][0-9A-Za-z.~+-]*-(x86_64|aarch64)\.rpm$|^worksuite-[0-9].*\.(x86_64|aarch64)\.rpm$ ]] ||
          die "$f is not named like a WorkSuite .rpm (rpm is not installed to read it)"
      fi
      rpms+=("$f")
      ;;
    *) die "$f is neither a .deb nor an .rpm" ;;
  esac
done

rpm_arch() { # the architecture of an .rpm, from rpm or else its name
  if command -v rpm >/dev/null; then rpm -qp --nosignature --qf '%{ARCH}' "$1" 2>/dev/null; return; fi
  case "$(basename -- "$1")" in *aarch64*) echo aarch64 ;; *) echo x86_64 ;; esac
}

source_text() { # kind suite — the same text as the desktop's electron/updater/linux-repo.ts
  if [ "$1" = deb ]; then
    printf '%s\nTypes: deb\nURIs: %s/apt\nSuites: %s\nComponents: main\nSigned-By: /usr/share/keyrings/worksuite.gpg\n' "$HEADER" "$URL_BASE" "$2"
  else
    printf '%s\n[worksuite]\nname=WorkSuite Desktop (%s)\nbaseurl=%s/rpm/%s\nenabled=1\ngpgcheck=0\nrepo_gpgcheck=1\ngpgkey=file:///etc/pki/rpm-gpg/RPM-GPG-KEY-worksuite\nmetadata_expire=6h\n' "$HEADER" "$2" "$URL_BASE" "$2"
  fi
}

# ── Dry run: say what would happen ───────────────────────────────────────────────────────────────────────
if [ "$dry" = 1 ]; then
  missing=()
  [ ${#debs[@]} -eq 0 ] || command -v reprepro >/dev/null || missing+=(reprepro)
  [ ${#rpms[@]} -eq 0 ] || command -v createrepo_c >/dev/null || missing+=(createrepo_c)
  command -v gpg >/dev/null || missing+=(gpg)
  for f in "${debs[@]}"; do
    v=$(dpkg-deb -f "$f" Version) a=$(dpkg-deb -f "$f" Architecture)
    state='would add'
    if [ -d "$STATE/db" ] && command -v reprepro >/dev/null && reprepro -b "$STATE" --outdir "$APT" list "$channel" worksuite 2>/dev/null | grep -Fqx "$channel|main|$a: worksuite $v"; then state='already there'; fi
    echo "apt $channel/main $a: worksuite $v — $state ($f)"
  done
  for f in "${rpms[@]}"; do
    target="$RPM/$channel/packages/$(basename -- "$f")"
    if [ -f "$target" ]; then
      if cmp -s "$f" "$target"; then state='already there'; else state='REFUSED: a different file is published under this name'; fi
    else state='would add'; fi
    echo "rpm $channel $(rpm_arch "$f"): $(basename -- "$f") — $state"
  done
  echo "signing: ${WORKSUITE_REPO_GPG_KEY_ID:-the key in WORKSUITE_REPO_GPG_PRIVATE_KEY} (not used in a dry run)"
  [ ${#missing[@]} -eq 0 ] || echo "missing here (the CI job and docker/linux-repo.Dockerfile have them): ${missing[*]}"
  echo "dry run: nothing changed under $root"
  exit 0
fi

# ── The real run ─────────────────────────────────────────────────────────────────────────────────────────
command -v gpg >/dev/null || die "gpg is not installed"
[ ${#debs[@]} -eq 0 ] || command -v reprepro >/dev/null || die "reprepro is not installed (apt-get install reprepro, or use docker/linux-repo.Dockerfile)"
[ ${#rpms[@]} -eq 0 ] || command -v createrepo_c >/dev/null || die "createrepo_c is not installed (apt-get install createrepo-c, or use docker/linux-repo.Dockerfile)"
[ -x "$SIGN_HOOK" ] || die "$SIGN_HOOK is missing or not executable"

mkdir -p "$STATE/conf" "$APT" "$RPM"
exec 9>"$root/.publish.lock"
flock -n 9 || die "another publish is running on $root"

# The signing key: imported into a throwaway keyring, never written anywhere else.
tmp=$(mktemp -d)
# The agent this run's temporary keyring started goes with it.
trap 'if [ -d "$tmp/gnupg" ]; then GNUPGHOME="$tmp/gnupg" gpgconf --kill gpg-agent 2>/dev/null || true; fi; rm -rf "$tmp"' EXIT
if [ -n "${WORKSUITE_REPO_GPG_PRIVATE_KEY:-}" ]; then
  export GNUPGHOME="$tmp/gnupg"
  mkdir -m 700 "$GNUPGHOME"
  printf '%s\n' "$WORKSUITE_REPO_GPG_PRIVATE_KEY" | gpg --batch --quiet --import 2>/dev/null || die "WORKSUITE_REPO_GPG_PRIVATE_KEY could not be imported"
fi
key=${WORKSUITE_REPO_GPG_KEY_ID:-}
if [ -z "$key" ]; then
  key=$(gpg --batch --with-colons --list-secret-keys 2>/dev/null | awk -F: '$1 == "fpr" { print $10; exit }')
fi
[ -n "$key" ] || die "no signing key: set WORKSUITE_REPO_GPG_PRIVATE_KEY (or GNUPGHOME and WORKSUITE_REPO_GPG_KEY_ID)"
gpg --batch --list-secret-keys "$key" >/dev/null 2>&1 || die "the secret key $key is not in the keyring"
export WS_REPO_KEY="$key"
if [ -n "${WORKSUITE_REPO_GPG_PASSPHRASE:-}" ]; then
  export WS_REPO_PASSFILE="$tmp/passphrase"
  (umask 077 && printf '%s' "$WORKSUITE_REPO_GPG_PASSPHRASE" >"$WS_REPO_PASSFILE")
fi
# Fail now, not halfway through: the key must sign with that passphrase.
echo test | "$SIGN_HOOK" /dev/stdin "" "$tmp/probe.asc" 2>/dev/null || die "the key $key cannot sign (wrong passphrase?)"

write_if_changed() { # target, content on stdin
  local new="$1.new.$$"
  mkdir -p "$(dirname -- "$1")"
  cat >"$new"
  if [ -f "$1" ] && cmp -s "$new" "$1"; then rm -f "$new"; else mv -f "$new" "$1" && log "wrote ${1#"$root"/}"; fi
}

# The public key and ready-made source files, for people who add the repository by hand.
gpg --batch --export "$key" | write_if_changed "$APT/worksuite.gpg"
gpg --batch --armor --export "$key" | write_if_changed "$APT/worksuite.asc"
gpg --batch --armor --export "$key" | write_if_changed "$RPM/worksuite.gpg"
for s in "${SUITES[@]}"; do
  source_text deb "$s" | write_if_changed "$APT/worksuite-$s.sources"
  source_text rpm "$s" | write_if_changed "$RPM/worksuite-$s.repo"
done

# ── APT (reprepro) ───────────────────────────────────────────────────────────────────────────────────────
if [ ${#debs[@]} -gt 0 ] || [ -d "$STATE/db" ]; then
  {
    for s in "${SUITES[@]}"; do
      printf 'Origin: WorkSuite\nLabel: WorkSuite\nSuite: %s\nCodename: %s\nArchitectures: %s\nComponents: main\nDescription: WorkSuite Desktop (%s)\nSignWith: ! %s\n\n' "$s" "$s" "$ARCHS_DEB" "$s" "$SIGN_HOOK"
    done
  } | write_if_changed "$STATE/conf/distributions"
  printf 'verbose\n' | write_if_changed "$STATE/conf/options"
  rr=(reprepro -b "$STATE" --outdir "$APT")
  for f in "${debs[@]}"; do
    v=$(dpkg-deb -f "$f" Version) a=$(dpkg-deb -f "$f" Architecture)
    case " $ARCHS_DEB " in *" $a "*) ;; *) die "$f is for $a; the repository carries $ARCHS_DEB" ;; esac
    if "${rr[@]}" list "$channel" worksuite 2>/dev/null | grep -Fqx "$channel|main|$a: worksuite $v"; then
      log "apt $channel $a: worksuite $v is already there"
      continue
    fi
    "${rr[@]}" includedeb "$channel" "$f"
    log "apt $channel $a: added worksuite $v"
  done
  # Every suite exists and is signed (an empty Beta still answers apt), without re-signing an unchanged one.
  for s in "${SUITES[@]}"; do
    if [ ! -s "$APT/dists/$s/InRelease" ]; then "${rr[@]}" export "$s"; fi
  done
fi

# ── DNF (createrepo_c) ───────────────────────────────────────────────────────────────────────────────────
changed=0
if [ ${#rpms[@]} -gt 0 ]; then
  dir="$RPM/$channel"
  mkdir -p "$dir/packages"
  for f in "${rpms[@]}"; do
    target="$dir/packages/$(basename -- "$f")"
    if [ -f "$target" ]; then
      cmp -s "$f" "$target" || die "$target is already published with different contents; publish a new version instead"
      log "rpm $channel: $(basename -- "$f") is already there"
      continue
    fi
    cp -- "$f" "$target.part" && mv -f "$target.part" "$target"
    log "rpm $channel: added $(basename -- "$f")"
    changed=1
  done
  # Keep the newest --keep per architecture.
  for arch in x86_64 aarch64; do
    mapfile -t old < <(for p in "$dir"/packages/*.rpm; do [ -e "$p" ] && [ "$(rpm_arch "$p")" = "$arch" ] && basename -- "$p"; done | sort -V | head -n "-$keep")
    for o in "${old[@]}"; do
      [ -n "$o" ] || continue
      rm -f -- "$dir/packages/$o"
      log "rpm $channel: removed old $o"
      changed=1
    done
  done
fi
for s in "${SUITES[@]}"; do
  dir="$RPM/$s"
  if { [ "$s" = "$channel" ] && [ "$changed" = 1 ]; } || { [ -d "$dir/packages" ] && [ ! -s "$dir/repodata/repomd.xml" ]; }; then
    createrepo_c --quiet --update --general-compress-type gz "$dir"
    log "rpm $s: metadata rebuilt"
  fi
  if [ -s "$dir/repodata/repomd.xml" ]; then
    if [ ! -s "$dir/repodata/repomd.xml.asc" ] || ! gpg --batch --verify "$dir/repodata/repomd.xml.asc" "$dir/repodata/repomd.xml" >/dev/null 2>&1; then
      "$SIGN_HOOK" "$dir/repodata/repomd.xml" "" "$dir/repodata/repomd.xml.asc"
      log "rpm $s: repomd.xml signed"
    fi
  fi
done

log "done: $root (channel $channel, key $key)"
