# Brownfield Engineering Rule — Govern Existing WordPress Sites

## Status

**Mandatory repository-wide engineering rule.**

This rule supplements `ENGINEERING_RULES.md` and defines the architectural meaning of the Brownfield track developed in `Emmakex/Site-Manager`.

If a historical roadmap, acceptance shortcut, customer-specific workaround or old architecture note conflicts with this Brownfield rule, this rule applies unless Eduardo explicitly approves a later architecture decision.

## 1. Canonical Brownfield definition

Site Manager develops the **Brownfield** approach to governed WordPress:

> Start from an existing WordPress installation that we did not design, discover what is really there, understand ownership and providers, expose only truthful capabilities, and progressively govern supported surfaces without adding customer-specific behavior to Core.

Brownfield assumes uncertainty at the start.

The site may already contain arbitrary combinations of themes, builders, plugins, custom fields, multilingual systems, ecommerce, SEO stacks, legacy conventions and technical debt.

## 2. Brownfield product assumptions

A Brownfield site may have been created by another agency, developer, theme vendor or the customer themselves.

Site Manager must therefore discover and classify, as applicable:

- WordPress runtime and configuration;
- active theme and child theme;
- page builder or block architecture;
- pages, posts and custom post types;
- options, metadata and custom fields;
- menus and navigation providers;
- SEO provider and ownership;
- multilingual provider and language model;
- WooCommerce or other business plugins;
- media behavior;
- routing and permalink conventions;
- indexability and public endpoints;
- capabilities already available through reusable adapters.

No detected technology automatically grants mutation authority.

## 3. Discovery precedes governance

The preferred Brownfield progression is:

```text
unknown site
→ discover
→ classify providers and ownership
→ understand supported surfaces
→ expose capabilities
→ authorize bounded operations
→ Plan / Preview
→ Apply
→ Verify
→ Rollback when applicable
```

Capability states should distinguish at least:

```text
detected
→ understood
→ governable
→ authorized
```

Unsupported or ambiguous behavior must remain visible as a capability gap and fail closed.

## 4. Core must remain customer-agnostic

No client acceptance site may cause hard-coded business or installation behavior to enter Site Manager Core.

Do not hardcode in Core:

- customer names;
- domains;
- page/post/menu IDs;
- one customer's theme slug;
- one customer's plugin configuration;
- one customer's field names unless represented through a reusable adapter/schema;
- one customer's business taxonomy;
- one customer's legacy URL assumptions.

Customer-specific behavior belongs in explicit configuration, discovered state, or reusable adapters/providers.

## 5. Adapters are the Brownfield specialization mechanism

Brownfield does not mean unrestricted generic writes.

Site Manager should gain depth through reusable adapters that understand concrete providers while keeping Core generic.

Examples include:

- WordPress core content adapter;
- theme/provider adapters;
- Gutenberg adapter;
- Elementor adapter;
- ACF adapter;
- WooCommerce adapter;
- Yoast adapter;
- Rank Math adapter;
- AIOSEO adapter;
- WPML/Polylang or other multilingual adapters;
- Research compatibility adapter where needed.

Each adapter must declare what it owns, what it can read, what it can write, required scopes, validation rules, verification semantics and rollback boundaries.

## 6. Site Manager must adapt; the customer site must not be forced to become Greenfield

Do not require an existing customer website to adopt the architecture used by a site we built from zero merely so Site Manager can govern it.

Where safe governance requires remediation, propose bounded, explicit remediation with preview and verification.

The normal goal is:

```text
understand existing architecture
→ govern it safely
```

not:

```text
replace existing architecture with our Greenfield architecture first
```

A rebuild may be a separate commercial/engineering decision, but it is not a prerequisite for Brownfield Site Manager.

## 7. Relationship with the Greenfield track

The Greenfield implementation in `Emmakex/eduardoyauriluna-research` and this Brownfield implementation are deliberately independent engineering tracks.

Greenfield asks:

> **How should we build WordPress from zero if we want excellent performance, SEO/GEO and safe AI governance from the first day?**

Brownfield asks:

> **How can we safely discover, understand and govern a WordPress site that already exists and that we did not design?**

Site Manager must not force the Greenfield repository to become generic, and the Greenfield implementation must not define Site Manager Core behavior.

## 8. What Brownfield should learn later from Greenfield

After both tracks have sufficient production evidence, Site Manager may adopt proven patterns from Greenfield where they improve the generic product, such as:

- richer semantic contracts;
- stronger mutation lifecycles;
- evidence-sensitive operations;
- better verification strategies;
- safer rollback patterns;
- clearer capability reporting;
- better ChatGPT-oriented business actions.

Adoption must be generalized properly rather than copied as Eduardo-specific or Research-specific code.

## 9. What Greenfield may learn later from Brownfield

Likewise, future convergence may reuse proven Brownfield patterns such as:

- runtime discovery;
- provider ownership resolution;
- capability negotiation;
- adapter registries;
- heterogeneous plugin/theme support;
- portable connection/bootstrap flows.

This comparison should happen only after independent real-world evidence exists.

## 10. Brownfield acceptance principle

The Brownfield product is strongest when the **same official Site Manager package** can be installed on unrelated existing WordPress sites and truthfully adapt to what each site contains without Core site-specific patches.

A successful acceptance site validates reusable discovery and adapters; it must not redefine Core around that customer's architecture.

## 11. Final engineering test

Before implementing a Site Manager capability, ask:

> **Does this help us discover, understand or safely govern an existing WordPress site without assuming that we built it?**

If yes, it belongs in the Brownfield track.

If it only works because we already know and control the site's architecture from creation time, it probably belongs to the Greenfield track instead.
