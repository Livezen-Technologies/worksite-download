#!/bin/sh
# reprepro's SignWith hook for the WorkSuite APT repository (docs/linux-repositories.md).
#
# reprepro calls it as  linux-repo-sign.sh <Release> <InRelease to write> <Release.gpg to write>
# (either output may be empty), with the environment publish-linux-repos.sh set up:
#   GNUPGHOME              the keyring holding the repository key (a temporary one in CI)
#   WS_REPO_KEY            the key's fingerprint
#   WS_REPO_PASSFILE       optional: a file holding the key's passphrase
set -eu

release=$1
inline=${2:-}
detached=${3:-}
: "${WS_REPO_KEY:?WS_REPO_KEY is not set (run through scripts/publish-linux-repos.sh)}"

sign() {
  if [ -n "${WS_REPO_PASSFILE:-}" ]; then
    gpg --batch --yes --no-tty --pinentry-mode loopback --passphrase-file "$WS_REPO_PASSFILE" --local-user "$WS_REPO_KEY" --digest-algo SHA512 "$@"
  else
    gpg --batch --yes --no-tty --local-user "$WS_REPO_KEY" --digest-algo SHA512 "$@"
  fi
}

if [ -n "$inline" ]; then sign --clearsign --output "$inline" "$release"; fi
if [ -n "$detached" ]; then sign --armor --detach-sign --output "$detached" "$release"; fi
