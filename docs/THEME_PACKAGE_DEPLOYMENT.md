# Governed WordPress Theme package deployment (#89)

## State of this cut

The WordPress adapter provides a separately scoped `theme.package.write` capability and an internal package lifecycle via `RemoteThemePackageAccess`. This is **not** a general file editor, PHP executor, REST proxy, or arbitrary ZIP uploader. Normal operation remains ChatGPT → typed GitHub Command → protected Command Gateway → MCP → Site Manager → WordPress. Until that protected MCP/Gateway bridge is implemented and end-to-end WordPress acceptance succeeds, **do not claim this endpoint is usable from ChatGPT**.

## Plan / Apply / Rollback

- WordPress Connection must explicitly grant `theme.package.write` to the original bound administrator. The separate `content.write` scope does not authorize code updates.
- Only the **active standalone theme** is eligible, not other themes or child themes. WordPress must report `local`, `development`, or `staging`, and allow direct filesystem modifications. Multisite and production fail closed.
- `plan` receives official bytes from the trusted MCP after GitHub CI exact-commit provenance checking, in Base64, with a SHA-256 digest and immutable source run/commit/artifact IDs. The request does not select a backend URL, shell, path or PHP.
- The Site Manager WordPress adapter rechecks ZIP root, theme slug, integrity manifest, file contents and digest, stages the bytes outside the served document root and captures the actual installed Theme tree SHA. A short-lived WordPress-side plan is tied to connection ID, connection generation, site fingerprint and old/new tree digests. Plan returns **no private staging path, ZIP bytes, private file names or contents**.
- `apply` accepts only an unexpired `plan_id` and literal `confirmed:true`. It rechecks the staging ZIP and site fingerprint, then runs the Theme package engine: WordPress capabilities, environment, locking, verified private snapshot, native `Theme_Upgrader` overwrite, byte-for-byte readback and optional automatic restoration if the install fails.
- `rollback` accepts an approved applied plan only, rejects drift from the installed release and independently validates and restores the retained original snapshot via WordPress's Theme updater.
- The REST adapter strips internal backup and staging locations from responses. The sanitized Command Gateway must apply its own closed response allowlist before persisting evidence in GitHub.

## Outstanding acceptance — NOT YET RELEASED

1. Implement the strict official-artefact downloader and bytes transport **in the protected Gateway**, with `theme.package.deploy` registry/schema/MCP stages. Do not accept arbitrary repo or download URLs in the user command. The WordPress endpoint's source IDs alone are **not** proof of provenance.
2. Run a real disposable WordPress staging fixture with filesystem API direct: plan, install, verify, original content/settings/noindex preserved, explicit rollback, source mismatch, failing upgrade/partial restore, lost connection and concurrency. A simulated installer test alone is not full WordPress acceptance.
3. Prove an exact ZIP artifact checksum and active file-tree hash, confirm public CSS/JS bytes, verify Home and interior pages in desktop/mobile, and preserve last rollback.
4. Establish a one-time plugin bootstrap for existing `0.11.13` installations; only after live installed plugin and MCP image are updated can the route execute. Initial plugin bootstrap may still require a one-time trusted admin/hosting update. Do not claim it is already available on Emmake.
5. Add deterministic staging/recovery artifact retention cleanup and recovery operations for timeouts/failed requests before declaring production-ready. No repeated manual theme ZIP installation should be normal.

## Fail-closed conditions

Invalid package, altered SHA-256, unsupported identity, symlinks/traversal, private directory unavailable, expired plan, wrong connection generation, changed site fingerprint, theme tree drift, production environment, insufficient write scope/capabilities, non-direct filesystem, lock contention, failed readback, and incomplete restoration. These are blockers, not success states.


## Emmake /nuevaweb activation acceptance — 14 Nov 2026

- Live scoped `site.read` confirmed Site Manager `0.12.0`, WP `7.1.4`, PHP `8.5.12`, `WP_ENVIRONMENT_TYPE=staging`, active standalone `seo-geo-theme 0.1.1`, and `theme.package.write` **governable** (this is not proof that the scope is granted).
- Protected deployment `deploy-emmake-nuevaweb-mcp-package-20261114-01` passed and pinned MCP image `sha256:69686458e63df31ae1d2503de5140a717486347c26c3c886608ffd6895f92cf6` was accepted. Read-only `site.theme_package_state` returned **180 files / 1,215,811 bytes / full-tree SHA-256 `8dd8d28019acb47c82d7a000c27d7b9ce36a921a38b21d5a4db74b5a797e49f7`**.
- The exact file-tree SHA of the official Theme release candidate from `Emmakex/template-wordpress-seo-geo` commit `aa1f25c6c16c381deafc59619912532785cfe312`, workflow run `43418785500`, artifact `14032254429`, inner ZIP digest `2c689b23c93ed2b3dd5f75dfd078e5df734f08665f16bf801fca36b9db882c5f`, matches the live installed tree. **Never redeploy a no-op just to claim progress**.
- A protected plan probe with missing `SITE_MANAGER_THEME_RELEASE_TOKEN` failed `configuration_failed`. Attempting current-repository ephemeral `GITHUB_TOKEN` returned `verification_failed`; isolated GitHub Actions test proved **HTTP 401** on the target repository's public artifact without an appropriate token. Experimental anonymous-access PR #122 was rejected/closed; do not bypass the official Actions artifact.
- **Required one-time environment setup:** In the protected GitHub Environment `site-manager-emmake-nuevaweb`, create an encrypted secret named `SITE_MANAGER_THEME_RELEASE_TOKEN`, backed by a fine-grained token limited to `Emmakex/template-wordpress-seo-geo` with **Actions: read** (and no write scopes). Do not paste or store the token in a command, PR, chat, WordPress page or repository. The `Site Manager → Connection` admin must also grant `theme.package.write` to the existing connection if not yet granted. Verify real scopes before any Apply.
- After the secret is provisioned, a future Theme release that genuinely changes the file-tree can be safely planned with exact provenance, independently verified, and applied with backup/rollback. Do not modify the live Theme while no verified newer release exists.
