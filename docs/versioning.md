# Versioning and changelog

This repository (the Update Center itself) follows **SemVer 2.0**. The
versions of the applications it *distributes* (WorkSuite Desktop, Server …)
are managed as releases inside the Update Center and are unrelated to this
number.

## Source of truth

| File | Role |
|---|---|
| `VERSION` | The version, e.g. `0.1.0` or `0.2.0-rc.1`. Nothing else is edited by hand. |
| `CHANGELOG.md` | Keep a Changelog; every change goes under `## [Unreleased]`. |
| `frontend/package.json`, `sdks/bugtrace-web/package.json` | Synced from `VERSION` by the script. |
| `backend/app/Libraries/AppVersion.php` | Reads `VERSION` at runtime (or `UC_VERSION`); CI adds `UC_BUILD` and `UC_COMMIT`. |

The running version is visible at `GET /api/v1/version`, in `GET /api/v1/site`,
in *Administration › Health* and in the `X-Update-Center-Version` response header.

## Day to day

Add a bullet under the right heading of `## [Unreleased]` in the same pull
request as the change (**Added / Changed / Deprecated / Removed / Fixed /
Security**). Reference SRS IDs where useful (e.g. `FR-UC-ROL-03`).

## Cutting a release

```bash
php scripts/version.php show                 # 0.1.0
php scripts/version.php bump minor --dry-run # preview
php scripts/version.php bump minor           # 0.1.0 → 0.2.0
php scripts/version.php bump patch --pre=rc.1  # 0.2.0 → 0.2.1-rc.1
php scripts/version.php bump patch           # 0.2.1-rc.1 → 0.2.1 (finalises the prerelease)
php scripts/version.php set 1.0.0            # explicit
git commit -am "Release v0.2.0" && git tag v0.2.0
```

`bump` / `set`:

1. refuses when `[Unreleased]` is empty or the new version is not greater;
2. renames `[Unreleased]` to `[x.y.z] - YYYY-MM-DD` and opens a fresh `[Unreleased]`;
3. updates the compare links at the bottom of the changelog;
4. writes `VERSION` and syncs every `package.json`.

Choose the bump by impact: **major** for breaking API / manifest schema /
database changes that need coordinated tenant or desktop updates, **minor**
for new features, **patch** for fixes.

## CI

`php scripts/version.php check` fails the build when a `package.json`
disagrees with `VERSION` or the changelog has no dated section for the
current version.
