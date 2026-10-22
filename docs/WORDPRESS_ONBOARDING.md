# Generic WordPress onboarding contract

## Status

This document defines the repeatable onboarding contract for **any WordPress installation** governed by Site Manager.

It operationalises `ENGINEERING_RULES.md`. EMMake `/nuevaweb`, `eduardoyauriluna.com` and future client sites are acceptance instances of this contract; none of them may become a special case in Site Manager Core.

## Product goal

The same official Site Manager plugin package must be installable on an unrelated WordPress and answer, truthfully:

- what is installed;
- what Site Manager understands;
- what can be read safely;
- what can be changed safely;
- which provider owns each surface;
- which scopes are currently authorised;
- which surfaces remain gaps/blocked;
- what changed after a governed operation;
- whether rollback is available and verified.

A client WordPress does **not** need GitHub installed or connected. GitHub belongs to our operator/control-plane transport, outside the client WordPress.

## Canonical managed path

```text
User
→ ChatGPT
→ GitHub connector
→ Command Gateway
→ production MCP for the selected logical site
→ Site Manager
→ WordPress
→ result / verify / rollback
→ sanitized GitHub result
→ ChatGPT
→ User
```

Normal operation never requires the client to use SSH, WP-CLI, a developer terminal, GitHub or direct database access.

## Phase 1 — Install and discover first

1. Install the exact current official Site Manager ZIP.
2. Activate the plugin.
3. Do **not** grant remote write scopes yet.
4. Let Site Manager discover the live installation.
5. Review the capability graph before making assumptions.

Discovery must identify the real installation, including where relevant:

- WordPress/PHP/multisite/locale/runtime;
- active theme;
- public post types and taxonomies;
- installed/active plugins;
- WordPress Core-owned content surfaces;
- classic/block navigation ownership;
- known SEO providers;
- known multilingual providers;
- WooCommerce;
- Elementor;
- ACF;
- known compatible theme/provider adapters;
- unsupported providers/gaps.

The absence of Migration Bridge metadata is normal for direct onboarding.

## Phase 2 — Capability classification

Every surface follows this progression:

```text
detected
→ understood
→ governable
→ authorized
```

Rules:

- `detected` never implies write authority;
- unsupported providers stay visible as gaps;
- Site Manager must not fall back to arbitrary meta/database/REST writes;
- provider ownership must be resolved before a provider-specific mutation;
- site-specific behavior belongs in reusable adapters/contracts, never Core conditionals for a domain/customer.

## Phase 3 — Create the scoped connection

In WordPress Admin open:

```text
Site Manager → Connection
```

The Connection screen exposes the installation's real REST base, connection id, current scopes, MCP presence and token lifecycle.

For the first connection:

1. begin read-only;
2. select only `site.read` and `site.diagnostics` when they are offered as governable;
3. generate the connection token;
4. copy the token immediately to the external protected secret store;
5. never paste the token into ordinary chat or commit it to Git.

Site Manager shows the plaintext token only once and stores only a hash in WordPress. Rotating the token invalidates the previous token immediately.

Write scopes are never selected automatically.

## Phase 4 — Register the logical site in the operator control plane

Each WordPress gets a stable logical id such as:

```text
example-site
```

The protected GitHub Environment is:

```text
site-manager-example-site
```

Environment variables:

- `SITE_MANAGER_SITE_ID=example-site`
- `SITE_MANAGER_REST_BASE=<Site Manager REST base shown by the plugin>`
- `SITE_MANAGER_CONNECTION_ID=<connection id shown by the plugin>`
- `SITE_MANAGER_MCP_BASE_URL=<bounded production MCP URL assigned to this logical site>`

Environment secret:

- `SITE_MANAGER_MCP_TOKEN=<one-time Site Manager connection token>`

Infrastructure deployment credentials remain protected secrets and never enter command/deployment request files.

The logical site id is operator infrastructure metadata; it must not change Site Manager Core behavior.

## Phase 5 — Deploy production MCP through the governed deployment transport

Production MCP deployment is requested through:

```text
deployments/site-manager-mcp/pending/<deployment_id>.json
```

The request contains only:

- deployment id;
- logical site id;
- deploy/rollback action;
- immutable MCP image digest when deploying;
- requester identity.

It contains no token, REST/MCP URL, connection id, SSH data, tool name or arbitrary command.

GitHub resolves the protected site environment, deploys the isolated runtime and persists a sanitized result to:

```text
deployments/site-manager-mcp/results/<deployment_id>.json
```

Each site's production runtime uses a distinct Compose project and edge alias:

```text
site-manager-mcp-<site>
```

## Phase 6 — Read-only round-trip acceptance

Before any remote write scope is granted, prove:

```text
ChatGPT
→ GitHub command
→ Command Gateway
→ production MCP
→ Site Manager
→ real WordPress state
→ sanitized result
→ GitHub
→ ChatGPT
```

Minimum commands:

- `site.read`
- `site.diagnostics`

Acceptance requires:

- authenticated MCP traffic reaches the intended Site Manager connection;
- Site Manager reports MCP presence;
- returned data reflects the real WordPress installation;
- no write scopes are required for the read-only proof;
- exact successful command replay causes no second MCP execution;
- another logical site's credential cannot be substituted implicitly.

## Phase 7 — Authorise writes only after discovery review

Only grant a write scope when its underlying capability is `governable` on this installation.

Examples:

- `content.write`
- `navigation.write`
- `seo.write`

A granted write scope still does not permit arbitrary mutation. The corresponding adapter/provider contract must exist and every material operation must use:

```text
plan
→ preview / validation
→ explicit confirmation / authorization
→ apply
→ verify
→ rollback when supported
→ verify restoration when rolled back
```

If a provider is detected but no safe adapter exists, Site Manager must keep that surface blocked.

## Phase 8 — WordPress acceptance before normal use

For a newly connected WordPress, complete at least:

1. read-only discovery/diagnostics round trip;
2. capability/ownership review;
3. one disposable WordPress Core content plan/apply/verify/rollback when authorised;
4. navigation lifecycle only if a governable navigation provider exists;
5. SEO lifecycle only if a governable SEO owner/provider exists;
6. stale-state protection proof;
7. audit/result proof;
8. rollback proof where technically reversible.

Do not add Core special cases merely to make an acceptance site pass.

## Adding support for a new WordPress stack

When an unknown site exposes a surface Site Manager cannot govern:

1. record it as a capability gap;
2. determine the actual owner/provider and stable public/verified integration contract;
3. implement a reusable provider adapter;
4. add provider-specific contract tests;
5. prove it on the original site;
6. prove the adapter does not affect sites where that provider is absent.

Never solve a client-specific gap by checking hostname, customer name, database id or a one-off plugin combination inside Core.

## Offboarding / emergency control

The WordPress administrator remains authoritative over remote access.

From `Site Manager → Connection` an administrator can:

- change granted scopes;
- rotate the token;
- revoke the connection.

Revocation must immediately make the remote credential unusable while leaving local Site Manager/WordPress administration functional.

The production MCP runtime and operator environment can then be removed independently without changing the WordPress content source of truth.

## Portability success criterion

Site Manager is ready for broad WordPress rollout only when the **same official plugin package and the same control-plane contracts** can govern:

1. the controlled EMMake acceptance site;
2. the Research Manager parity site through adapters rather than Core contamination;
3. a genuinely unrelated WordPress installation with a different theme/plugin/provider stack.

No site-specific Core logic is an acceptable prerequisite for that success.
