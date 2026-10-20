# WordPress acceptance — 0.1.0

Target for the first live acceptance: a controlled WordPress installation. Do not use a client production site for this milestone.

## Package identity

- Plugin: Site Manager
- Slug: `site-manager`
- Version: `0.1.0`
- Package: `site-manager-0.1.0.zip`
- Minimum PHP: 8.1
- Minimum WordPress: 6.6

The installable package must contain its own Composer autoloader and runtime classes. The target WordPress must not need Git, Composer or access to this repository.

## Acceptance sequence

1. Back up / snapshot the controlled WordPress environment.
2. Remove or deactivate the previous Research Manager when the test specifically validates Site Manager behavior in isolation.
3. Install the official `site-manager-0.1.0.zip` through WordPress plugin upload.
4. Activate Site Manager.
5. Open **Tools → Site Manager** immediately after activation.
6. Confirm a discovery snapshot already exists without a manual scan.
7. Confirm runtime, site/home URLs, locale, multisite status, routing, theme, public post types, taxonomies and active plugins match the installation.
8. Confirm known integrations are reported as detected but not granted writes merely because they exist.
9. Confirm generic write surfaces remain blocked in 0.1.0.
10. Record the discovery fingerprint.
11. Activate/deactivate a harmless test plugin; reopen Site Manager and confirm discovery refreshes and the fingerprint changes.
12. Restore the previous plugin state and confirm the map refreshes again.
13. If safe in the test environment, change one routing/front-page setting and confirm structural drift refreshes the snapshot.
14. Check authenticated REST discovery/capability/diagnostic responses.
15. Confirm no secret or connection token is exposed in screenshots, logs or acceptance evidence.

## Pass criteria

Acceptance passes only when the observed installation matches Site Manager's inventory and no unsupported write capability is reported as governable.

A discovery mismatch is treated as a product defect. We do not compensate for it with per-client manual configuration unless a platform limitation makes an explicit setting unavoidable.

## Evidence to retain

- plugin version and ZIP SHA-256
- WordPress/PHP versions
- initial fingerprint
- detected theme and providers
- capability/gap summary
- post-change fingerprint
- relevant screenshots with secrets excluded
- acceptance result and blocking defects
