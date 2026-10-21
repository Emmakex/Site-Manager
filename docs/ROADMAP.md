# Roadmap

## Canonical engineering constraint

All roadmap execution is subordinate to [`../ENGINEERING_RULES.md`](../ENGINEERING_RULES.md).

For ChatGPT-governed WordPress operation, the canonical production transport is:

```text
User
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP
→ Site Manager
→ WordPress
→ verified sanitized result
→ GitHub
→ ChatGPT
```

Do not introduce a parallel normal operating path through SSH, local Docker, direct REST or ad-hoc WP-CLI. Those transports remain available for engineering, bootstrap and recovery only.

## Product entry modes

Site Manager supports two first-class onboarding paths. Migration is optional and must never be a prerequisite for governance.

### Mode A — Rebuild / migration-assisted onboarding

Use when a client wants to renew or rebuild the website.

1. Scan the legacy site.
2. Clone or create a clean target installation.
3. Rescue only the useful URLs, SEO signals, content, internal links, media and redirect intent.
4. Reset / rebuild on the chosen target stack or theme.
5. Emit an optional neutral migration handoff manifest.
6. Install Site Manager on the rebuilt site.
7. Site Manager discovers the resulting installation, validates the handoff when present, and becomes the permanent control plane.

The theme or migration tooling used during the rebuild is not a Site Manager dependency.

### Mode B — Direct Site Manager onboarding

Use when the client keeps the existing website.

1. Install Site Manager directly on the existing WordPress / future supported platform.
2. Discover the installation without reset, clone, migration or theme replacement.
3. Build the capability and ownership map from the live structure.
4. Add only the adapters required by what is actually detected.
5. Govern the existing installation in place with the same planning, verification, rollback and audit contracts.

The absence of a migration handoff is a normal state and must not reduce ordinary Site Manager discovery or governance.

## SM-0 — Repository foundation

- Product-neutral naming.
- Core / adapters / remote-governance boundaries documented.
- Canonical engineering rules documented and enforced as architecture authority.
- CI and contract tests.
- Migration inventory from the existing Research Manager implementation.

## SM-1 — WordPress portable discovery

Goal: install the plugin on an unknown WordPress and immediately understand the installation without manual setup.

Acceptance:

- detect WordPress / PHP / multisite / locale / routing
- detect active theme and theme capabilities
- enumerate public post types and taxonomies
- enumerate active plugins
- detect known providers and integrations
- persist a versioned site fingerprint
- automatically refresh after relevant structural changes
- produce `detected / governable / gaps`
- require no customer/domain-specific Core code

## SM-2 — WordPress Core adapter

Goal: govern generic WordPress without depending on a custom theme.

Initial bounded surfaces:

- pages and posts
- publication state
- title / slug / content where safely owned by WordPress Core
- media metadata
- navigation where supported
- site identity and selected settings

Every write must support plan, policy check, apply, verify and rollback when technically reversible.

### SM-2C — Optional migration handoff

When Mode A was used, Site Manager may consume a neutral, versioned migration handoff manifest to validate provenance and preserved surfaces. The manifest is optional, never grants authorization and must not be required for Mode B.

## SM-3 — WordPress provider adapters

Add specialized adapters independently:

- SEO providers
- multilingual providers
- ACF
- Elementor
- WooCommerce
- Research Theme / Research Manager behavior as compatibility adapters, not as product Core

A custom rebuild theme may have its own provider adapter, but Site Manager Core must not depend on that theme.

## SM-4 — Remote governance

Goal: a remote operator sees and governs the real capability graph of each connected installation through the canonical production path.

### SM-4A to SM-4E — Site connection and bounded MCP

Provide site-specific authentication/scopes, read-only Site Manager MCP resources, governed content/navigation/SEO write lifecycles, remote acceptance, diagnostics and production runtime reliability.

MCP remains a bounded adapter behind Site Manager governance. Infrastructure persistence is required for availability but must not become the primary operator interface.

### SM-4F — GitHub Command Gateway for ChatGPT governance

Goal: make the accepted Research Manager governance pattern generic for Site Manager:

```text
ChatGPT
→ GitHub command
→ Command Gateway
→ production MCP
→ Site Manager
→ WordPress
→ sanitized result to GitHub
→ ChatGPT
```

First acceptance slice:

1. register an acceptance WordPress/site connection;
2. submit one strict `site.read` business command through the trusted GitHub namespace;
3. Gateway validates schema, command identity, site registration and replay;
4. Gateway calls the production MCP using protected environment configuration;
5. MCP calls Site Manager;
6. Site Manager reads the real WordPress installation;
7. sanitized result is persisted in GitHub;
8. ChatGPT reads and reports that result;
9. exact successful replay does not execute a second operation;
10. no SSH, terminal, local Docker or direct WordPress Admin step is required for the managed round trip.

After read-only acceptance, add closed business-action families for governed writes such as `content.update`, `navigation.update` and `seo.update`, reusing existing Site Manager plan/apply/verify/rollback contracts.

## SM-5 — WordPress acceptance

Site Manager must be functionally complete as a generic WordPress control plane before final acceptance begins. Do not use acceptance sites to drive site-specific Core logic.

Definitive acceptance order (do not reorder):

1. **Finish Site Manager for generic WordPress first.** Complete discovery, Core governance, provider ownership/adapters, optional migration handoff, canonical Command Gateway/MCP authorization path, audit, verification, stale-plan protection and rollback before treating any site as final acceptance.
2. **EMMake `/nuevaweb` — first acceptance.** Complete end-to-end acceptance on the controlled Mode A rebuild. This proves the rebuild/migration-assisted path and our SEO/GEO Starter integration without making that theme a Core dependency.
3. **`eduardoyauriluna.com` — second acceptance.** Use the original Research Manager environment as a demanding regression/parity target. Site Manager + adapters must reproduce the required governance without moving Research/site-specific behavior into Core.
4. **A genuinely different WordPress — final acceptance.** Install the exact same finished package on a site with a different theme/plugin/provider stack, no Research Manager history and no Site Manager preparation. This is the final proof of portability and adaptability.

No client rollout until all four stages are complete.

## SM-6 — PrestaShop adapter

Goal: prove the core is genuinely platform-neutral.

Implement PrestaShop-specific discovery and adapters while keeping the same normalized capability, plan, audit and verification contracts.

Suggested first surfaces:

- catalogue and combinations
- categories
- CMS pages
- SEO fields
- languages
- themes / modules
- configuration inventory

PrestaShop must support the same two entry principles where applicable: migration-assisted onboarding when rebuilding, or direct Site Manager onboarding on an existing store.

## SM-7 — Platform SDK

Formalize adapter interfaces and test harnesses so additional platforms can be added without modifying core behavior.

Candidates after WordPress and PrestaShop can include custom applications, headless CMSs and commerce systems.
