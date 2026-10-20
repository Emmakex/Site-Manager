# Roadmap

## SM-0 — Repository foundation

- Product-neutral naming.
- Core / adapters / MCP boundaries documented.
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

## SM-3 — WordPress provider adapters

Add specialized adapters independently:

- SEO providers
- multilingual providers
- ACF
- Elementor
- WooCommerce
- Research Theme as a compatibility adapter, not as the product core

## SM-4 — MCP bridge

Goal: remote operator sees the real capability graph of each connected installation.

The remote layer must be able to answer:

- what was detected?
- what can be read?
- what can be changed?
- what is blocked and why?
- what scopes are missing?
- what adapter must be added?

## SM-5 — WordPress acceptance

Use controlled environments first. No client rollout until generic WordPress discovery, governance guards, verification and rollback have passed end-to-end acceptance.

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

## SM-7 — Platform SDK

Formalize adapter interfaces and test harnesses so additional platforms can be added without modifying core behavior.

Candidates after WordPress and PrestaShop can include custom applications, headless CMSs and commerce systems.
