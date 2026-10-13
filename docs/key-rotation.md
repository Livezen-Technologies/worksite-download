# Key rotation drill

The WorkSuite Download Center signs with three Ed25519 keys. Only the public halves ever reach a
customer's server or a WorkSuite Desktop. This is how each one is replaced, and the drill that
proves the procedure works before it is needed for real (IMPL-LIC-01 P6).

| Key | Signs | Where the private half lives | Who pins the public half |
|---|---|---|---|
| `ws-release-1` (RELEASE) | release manifests | signing service (`uc-signing`, `/etc/update-center-signing`) | WorkSuite Server `SystemUpdate/Config/keys.php`, the installer (`install.sh`), WorkSuite Desktop |
| `ws-uc-resp-1` (RESPONSE) | update answers, licence entitlements, download grants, revocations | Vault transit | WorkSuite Server `keys.php`, WorkSuite Desktop |
| `ws-ci-1` (CI attestation) | build attestations | the CI pipeline | the Download Center (`KeyRegistry::ciAttestationKeys`) |

Keys are ACTIVE, OVERLAP (still accepted, no longer used for signing) or RETIRED/REVOKED.
`KeyRegistry` accepts ACTIVE + OVERLAP, so a rotation always has a window where both keys verify.

## Release key

A server cannot be told to trust a new release key by anything except a manifest signed by a key it
already trusts.

1. Generate `ws-release-2` in the signing service. Register it as OVERLAP (`signing_keys`), not ACTIVE.
2. Publish a release whose manifest is signed by `ws-release-1` and carries
   `release_key_rotation: {"ws-release-2": "<public key>"}`. The updater agent (`Runner`) accepts it
   only because `ws-release-1` signed the manifest, and keeps it in `keys/release-keys.json` beside the
   releases, where the web server cannot write.
3. Ship the next WorkSuite Server, Desktop and `install.sh` with both keys pinned.
4. When every supported version pins `ws-release-2`, make it ACTIVE (signing switches) and
   `ws-release-1` OVERLAP.
5. When no supported version is signed by `ws-release-1`, RETIRE it, and drop it from the pins in the
   next releases.

## Response key

Response documents are short-lived (minutes to seven days), so no announce-in-band step is needed.

1. Create `ws-uc-resp-2` in Vault transit and register it as OVERLAP.
2. Ship WorkSuite Server and Desktop with both response keys pinned.
3. When every supported version pins it, switch `updatecenter.responseKeyId` to `ws-uc-resp-2`
   (ACTIVE) and set `ws-uc-resp-1` to OVERLAP.
4. A week later — the longest entitlement validity — RETIRE `ws-uc-resp-1`. Servers that were offline
   longer revalidate on their next contact and fall into grace, not a lockout.

## Revocation

If a private key leaks, set it to REVOKED. It disappears from `KeyRegistry` at once and is listed
in `GET /api/v1/server/revocations/latest`, which servers fetch with every validation. Rotate as
above, with the steps compressed.

## The drill (every six months, on staging)

1. On staging, create `ws-release-drill` and `ws-uc-resp-drill` as OVERLAP.
2. Publish an INTERNAL release whose manifest (signed by the current key) announces
   `ws-release-drill`; update a staging WorkSuite Server through it and confirm
   `keys/release-keys.json` gained the key and the agent log says the rotation was accepted.
3. Switch staging's signing to the drill keys; publish another INTERNAL release; confirm the staging
   server updates, that `spark integrity:check --full` reports HEALTHY (the manifest kept beside the
   release verifies under the new key), and that `spark license:validate --force` succeeds with the
   new response key pinned.
4. Revoke the drill keys; confirm `revocations/latest` lists them and a manifest signed by them is refused.
5. Switch staging back, retire the drill keys, and record the date and result here.

| Date | Result | By |
|---|---|---|
| — | not yet run | |
