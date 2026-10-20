# WordPress discovery adapter (SM-1)

SM-1 is intentionally read/discovery only. Its job is to install on an unknown WordPress site and immediately explain what is present, what Site Manager understands, what is governable now and what still needs an adapter.

## Activation lifecycle

1. Plugin bootstrap loads the shared Site Manager package.
2. Activation calls the WordPress adapter immediately.
3. The adapter detects runtime, routing, theme, public content types, public taxonomies and active plugins.
4. Known providers are classified (WooCommerce, Elementor, ACF, SEO and multilingual providers among the initial set).
5. A deterministic fingerprint is generated from structural state.
6. A normalized capability graph is persisted with diagnostics and governance gaps.
7. Theme/plugin/routing changes mark the snapshot dirty; the next Manager request refreshes it. Fingerprint drift also refreshes the snapshot.

## Safety boundary

SM-1 exposes read and diagnostic capabilities only. Generic content, navigation, SEO and provider writes are deliberately reported as blocked until SM-2/SM-3 adapters provide ownership rules, validation, snapshots, verification and rollback.

A detected provider is never treated as a write capability.

## Local WordPress surface

Tools → Site Manager displays:

- platform/runtime
- active theme
- content type/taxonomy counts
- active plugin count
- known providers
- site fingerprint
- normalized capability summary
- missing governance adapters

Authenticated local REST reads are also available at:

- `/wp-json/site-manager/v1/discovery`
- `/wp-json/site-manager/v1/capabilities`
- `/wp-json/site-manager/v1/diagnostics`

These routes use the current WordPress administrator session. Remote/MCP credentials are a later boundary and are not implied by SM-1.
