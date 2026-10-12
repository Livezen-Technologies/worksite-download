# Sparkle appcasts (macOS apps that update through Sparkle)

WorkSuite Desktop asks the Update API whether to update. WorkSuite Office does not. On macOS it updates
through [Sparkle](https://sparkle-project.org) 2, which reads an appcast (an RSS feed) and installs what
the feed offers. The Update Center serves that feed:

```
GET /api/v1/apps/{app}/channels/{channel}/appcast.xml?arch=arm64|x64[&type=dmg|zip]
```

For example `https://download.worksuitecloud.com/api/v1/apps/lk.worksuite.office/channels/beta/appcast.xml?arch=arm64`.

## What it offers

The feed offers whatever an anonymous macOS update check on that channel would get (`UpdateResolverService::decide`):

* the newest **published** release with a **READY** package for the architecture;
* only once it is **fully rolled out** — a staged release is not offered until it reaches 100 %, because a feed
  has no device to put in a cohort. Until then the previous release stays in the feed;
* never a withdrawn or blocked release.

It has at most one item:

| Appcast | From |
|---|---|
| `sparkle:version` | the release's **build number**. The build stamps it into `CFBundleVersion`, which Sparkle compares. |
| `sparkle:shortVersionString` | the release version |
| `sparkle:minimumSystemVersion` | the package slot's minimum OS |
| `sparkle:criticalUpdate` | present when the Update API would answer `UPDATE_REQUIRED`: a CRITICAL release, or a mandatory one past its deadline |
| `description` | the release notes as the site renders them (sanitised HTML) |
| `enclosure` | the public download URL of that build (`/download/{slug}/[{channel}/]{version}/macos/{arch}/{type}`), its size, and `sparkle:edSignature` |

Sparkle sends no credentials, so only a **public channel of a public application** answers. Anything else gets
`403 CHANNEL_FORBIDDEN`. Responses are `Cache-Control: public, max-age=60`.

## Where the signature comes from

Sparkle verifies an Ed25519 signature over the archive itself, against the key compiled into the app
(`SUPublicEDKey`). The signing service only signs documents, never archives, so CI makes this signature:

1. The Mac build signs the archive with Sparkle's own `sign_update` and the app's Sparkle private key. It
   writes the `sparkle:edSignature` value to `<archive>.edsig`.
2. `scripts/publish-release.mjs` puts the signature into the CI build attestation beside the file's SHA-256,
   as `files[].sparkle_ed_signature`. If `UC_SPARKLE_PUBLIC_KEY` is set (the same value as `SUPublicEDKey`),
   the script first checks the signature against the archive and stops on a mismatch. It also stops on a
   macOS file that has no `.edsig`.
3. The feed takes the signature from a **verified** attestation of the release, matched on the active
   revision's SHA-256.

A package with no attested signature is left out of the feed, because Sparkle would refuse it. An emergency
replacement of a published package therefore needs a new attestation, as the release manifest already does.

## Setting up WorkSuite Office

These steps happen once, in production.

1. **The application**, in `/admin` › Applications:
   * name `WorkSuite Office`, slug `office`, identifier `lk.worksuite.office`, kind Desktop, public;
   * platform macOS arm64, types `dmg`, `zip`, default `dmg`, minimum OS `11.0`;
   * platform macOS x64, types `dmg`, `zip`, default `dmg`;
   * channels Stable and Beta.

   The seed catalog (`CatalogSeeder`) creates the same entry for development and tests.
2. **Code signing.** Stable requires a Developer ID–signed, notarised build. Until WorkSuite Office has one,
   publish to **Beta**. Turn on `updatecenter.allowUnsignedTestChannels` and make Beta public, so that Sparkle,
   which signs in to nothing, can read it.
3. **The Sparkle key pair.** Generate it on a Mac with the tools vendored in the app's repository
   (`macos/Vendor/Sparkle/bin` in worksuite-office-desktop-apps):
   * `generate_keys` saves the key in the login keychain and prints the public key. That public key is
     `SUPublicEDKey`, stored as the repository variable `SPARKLE_PUBLIC_ED_KEY` of worksuite-office.
   * `generate_keys -x sparkle-private.key` exports the private key. Store it as the GitHub secret
     `SPARKLE_ED_PRIVATE_KEY` of worksuite-office, then delete the file. Keep a copy offline: an app can only
     verify updates signed with the key it was built with, so a lost key means a manual reinstall for everyone.
4. **Publishing:** a CI API client (`php spark uc:create-ci-client`) and a pinned CI attestation key, as for
   WorkSuite Desktop. Then:

   ```bash
   UC_SPARKLE_PUBLIC_KEY=<SUPublicEDKey> node scripts/publish-release.mjs \
     --app office --app-id lk.worksuite.office --channel BETA \
     --version 9.3.2 --build 1042 --commit <sha> --summary "WorkSuite Office 9.3.2" \
     --file macos:arm64:dmg:WorkSuite-Office-9.3.2-arm64.dmg
   ```

   `WorkSuite-Office-9.3.2-arm64.dmg.edsig` must sit beside the archive. Build numbers must rise on each
   channel. Installed copies of WorkSuite Office report `CFBundleVersion` 537, so the first build number has
   to be above that.
