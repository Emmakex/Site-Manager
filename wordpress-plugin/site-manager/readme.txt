=== Site Manager ===
Contributors: emmake
Tags: site management, discovery, diagnostics, governance
Requires at least: 6.6
Requires PHP: 8.1
Stable tag: 0.1.0
License: Proprietary

Site Manager discovers the real structure of a WordPress installation and reports what is detected, understood, governable, authorized or blocked.

== Description ==

Version 0.1.0 is the first WordPress acceptance build of the platform-neutral Site Manager core.

On activation Site Manager performs a bounded local discovery of the installation, including runtime, routing, active theme, public content types, taxonomies, active plugins and known providers. Detection never grants write access.

This release is intentionally read/diagnostics-first. Generic write governance will be introduced only through explicit adapters with planning, confirmation, verification and rollback contracts.

== Installation ==

1. Upload the official `site-manager-0.1.0.zip` from Plugins > Add New > Upload Plugin.
2. Activate Site Manager.
3. Open Tools > Site Manager.
4. Review the detected installation, capability map and missing adapters.

== Changelog ==

= 0.1.0 =
* First installable WordPress acceptance build.
* Automatic discovery on activation.
* Structural fingerprint and drift refresh.
* Known provider detection.
* Read/diagnostics capability graph with writes blocked by default.
