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
