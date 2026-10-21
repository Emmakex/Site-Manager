# Site Manager — Global Engineering Rules

## Status

**Mandatory repository-wide engineering rule.**

This document is the canonical operating and architecture rule for Site Manager. If an issue, roadmap note, implementation detail, acceptance fixture, deployment runbook or historical decision conflicts with this document, **this document wins** unless an explicit architecture decision approved by the product owner replaces it.

Site Manager exists to let ChatGPT safely govern real websites through bounded, auditable capabilities. WordPress is the first complete product target, and the WordPress implementation must remain portable across unrelated installations.

## 1. Canonical operator flow

The normal managed flow from this ChatGPT conversation is:

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

This is the canonical control path for remotely governing a WordPress site with Site Manager.

The operator must not need to keep a developer terminal, SSH session, local Docker process or WordPress Admin screen open for normal managed operations.

Direct `ChatGPT → MCP`, direct REST calls, WP-CLI or SSH may exist as development, bootstrap, recovery or future transports, but they must not silently replace the canonical managed flow above.

## 2. Governance lifecycle

Read-only operations may execute after authentication, scope validation and capability validation:

```text
REQUEST
→ VALIDATE
→ READ REAL WORDPRESS STATE
→ RETURN SANITIZED RESULT
```

Material mutations must follow the bounded lifecycle:

```text
REQUESTED
→ VALIDATED
→ PLANNED
→ PREVIEWED
→ AUTHORIZED
→ APPLIED
→ VERIFIED
→ COMPLETED
```

When technically reversible, rollback remains part of the operation contract:

```text
PLAN
→ APPLY
→ VERIFY
→ ROLLBACK if requested or required
→ VERIFY RESTORATION
```

Allowed non-success outcomes include:

```text
BLOCKED
SCOPE_DENIED
CAPABILITY_UNAVAILABLE
STALE_STATE
FAILED
ROLLED_BACK
PARTIALLY_VERIFIED
REQUIRES_HUMAN_REVIEW
```

A mutation is not complete merely because an HTTP call, workflow or repository commit succeeded. Completion requires verification of the real target state.

## 3. Product mission: govern any WordPress through discovery and adapters

The WordPress product target is:

> **Install the same official Site Manager plugin on an unknown WordPress installation, discover what is really there, expose only truthful capabilities, and govern every supported surface without adding site-specific logic to Core.**

Site Manager must not assume:

- a specific theme;
- a specific page builder;
- a specific SEO plugin;
- a specific multilingual plugin;
- WooCommerce;
- ACF;
- Research Manager;
- a migration history;
- GitHub on the client site;
- a particular hosting provider;
- a particular business, domain or brand.

The same plugin package must adapt through runtime discovery and reusable adapters.

## 4. Discovery never implies authority

Every relevant surface must move through explicit capability states such as:

```text
detected
→ understood
→ governable
→ authorized
```

A detected technology is not automatically writable.

If Site Manager recognizes a provider but does not have a safe adapter for it, the correct state is a visible capability gap, not an inferred write path.

Unknown or unsupported surfaces must fail closed.

## 5. WordPress and Site Manager responsibility boundary

WordPress remains the runtime and content source of truth.

Site Manager is the governance source of truth for:

- discovery;
- capability ownership;
- provider ownership;
- scopes;
- planning;
- mutation policy;
- verification;
- rollback boundaries;
- audit metadata.

The MCP layer is a bounded protocol/operator adapter. It does not decide WordPress ownership rules and must not bypass Site Manager.

The Command Gateway is the trusted orchestration boundary between GitHub commands and production MCP. It translates a deliberately small business-action surface into already accepted Site Manager MCP operations.

## 6. GitHub is transport and engineering history, not WordPress content staging

GitHub has two valid roles.

### 6.1 Governed command transport

For ChatGPT-managed operations:

```text
ChatGPT
→ bounded command in GitHub
→ Command Gateway
→ production MCP
→ Site Manager
→ WordPress
→ sanitized result back to GitHub
→ ChatGPT
```

Command files may express business intent and structured payloads, but must never contain:

- bearer tokens or credentials;
- arbitrary backend URLs;
- arbitrary REST paths;
- MCP tool names chosen by the caller;
- PHP;
- SQL;
- shell commands;
- filesystem instructions.

### 6.2 Product engineering

GitHub stores and reviews:

- Site Manager code;
- adapters;
- MCP bridge code;
- Command Gateway code;
- schemas and contracts;
- tests;
- CI/CD;
- architecture and engineering documentation.

Normal WordPress content must not use the repository as an editorial staging source. A commit is not evidence that a WordPress content change is live.

## 7. ChatGPT is the primary managed operating surface

The intended user experience is high-level operation from conversation.

ChatGPT should be able to ask Site Manager, through the canonical transport:

- what exists on this WordPress installation;
- what can be read;
- what can be governed;
- what is blocked;
- what provider owns a field or surface;
- what scope is missing;
- what would change before applying a mutation;
- whether the mutation was verified;
- whether rollback is available or succeeded.

The user should not have to understand internal WordPress storage, plugin metadata keys or provider-specific APIs to perform an ordinary supported operation.

