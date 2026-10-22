# Site Manager

Site Manager is a platform-neutral control plane for discovering, understanding and safely governing websites and digital platforms.

The product is intentionally **not tied to one CMS, one theme, one vendor or one commercial brand**. WordPress is the first supported platform because it is our current acceptance environment, but the architecture is designed from day one to add adapters for PrestaShop and other systems without rewriting the core.

## Canonical engineering rule

All product and operator work must follow [`ENGINEERING_RULES.md`](ENGINEERING_RULES.md). If another issue, roadmap note, runbook or historical implementation conflicts with that rule, the engineering rule wins unless an explicit replacement architecture decision is approved.

For normal ChatGPT-governed WordPress operation, the canonical transport is:

```text
User
→ ChatGPT
→ GitHub connector
→ GitHub Actions / Command Gateway
→ production MCP
→ Site Manager
→ WordPress
→ read / plan / apply / verify / rollback as required
→ sanitized result
→ GitHub
→ ChatGPT
→ User
```

Infrastructure such as persistent MCP runtimes, Docker, SSH and WP-CLI may support deployment, recovery and maintenance, but must not redefine this normal operator path or introduce per-command manual terminal steps.

Detailed Command Gateway contract and per-site environment model: [`docs/COMMAND_GATEWAY.md`](docs/COMMAND_GATEWAY.md).

## Product principle

**Install / connect → discover → classify → expose capabilities → govern safely → verify → audit / rollback**

Site Manager must never assume how a target site is built. Each platform adapter inspects the real installation and reports:

- what exists (`detected`)
- what Site Manager understands (`understood`)
- what can be safely controlled (`governable`)
- what is currently authorized (`connected` / scoped)
- what is still missing (`gaps`)

Detection alone never grants write access.

For WordPress, the portability target is explicit: **the same official Site Manager plugin must be installable on an unrelated WordPress and adapt through discovery plus reusable adapters, without site-specific Core logic**.

## Architecture

```text
Site Manager Core
├── discovery
├── capability graph
├── governance policy
├── plans / approvals
├── snapshots
├── verification
├── audit
└── rollback

Platform adapters
├── WordPress
│   ├── Core
│   ├── theme contracts / compatibility adapters
│   ├── WooCommerce
│   ├── Elementor
│   ├── ACF
│   ├── SEO providers
│   └── multilingual providers
├── PrestaShop
└── future platforms

Remote governance
├── GitHub command transport
├── Command Gateway
├── production MCP bridge
└── stable Site Manager contracts
```

## Engineering rule

Technical names, namespaces, repositories, packages, endpoints and internal services must stay brand-neutral. Commercial branding can change without forcing a core refactor.

Site-specific behavior belongs behind adapters or provider contracts, never in Core. Unsupported surfaces remain explicit capability gaps and must fail closed.

## Current roadmap

### Phase 0 — Product extraction

Extract the reusable Manager logic from the existing research project into this repository, removing research-site-specific and personal naming from the core.

### Phase 1 — WordPress discovery

Build the first production adapter. On installation / activation it must immediately detect the WordPress structure, theme, content types, taxonomies, plugins, routing and known integrations and produce a capability / governance map.

### Phase 2 — Safe WordPress governance

Add explicit adapters for bounded writes with preview → confirmation → apply → verify → rollback. Start with WordPress Core, then SEO / multilingual / builder / commerce providers as separate adapters.

### Phase 3 — MCP control

Expose the discovered capability graph to the MCP bridge so an operator can ask high-level questions such as:

> What can you govern on this site?

and receive a truthful, installation-specific answer.

The MCP bridge remains behind the canonical GitHub Command Gateway operator path; it is not an excuse to bypass Site Manager governance.

### Phase 4 — WordPress acceptance lab

Validate first against controlled WordPress environments before using the product on client sites.

### Phase 5 — PrestaShop adapter

Reuse the same core contracts for discovery, capabilities, governance, verification and audit while implementing PrestaShop-specific adapters.

### Phase 6 — Additional platforms

Extend through adapters rather than forks of the core.

## Non-goals

- No arbitrary remote REST proxy.
- No inferred write permissions just because a technology was detected.
- No platform-specific assumptions inside the core.
- No requirement for client sites to use GitHub.
- No normal operating dependency on SSH, local Docker or developer terminals.
- No site/domain/customer IDs hard-coded into Core.
