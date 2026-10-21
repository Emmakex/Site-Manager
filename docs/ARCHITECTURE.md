# Site Manager architecture

## 0. Canonical engineering authority

[`../ENGINEERING_RULES.md`](../ENGINEERING_RULES.md) is the mandatory architecture and operating rule for Site Manager. This document explains the structure underneath that rule; it does not override it.

The canonical ChatGPT-governed operator path is:

```text
User
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP
→ Site Manager
→ target platform
→ verified sanitized result
→ GitHub
→ ChatGPT
→ User
```

For WordPress, the target platform in that flow is the real WordPress installation discovered and governed by the Site Manager plugin.

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

No acceptance site may introduce domain-, customer-, theme-instance- or resource-ID-specific behavior into Core. Site-specific behavior must live behind reusable adapters or explicit compatibility/provider contracts.

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

The WordPress portability contract is: install the same official plugin on an unknown WordPress, discover the actual stack, expose truthful capabilities and govern only surfaces owned by WordPress Core or a recognized reusable adapter.

Sub-adapters may provide specialized ownership for:

- WordPress Core
- themes / theme contracts
- WooCommerce
- Elementor
- ACF
- SEO providers
- multilingual providers
- compatibility layers such as Research Manager / Research Theme behavior during migration

An unknown provider remains a capability gap until a safe adapter exists. Detection never grants write authority.

### PrestaShop
Second platform adapter. It must reuse the same normalized core contracts and must not introduce PrestaShop assumptions into the core.

Expected discovery areas include modules, themes, catalogue, categories, CMS pages, multilingual configuration, SEO metadata, routing, orders / commerce capabilities and installed connectors.

## 5. Remote governance layers

Remote operation is intentionally layered.

### ChatGPT
Primary managed operating surface. It expresses intent and reads the verified result.

### GitHub connector
Carries bounded command envelopes and exposes sanitized results back to ChatGPT. GitHub is transport and engineering history, not the WordPress content source of truth.

### Command Gateway
Trusted orchestration boundary. It validates command identity/schema/replay, resolves a registered site and business action, and maps that action to an already accepted MCP lifecycle. The caller does not choose arbitrary MCP tools, URLs or REST paths.

### Production MCP
Bounded protocol adapter. It exposes only the Site Manager tools permitted by the current WordPress connection scopes. MCP is not the source of truth for provider ownership or mutation policy.

### Site Manager
Authoritative governance layer. It owns discovery, capability/provider resolution, plans, stale-state checks, apply, verify, rollback boundaries and audit metadata.

### Target platform
WordPress or another supported runtime remains the actual application/content source of truth.

## 6. Command boundary

A remote command expresses business intent, not implementation escape hatches.

The command layer must never allow the caller to supply arbitrary:

- MCP tool names;
- backend URLs;
- REST routes;
- PHP;
- SQL;
- shell commands;
- filesystem operations.

Registered actions map to closed lifecycles. Initial Site Manager actions are read-only (`site.read`, `site.diagnostics`), followed by governed action families such as `content.update`, `navigation.update` and `seo.update` once their end-to-end command path is accepted.

## 7. Infrastructure boundary

Persistent MCP runtimes, containers, runners, deployment hosts and reverse proxies are infrastructure behind the operator model. They must keep production available, but they must not redefine normal operation into a terminal/SSH workflow.

SSH and WP-CLI remain acceptable for bootstrap, deployment, emergency recovery and diagnostics; they are not normal content/governance dependencies.

## 8. Product boundary

Site Manager is not a hosting platform, CMS replacement or arbitrary remote shell. It is a bounded governance layer over existing digital systems.
