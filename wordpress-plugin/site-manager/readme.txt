=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.1.2
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Site Manager is a platform-neutral control plane. WordPress is its first adapter, not the product boundary.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, active plugins and known providers. Detection never grants write access.

The WordPress dashboard is available in English and Spanish. It follows the current WordPress administrator locale automatically; English is the source/fallback language and Spanish locales use the bundled Spanish catalogue.

This release is intentionally read/diagnostics-first. Generic write governance will be introduced only through explicit adapters with planning, confirmation, verification and rollback contracts.

== Installation ==

1. Upload the official `site-manager-0.1.2.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open the top-level Site Manager menu in WordPress admin.
4. Review the dashboard, detected installation, capability map and missing adapters.
5. Site Manager automatically follows the administrator language (English or Spanish).

== Changelog ==

= 0.1.2 =
* Adds complete English/Spanish localization for the WordPress dashboard.
* Uses the current WordPress administrator locale automatically.
* Ships a bundled Spanish catalogue and keeps English as source/fallback.
* Localizes capability summaries and governance blockers at render time without changing the language-neutral stored contract.

= 0.1.1 =
* Fixes dashboard rendering when the stored platform identity comes from 0.1.0.
* Normalizes persisted discovery data to plain serializable arrays.
* Moves Site Manager out of Tools into its own top-level WordPress admin menu.
* Keeps existing 0.1.0 discovery data compatible and refreshes legacy snapshots automatically.

= 0.1.0 =
* First installable WordPress acceptance build.
* Automatic discovery on activation.
* Structural fingerprint and drift refresh.
* Known provider detection.
* Read/diagnostics capability graph with writes blocked by default.
