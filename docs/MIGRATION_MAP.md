# SM-0 migration map

This document defines what is extracted from the previous Research Manager implementation and, equally importantly, what must stay outside the new platform-neutral Core.

## Migration rule

We migrate **behaviour and contracts**, not legacy naming or site-specific assumptions.

| Previous responsibility | Site Manager destination | Migration decision |
| --- | --- | --- |
| Site baseline / environment inspection | `adapters/<platform>/discovery` + normalized `DiscoverySnapshot` | Reimplement behind adapter contract. Platform calls never enter Core. |
| Site/product profile detection | Platform adapter `detect()` | Reimplement per platform. |
| Capability exposure | `Capability` + `CapabilityGraph` | Extract as neutral model. |
| Semantic scope checks | `GovernancePolicy` | Extract as neutral policy. |
| Preview / plan identifiers | `PlanFactory` + adapter `plan()` | Extract stable-plan concept; adapter owns platform-specific diff. |
| Snapshots | `SnapshotStore` contract | Extract contract; persistence implementation belongs to runtime/integration layer. |
| Apply | `PlatformAdapter::apply()` | Adapter-specific execution only after governance gate. |
| Rendered/post-state verification | `PlatformAdapter::verify()` | Keep verification mandatory, implementation adapter-specific. |
| Rollback | `PlatformAdapter::rollback()` | Keep contract; adapter declares reversibility. |
| Remote audit | `AuditEvent` + `AuditSink` | Extract neutral immutable event contract. |
| Remote credentials / connection scopes | remote/MCP layer + Core governance inputs | Do not couple credential storage to Core. Core receives granted scopes. |
| Research Theme page slots and contracts | WordPress `research-theme` compatibility adapter | Do not migrate into Core. |
| Research identity, lines, insights, evidence and blueprint | Research-specific compatibility adapter/application | Do not migrate into Core. |
| WordPress admin menus and REST routes | WordPress plugin adapter | Do not migrate into Core. |

## Core invariants established by SM-0

1. Core source contains no WordPress, PrestaShop or CMS runtime calls.
2. Core technical identifiers contain no commercial brand or personal/project legacy naming.
3. Detection, understanding, governance and authorization remain separate states.
4. Detection never implies write permission.
5. A write operation must have a declared capability, operation, scopes and confirmation policy before execution.
6. Plans have deterministic identifiers derived from canonical change input, not from timestamps.
7. Verification is part of the adapter lifecycle rather than an optional UI concern.
8. Rollback is explicit and only advertised when the adapter can support it safely.

## First extraction slice

SM-0 intentionally starts with the smallest reusable contracts:

- `PlatformAdapter`
- `PlatformIdentity`
- `DiscoverySnapshot`
- `Capability` / `CapabilityGraph`
- `OperationRequest`
- `GovernancePolicy`
- `Plan` / `PlanFactory`
- `ExecutionResult`
- `VerificationResult`
- `RollbackResult`
- `SnapshotStore`
- `AuditEvent` / `AuditSink`

The first concrete implementation of these contracts will be the WordPress adapter in SM-1. PrestaShop must later implement the same contracts without changes to Core; that is the portability proof.

## Not migrated on purpose

The new repository must not become a copy of the research project. Site-specific blueprint data, identity data, content taxonomies, research resources, presentation slots and product branding are excluded from Core. They can be supported through adapters when a target installation actually exposes them.
