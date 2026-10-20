# Site Manager architecture

## 1. Core invariant

The core is platform-neutral. It never imports WordPress, PrestaShop or any other CMS-specific runtime directly.

Every platform is integrated through an adapter that implements the same lifecycle:

1. `detect()` — identify platform and version.
2. `discover()` — inspect structure and installed capabilities.
3. `capabilities()` — report what the adapter can safely read / write.
4. `diagnostics()` — report health and blockers.
5. `plan()` — prepare a bounded change without mutating the target.
6. `apply()` — execute an approved plan.
7. `verify()` — confirm the expected state after mutation.
8. `rollback()` — restore a captured snapshot when supported.
9. `audit()` — emit immutable operation metadata.

## 2. Capability states

Each discovered surface is represented independently from authorization.

- `detected`: technology / surface exists.
- `understood`: Site Manager has a known model for it.
- `governable`: an adapter supports safe operations.
- `authorized`: the current connection has the required scope.
- `blocked`: detected but unsafe or unsupported.

This prevents a false assumption that detection implies mutation rights.

## 3. Core modules

### Discovery
Builds a normalized site fingerprint and platform inventory.

### Capability graph
Combines platform, extension and provider capabilities into one normalized graph.

### Governance policy
Checks scope, adapter support, risk class, confirmation class and mutation policy before execution.

### Planning
Produces an explicit, reviewable plan. Plans are immutable and receive a stable identifier.

### Snapshot / rollback
Captures the smallest reversible state required for each operation.

### Verification
Reads the target after apply and compares it with the expected outcome.

### Audit
Records discovery, planning, approvals, execution, verification and rollback events.

## 4. Platform adapters

### WordPress
First production adapter and current acceptance target.

Sub-adapters may provide specialized ownership for:

- WordPress Core
- themes / theme contracts
- WooCommerce
- Elementor
- ACF
- SEO providers
- multilingual providers

### PrestaShop
Second platform adapter. It must reuse the same normalized core contracts and must not introduce PrestaShop assumptions into the core.

Expected discovery areas include modules, themes, catalogue, categories, CMS pages, multilingual configuration, SEO metadata, routing, orders / commerce capabilities and installed connectors.

## 5. Remote control

Site Manager exposes normalized capabilities to remote clients through stable contracts. MCP is an operator interface, not the source of truth for platform behavior.

The target platform adapter remains responsible for platform-specific validation and safe execution.

## 6. Product boundary

Site Manager is not a hosting platform, CMS replacement or arbitrary remote shell. It is a bounded governance layer over existing digital systems.