## 8. Command Gateway contract

ChatGPT submits typed business commands to a trusted GitHub namespace. A command must contain identity and intent, not transport internals.

The gateway must:

1. accept exactly the trusted command namespace;
2. validate a strict schema with unknown fields rejected;
3. resolve only registered sites/connections;
4. resolve only registered business actions;
5. prevent command-id collisions and ambiguous replay;
6. make successful replay idempotent;
7. use production endpoint and credentials from protected environment configuration, never from the command;
8. execute only the registered MCP lifecycle;
9. persist only sanitized non-secret results;
10. fail closed on scope, capability, transport, verification or isolation errors.

The first Site Manager business actions are read-only:

```text
site.read
site.diagnostics
```

Governed write actions follow only after the read round trip is accepted, and must reuse Site Manager's existing bounded contracts rather than inventing alternate write paths.

Initial write families are expected to include:

```text
content.update
navigation.update
seo.update
```

Each write family must resolve internally to Site Manager plan / apply / verify / rollback operations according to its capability and scope contract.

## 9. No arbitrary remote execution

Site Manager must never become:

- an arbitrary REST proxy;
- a remote PHP executor;
- a SQL executor;
- a filesystem browser/editor;
- a shell gateway;
- an unrestricted WordPress option/meta mutation endpoint.

Every writable field or operation must have explicit ownership, validation, scope and adapter support.

## 10. Site-specific behavior belongs in adapters, never Core

No production acceptance site may cause hard-coded business/domain behavior to enter Site Manager Core.

Examples:

- Research-specific behavior belongs behind a Research compatibility/provider adapter.
- Theme-specific behavior belongs behind a theme/provider adapter.
- Yoast, Rank Math and AIOSEO behavior belongs behind independent SEO provider adapters.
- Elementor, ACF, WooCommerce and multilingual behavior belongs behind their own adapters.

If an unknown WordPress requires new support, add or improve a reusable adapter or capability contract. Do not special-case its domain, post IDs, menu IDs, theme name or customer identity in Core.

## 11. Secrets and identity

Production credentials must be:

- site/connection specific;
- revocable;
- rotatable;
- scoped;
- stored only in appropriate protected runtime/environment storage;
- absent from Git command payloads and sanitized results.

The WordPress connection remains the authority for granted Site Manager scopes.

Remote automation must preserve connection/site isolation and must fail closed if the credential is valid for the wrong site or tenant.

## 12. Stale-state and idempotency protection

A plan must be bound to the relevant site, connection, capability, operation and target fingerprint/revision.

If the target changes between planning and apply, Site Manager must reject the stale plan and require a new read/plan cycle.

A command identifier may correspond to one canonical payload only. Reusing the identifier with different intent must fail closed. Replaying an already successful identical command must not execute a second mutation.

## 13. Verification and evidence rule

The authoritative evidence for a normal managed operation is:

```text
actual WordPress state
→ Site Manager verification
→ MCP result
→ Command Gateway sanitized result
→ GitHub readback
→ ChatGPT report
```

Never infer completion from:

- a successful code merge;
- green CI alone;
- a 200 response alone;
- a queued command alone;
- a plan alone;
- an apply call without verification.

## 14. WordPress portability acceptance

The WordPress product is not considered ready for broad client rollout until the same official package proves all of the following without Core site-specific changes:

1. generic WordPress functionality is complete;
2. EMMake `/nuevaweb` passes the full managed flow;
3. `eduardoyauriluna.com` passes Research Manager → Site Manager parity through adapters;
4. a genuinely different WordPress installation passes discovery and governed operations with the same package.

Acceptance sites validate the product; they do not define bespoke Core behavior.

## 15. Infrastructure is subordinate to the operator flow

Persistent MCP processes, containers, reverse proxies, runners, deployment hosts and secrets are implementation infrastructure.

They may be required to keep production services available, but they must remain behind the canonical operator flow:

```text
ChatGPT → GitHub → Command Gateway → production MCP → Site Manager → WordPress
```

Infrastructure work must not redefine the normal user path or introduce per-command manual terminal steps.

## 16. Future platforms must preserve the same core contract

WordPress is the first complete implementation. PrestaShop and future platforms may add their own adapters, but the normalized Site Manager lifecycle remains:

```text
discover
→ classify
→ expose capabilities
→ govern
→ verify
→ audit / rollback
```

Platform-specific behavior must not contaminate Core.

## 17. Rule for future engineering work

Before implementing a Site Manager change, ask:

1. Does it preserve the canonical ChatGPT → GitHub → Command Gateway → MCP → Site Manager → target flow?
2. Does it work through a bounded capability rather than an arbitrary transport escape hatch?
3. Is the real target system still the source of truth?
4. Does it keep unsupported behavior visible as a gap rather than guessing?
5. Is it generic or isolated behind a reusable adapter?
6. Can the result be verified and audited?
7. Would the same official plugin still behave correctly on an unrelated WordPress?

If the answer to any applicable question is no, the implementation must be redesigned or explicitly approved as a new architecture decision before proceeding.
