# Site Manager Command Gateway

## Status

SM-4F implements the canonical ChatGPT operator transport defined by `ENGINEERING_RULES.md`.

The Gateway is not an alternative source of governance. Site Manager remains authoritative for discovery, capability ownership, scopes, provider selection, plans, verification and rollback. The Gateway only transports a deliberately small set of typed business actions to production MCP and returns sanitized results to GitHub for ChatGPT readback.

## Canonical path

```text
User
→ ChatGPT
→ GitHub connector
→ commands/site-manager/pending/<command_id>.json
→ GitHub Actions / Site Manager Command Gateway
→ protected GitHub environment for the logical site
→ production MCP
→ Site Manager
→ WordPress
→ sanitized result
→ commands/site-manager/results/<command_id>.json
→ ChatGPT
→ User
```

Normal managed operation does not require SSH, a developer terminal, local Docker, WP-CLI or WordPress Admin to remain open.

## Command contract — v1 slice 1

A command contains only business intent:

```json
{
  "version": "1",
  "command_id": "cmd-site-read-0001",
  "site": "example-site",
  "action": "site.read",
  "requested_by": "chatgpt",
  "payload": {}
}
```

The command must never contain:

- an MCP URL;
- a bearer token or credential;
- an MCP tool name;
- a WordPress REST route;
- PHP, SQL, shell or filesystem instructions;
- arbitrary HTTP destinations.

SM-4F slice 1 exposes only:

- `site.read` → registered MCP tool `site_read` → requires `site.read`;
- `site.diagnostics` → registered MCP tool `site_diagnostics` → requires `site.diagnostics`.

The action-to-tool mapping exists only in `config/site-manager-command-registry.json` and is validated fail-closed.

## Per-site production environments

The workflow resolves the logical `site` first, then executes under this GitHub Environment name:

```text
site-manager-<site>
```

Each connected WordPress therefore receives its own protected environment and its own Site Manager connection credential.

Required environment configuration:

- variable `SITE_MANAGER_SITE_ID` — must exactly equal the command's logical `site`;
- variable `SITE_MANAGER_MCP_BASE_URL` — exact HTTPS production MCP endpoint for that Site Manager connection;
- secret `SITE_MANAGER_MCP_TOKEN` — site-specific bearer for the production MCP / Site Manager connection.

The runner refuses execution when the environment site identity does not match the command. The URL and token are never read from the command file and are never persisted in the result.

This model scales without site-specific Core logic: connecting another WordPress means provisioning another Site Manager connection/MCP runtime and another protected GitHub Environment, not changing Site Manager Core or the command runner.

## Trusted namespaces

```text
commands/site-manager/examples/
commands/site-manager/pending/
commands/site-manager/results/
```

Only exactly one added/modified JSON command under `pending/` is eligible per trusted push. Deletion-only cleanup does not execute MCP.

## Replay and idempotency

Before execution, the Gateway canonicalizes the command and computes a SHA-256 fingerprint.

Rules:

1. no previous result → command is new and may execute;
2. exact command + successful fingerprinted result → accepted as an idempotent replay with no MCP execution;
3. existing failed result → fail closed; use a new `command_id`;
4. same identity with a different fingerprint → fail closed;
5. historical GitHub Actions reruns are rejected.

The fingerprint is persisted only after a sanitized result exists.

## Result contract

Successful read example:

```json
{
  "version": "1",
  "status": "success",
  "site": "example-site",
  "action": "site.read",
  "command_id": "cmd-site-read-0001",
  "command_sha256": "...",
  "verified": true,
  "required_scope": "site.read",
  "gateway_generation": "sm-4f-v1-20261022",
  "data": {}
}
```

Failure results distinguish at least:

- `mcp_refused` — production MCP / Site Manager refused the registered operation;
- `transport_failed` — production MCP transport did not complete;
- `configuration_failed` — environment/site/URL/credential configuration was not safely executable;
- `execution_failed` — unexpected bounded execution failure.

Sensitive-looking keys and bearer material are redacted before persistence. Result files are bounded in size.

## Source-of-truth boundaries

- **ChatGPT** — intent and orchestration.
- **GitHub connector** — submits/readbacks governed command/result files.
- **Command Gateway** — trusted schema/action/replay/transport boundary.
- **MCP** — scoped protocol adapter.
- **Site Manager** — authoritative governance and capability layer.
- **WordPress** — live runtime/content state.

A successful GitHub workflow is not proof that WordPress changed. For read operations the returned data must come from the real Site Manager/WordPress path. Future writes remain incomplete until Site Manager reports successful verification.

## Adding future write actions

Do not expose generic mutation tools. Add one business action at a time to the schema and registry only after the underlying Site Manager capability is accepted.

Planned next bounded actions after read-only acceptance:

- `content.update` — staged write family described below;
- `navigation.update` — staged, provider-aware menu governance (this cut).
- `seo.update` — provider-authoritative semantic SEO governance (independent cut).

Each must reuse Site Manager's existing `plan → explicit confirmation → apply → verify → rollback` contracts. The Gateway must never bypass Site Manager by writing WordPress/provider storage directly.

## SM-4F content.update — staged WordPress Core governance

After read-only production acceptance, the first **bounded write family** is
`content.update`, with a required `payload.stage`. It is a business action,
**not** a caller-selected MCP tool or REST route. The trusted registry maps
`plan`, `apply` and `rollback` to the already accepted WordPress Site Manager
MCP operations.

**Plan only** (does not change WordPress):

```json
{
  "version": "1",
  "command_id": "cmd-content-plan-example-01",
  "site": "example-site",
  "action": "content.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "plan",
    "post_id": 123,
    "fields": { "title": "Proposed title" },
    "reason": "Editorial review"
  }
}
```

The plan phase is allowed to request only owned native `title`, `slug`,
`content`, `excerpt` or `status` (with bounded sizes and status allowlist).
It requires `content.write` scope even to plan. A successful plan returns
`status=success`, `verified=false`, a WordPress-generated `plan_id`, target,
changed field names and confirmation requirement. `verified=false` is intentional:
**planning is not an applied/verified mutation**. No old or proposed raw WordPress
content is stored in the sanitized GitHub result; the operator reviews the
explicit proposed change before authorizing a separate command.

**Explicitly approved apply**, in a *different* GitHub command with a new id:

```json
{
  "version": "1",
  "command_id": "cmd-content-apply-example-01",
  "site": "example-site",
  "action": "content.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "apply",
    "plan_id": "sm-plan-0123456789abcdef01234567",
    "confirmed": true
  }
}
```

The operator must obtain **explicit user confirmation of the exact plan**
before generating `confirmed: true`. The Gateway refuses missing, false,
non-boolean, extra keys or unregistered stages. WordPress remains authoritative
for write scope, bound administrator, plan retention/generation, target drift,
capability and verification. `apply` only reports `verified=true` after
WordPress returns `status=verified`, successful execution and
`verification.verified=true`. A successful GitHub job alone never proves apply.

**Explicitly approved rollback**, with another new command id:

```json
{
  "version": "1",
  "command_id": "cmd-content-rollback-example-01",
  "site": "example-site",
  "action": "content.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "rollback",
    "plan_id": "sm-plan-0123456789abcdef01234567",
    "confirmed": true
  }
}
```

Only a rollback with `status=rolled-back` and
`rollback.success=true` is reported as verified restoration. Rollback
remains bound to the original Site Manager plan, connection and retained
snapshot, and fails on drift. Original plan window is 15 minutes; applied
rollback evidence is retained for up to 24 hours by the WordPress plan store.

### Operational safety and activation

- No write scopes are granted by GitHub, MCP, capability discovery or merging
  this code. The WordPress administrator must opt in to `content.write` on
  **Site Manager → Connection**, bind an authorized principal, and replace
  protected deployment credentials after any token rotation.
- MCP tool visibility is loaded from WordPress scopes when the MCP server
  starts. Restart/redeploy it through the existing protected GitHub deployment
  path after a scope change; do not bypass the Gateway.
- Do not automatically generate `apply` after a `plan`; always show and
  authorize the target/change separately.
- A successful exact replay of the same command id is non-executing;
  a different payload or failed command id fails closed.
- The Gateway never accepts arbitrary REST, PHP, SQL, shell, filesystem,
  endpoint URLs, provider-meta keys or user-selected MCP tools.
- A failure or missing verification after a mutation requires checking the
  actual Site Manager/WordPress state before any recovery or fresh request.
- No client site is required to use GitHub: it is the central operator's
  command transport.
- `navigation.update` is supported only through its separate closed, provider-aware
  Gateway cut. `seo.update` is now separately supported through scoped,
  provider-authoritative semantic inputs; existing MCP/WordPress governance
  remains the sole authority on provider ownership.

**Production acceptance gate:** merge only when contract tests pass on the
final PR head. Real content apply/rollback must be exercised solely on a
disposable acceptance target with explicit authorization and verification;
never take a real-site mutation just to prove the gateway is wired.

## Isolated disposable content acceptance (SM-4F)

The `content.acceptance` action runs **only** the WordPress-owned
`mcp-content-only` profile. It does not accept a post ID, draft title,
WordPress REST path or caller-provided code. It requires explicit operator
authorization and valid `site.read` + `content.write` scopes, with a bound
WordPress administrator and the existing connection identity.

```json
{
  "version": "1",
  "command_id": "cmd-content-acceptance-example-01",
  "site": "acceptance-site",
  "action": "content.acceptance",
  "requested_by": "chatgpt",
  "payload": { "confirmed": true }
}
```

On the current WordPress plugin (`>=0.11.4`) and matching MCP image,
the accepted flow is:

```text
precheck → create private draft fixture → content plan
→ confirmed apply + verify → confirmed rollback + verify
→ delete fixture → verify cleanup → report sanitized result
```

It reuses `RemoteAcceptanceService` and the normal content governance
pipeline, not an unrestricted WordPress post creation endpoint. The existing
13-step Acceptance Center sequence is unchanged. Content-only requests
cannot attach to an already active test, whether full-suite or content-only.
The WordPress fixture is always a draft page with known fixed test content.
No navigation or SEO operations are part of this profile.

The Gateway waits for the complete WordPress evidence chain, and only
returns `verified=true` after **all six steps pass**, apply verification,
rollback success, fixture deletion and confirmation that no menu was
created. A successful HTTP response alone never counts as acceptance.
Timeouts/failures must be reconciled with actual WordPress acceptance
state before issuing another command; do not suppress failed cleanup.

Required rollout order: build and install the official 0.11.4 WordPress
plugin package on the acceptance site (backup/standard safe plugin upgrade),
build a new immutable `site-manager-mcp` image, deploy it through the
existing protected OVH Gateway, then execute this command with a **new**
command ID. GitHub commands cannot upgrade WordPress plugins remotely.
**Do not run content-only acceptance against the older 0.11.3 plugin**.

This test is independent of manual `content.update` commands and cannot
be used to select or alter real customer posts. It is strictly for accepted
Site Manager-owned temporary fixtures; the full content creation feature
remains outside this scope.


## SM-4F navigation.update — bounded classic/block menu governance

The `navigation.update` business action is accepted by the central GitHub
Command Gateway only through the existing `navigation.write` scope and
WordPress-authoritative `navigation_update_plan`,
`navigation_update_apply` and `navigation_update_rollback` MCP tools.
No Site Manager plugin or MCP runtime deployment is needed for this cut,
because those exact tools are already present and the write scope remains
explicitly opt-in per WordPress connection.

Supported targets are strictly:

- `wordpress-classic-menu` — **individual existing classic-menu item ID**.
  Allowed fields: `title`, `url`, `description`, `attr_title`,
  `target`, `classes`, `xfn`.
- `wordpress-block-navigation` — **existing `wp_navigation` post ID**.
  Allowed fields: `title`, `content`, `status` (draft/publish).
- No menu creation, menu item deletion, arbitrary menu assignment, plugin
  options or unregistered providers. URL changes are restricted to HTTP(S)
  or site-relative paths. All fields are size-limited; the WordPress
  authority ultimately sanitizes and validates the provider-specific
  request.

The three separate commands use **new command IDs**:

```json
{
  "version": "1",
  "command_id": "cmd-nav-plan-example-01",
  "site": "acceptance-site",
  "action": "navigation.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "plan",
    "provider": "wordpress-classic-menu",
    "target_id": 123,
    "fields": { "title": "Proposed menu label" },
    "reason": "Review the menu item change"
  }
}
```

Planning is **not a mutation**. The Gateway requires WordPress's matching
provider/target ID, the exact proposed field names, a `planned` reversible
plan, `confirmation_required=true`, and an owned before/after preview.
It records only the field names, target, plan ID and lifecycle status;
existing or proposed menu labels/URLs/content are not copied into GitHub
result evidence. A successful plan deliberately has `verified=false`.

```json
{
  "version": "1",
  "command_id": "cmd-nav-apply-example-01",
  "site": "acceptance-site",
  "action": "navigation.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "apply",
    "plan_id": "sm-plan-0123456789abcdef01234567",
    "confirmed": true
  }
}
```

**Do not execute apply automatically after plan.** The user must first
authorize the specific target and proposed change; then a new command may
carry `confirmed=true` with the exact WordPress-generated plan ID.
The Gateway only reports `verified=true` when WordPress returns a verified
apply and successful read-back verification. WordPress rejects plans
outside the connection/generation, wrong ownership, missing scope,
stale target/site or expired 15-minute planning window.

```json
{
  "version": "1",
  "command_id": "cmd-nav-rollback-example-01",
  "site": "acceptance-site",
  "action": "navigation.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "rollback",
    "plan_id": "sm-plan-0123456789abcdef01234567",
    "confirmed": true
  }
}
```

Only WordPress-confirmed `status=rolled-back` with
`rollback.success=true` counts as restored. Its own stale-state protection
still controls whether restoration remains safe. The normal saved-plan
retention and existing GitHub replay guards remain in force. Do not
consider a successful GitHub workflow proof of a WordPress mutation.

**Safety/acceptance:** this PR only extends the Gateway registry,
schema, runner, tests, examples and documentation. It does not alter
WordPress/MCP code, grant write permissions, create temporary menus or
mutate any live menu. A later **navigation-only disposable fixture**
acceptance must be separately designed/authorized, without running
the existing full content+navigation+SEO Acceptance Center on customer
production data. The SEO Gateway remains a separate future cut.


## SM-4F seo.update — provider-authoritative semantic governance

The GitHub Command Gateway supports `seo.update` as a **separate**
business action with the explicitly granted `seo.write` scope. The
existing MCP `seo_update_plan`, `seo_update_apply`,
`seo_update_rollback` tools are fixed by the trusted registry. No
arbitrary plugin meta, WordPress REST routes, endpoints, SQL, PHP or
caller-selected provider adapter can be submitted.

Supported semantic fields (subject to WordPress's actual discovered provider):

- `title` — SEO title (up to 500 characters)
- `description` — SEO description (up to 5000 characters)
- `canonical` — absolute HTTP(S) canonical URL, or empty to clear where supported
- `indexability` — `default`, `index`, `noindex`

**These fields are not universally writable.** WordPress Site Manager's
SeoProviderRegistry selects the actual active owner of SEO metadata and
validates writable inputs. For example, the SEO/GEO Theme adapter
supports semantic title/description through native WordPress fields, and
may reject canonical/indexability changes. Yoast, Rank Math and AIOSEO
have independent provider adapters with their own support limits. The
Gateway neither assumes a provider nor writes storage keys directly.
Theme-derived output may change as a side effect; the preview must be
reviewed before confirmation.

**Plan** (no WordPress changes; unique command ID):

```json
{
  "version": "1",
  "command_id": "cmd-seo-plan-example-01",
  "site": "acceptance-site",
  "action": "seo.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "plan",
    "post_id": 123,
    "fields": {
      "title": "Proposed SEO title",
      "description": "Proposed meta description"
    },
    "reason": "Review SEO improvement"
  }
}
```

The Gateway validates the exact requested semantic field set, WordPress
post target, `planned` status, `reversible`, explicit confirmation
requirement and provider/semantic preview evidence. The GitHub result
contains only plan ID, post ID/type, semantic and storage **field names**,
derived output count and lifecycle status, never the old or new SEO values.
A plan is reported as `status=success`, **`verified=false`**.

**Apply**, after the user explicitly approves the reviewed target/change
(never automatic, always a fresh command ID):

```json
{
  "version": "1",
  "command_id": "cmd-seo-apply-example-01",
  "site": "acceptance-site",
  "action": "seo.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "apply",
    "plan_id": "sm-plan-0123456789abcdef01234567",
    "confirmed": true
  }
}
```

Only `status=verified`, `plan.status=applied`, successful execution
and WordPress provider read-back `verification.verified=true` produce
a verified apply. The WordPress plan binds provider, connection,
generation, site/target fingerprints and stale-state checks.

**Rollback**, independently approved with a separate command ID:

```json
{
  "version": "1",
  "command_id": "cmd-seo-rollback-example-01",
  "site": "acceptance-site",
  "action": "seo.update",
  "requested_by": "chatgpt",
  "payload": {
    "stage": "rollback",
    "plan_id": "sm-plan-0123456789abcdef01234567",
    "confirmed": true
  }
}
```

Only the WordPress-authoritative `rolled-back` response with
`rollback.success=true` qualifies as restored. Expired,
already-used, connection-mismatched or stale plans fail closed.

**Deployment/acceptance:** this cut modifies Gateway registry, schema,
runner, self-tests, examples and docs. It does not require a new WordPress
plugin or MCP image, grant new scopes, apply any SEO change to
`emmake.com/nuevaweb`, or create SEO fixtures. A future SEO-only
disposable acceptance should exercise actual apply/rollback through a
separately authorized, isolated draft, without affecting production SEO,
theme routing, navigation or client content. Existing GitHub idempotent
replay guards apply to all SEO commands.

## SM-4G isolated navigation acceptance — official disposable fixture

The `navigation.acceptance` business action runs the WordPress-owned
`mcp-navigation-only` profile; it **never accepts caller-selected menu
IDs**. It requires `site.read` and the separately granted
`navigation.write` on the bound Site Manager administrator. It does not
require `content.write`, `seo.write`, any extra plugin permissions, or
a full 13-step Acceptance Center run.

```json
{
  "version": "1",
  "command_id": "cmd-nav-acceptance-example-01",
  "site": "acceptance-site",
  "action": "navigation.acceptance",
  "requested_by": "chatgpt",
  "payload": {"confirmed": true}
}
```

The six mandatory steps are `precheck → fixture_navigation_create →
navigation_plan → navigation_apply_verify → navigation_rollback → cleanup`.
WordPress creates an **unassigned** temporary classic menu and one custom
menu item, updates its `attr_title` via the normal Site Manager plan,
verifies the change, rolls back, and deletes the entire fixture.
The fixed path cannot assign the menu to a theme location, alter the
existing site's navigation, create content, or mutate SEO.

The Gateway polls the scoped MCP status and returns `verified=true`
**only if** it sees the exact six-step profile, successful WordPress
apply verification, successful rollback, a genuinely unassigned fixture,
deletion of the menu and item, and no post fixture remaining. Failed
deletion, missing or mismatched plan identity, unverified mutation,
replay/collision, another profile, or a timed-out job fails closed;
reconcile WordPress state before attempting recovery.

The cleanup endpoint verifies the active run identifier and required
`cleanup` step. It requires the **current profile's** write scope:
`navigation.write` for navigation-only, and `content.write` for
the existing content-only/full acceptance. All writes still require
the connection-bound WordPress administrator. Active full, content,
and navigation fixture tests cannot overlap.

**Deployment gate:** the WordPress plugin must be updated to official
**0.11.5**, and the corresponding new immutable MCP image must be
deployed through the protected OVH workflow before executing a
navigation acceptance command. PR/CI integration does not update the
installed WordPress plugin. The profile must only run after a separate,
explicit authorization for a temporary menu; previous content-draft
consent is not authorization for a navigation mutation. No real menu
is changed as part of this engineering cut.

The original 13-step Acceptance Center, content-only acceptance, and
regular `navigation.update` plan/confirmation/rollback contracts
are preserved. SM-4H SEO-only acceptance remains a separate scope.

## SM-4H — isolated provider-authoritative SEO acceptance

The new `seo.acceptance` business action is **not** an arbitrary SEO
editing endpoint. It triggers only the WordPress-owned `mcp-seo-only`
profile on a **temporary unpublished draft created by Site Manager**.
The caller cannot select the post ID, provider, plugin meta key, field
value, REST route, or desired site URL.

```json
{
  "version": "1",
  "command_id": "cmd-seo-acceptance-example-01",
  "site": "acceptance-site",
  "action": "seo.acceptance",
  "requested_by": "chatgpt",
  "payload": { "confirmed": true }
}
```

The sequence is fixed to **six** server-owned steps:

```text
precheck → fixture_content_create → seo_plan →
seo_apply_verify → seo_rollback → cleanup
```

1. The remote WordPress Acceptance Center requires `site.read`,
   `content.write` (draft creation and final deletion) and `seo.write`
   (provider-governed description update); all three must be explicitly
   granted to the connection and tied to an authorized administrator.
2. The SEO plan uses the **actual provider** resolved by
   `SeoProviderRegistry` (e.g. SEO/GEO Theme, Yoast, Rank Math,
   AIOSEO), with the semantic `description` field. The provider can
   refuse unsupported or conflicting ownership. No caller-supplied
   meta key, provider choice, or customer post is accepted.
3. WordPress applies only a confirmed, owned, short-lived plan,
   verifies its read-back, rolls back that exact plan, and verifies
   restoration. A full-suite or parallel acceptance run blocks the
   isolated start.
4. Cleanup permanently deletes **only the newly created draft**.
   The Gateway verifies `post_status=draft`, all six passing phase
   records, matching run/plan IDs, `apply_verified=true`,
   `rollback_success=true`, `draft_deleted=true`, and that no
   menu fixture was created or left behind.
5. Only if every gate passes does the result contain
   `status=success, verified=true`. Any failure, timeout, incomplete
   cleanup, malformed evidence or unexpected profile fails closed.
   Reconcile WordPress state manually before retrying; do not
   automatically create a second fixture after uncertainty.

The GitHub result retains **only bounded sanitized evidence**, such as
the temporary draft ID, plan ID, lifecycle and cleanup statuses; it
does not retain SEO description text, historical values, credentials
or provider-specific metadata. Existing command replay protection
prevents repeat mutations for the same `command_id`.

**Rollout order:** merge exact-head CI-green PR, certify `main`,
build official WordPress plugin **0.11.6**, publish immutable MCP image,
deploy via the protected OVH workflow and verify public `site.read`;
then install 0.11.6 in the WordPress admin and confirm it is active
using the Gateway. The isolated **real-site** SEO acceptance test requires
the user's **separate explicit authorization**. Prior approval for
content or navigation tests does not cover SEO. No commercial page,
published SEO, homepage or navigation changes are part of this cut.

The pre-existing content-only, navigation-only and full 13-stage
Acceptance Center workflows remain unchanged and independently scoped.


## SM-4I — Read-only operator overview (first real-client workflow slice)

The `site.overview` business action is a **small, read-only projection**
of the canonical `site_read` MCP tool. It exists to make Site Manager's
existing, real installation discovery usable from an ordinary ChatGPT
conversation without exposing huge technical inventories as the default
answer. **There is no new WordPress endpoint, MCP tool, scope or deployment
requirement.** The Gateway consumes the existing site-specific protected
Environment and the same `site.read` authorization.

```json
{
  "version": "1",
  "command_id": "cmd-overview-example-0001",
  "site": "acceptance-site",
  "action": "site.overview",
  "requested_by": "chatgpt",
  "payload": {}
}
```

The command only accepts an empty payload. It cannot submit WordPress IDs,
write fields, arbitrary URLs, provider selections, MCP tools or transport
configuration. The Gateway checks the trusted registry's immutable mapping
`site.overview → site_read`, rejects ambiguous/malformed live discovery
and presents a bounded, sanitized view of:

- logical connection ID and real observed homepage, platform, installed
  Site Manager version and theme where discoverable;
- discovery timestamp and fingerprint, so stale reports are distinguishable;
- a capability matrix with `detected / understood / governable / unavailable`,
  provider, required scopes and **number** of blockers (not private text);
- actionable navigation providers and provider-owned target counts;
- discovered SEO authority and **semantic field names** it can write,
  rather than vendor-specific meta keys;
- three possible governed business actions (`content.update`,
  `navigation.update`, `seo.update`) with their required target type
  and explicit confirmation/rollback contracts;
- the real WordPress `search_visibility` flag, including a visible warning
  when search engines are discouraged.

**Important distinction:** `governable=true` does **not** mean the
write scope was granted. The `site_read` MCP response does not provide
current write-scope grants to this action. Therefore the overview
intentionally records `scope_grant_verified=false` for all capabilities
and actions. Any subsequent `plan`, `apply` or `rollback` must be
separately authorized by WordPress's actual connection, provider,
administrator and current target state. It is **not** correct to display
an overview entry as an executable grant of permission.

### Ordinary ChatGPT sequence

1. The user selects or names a *registered logical site* (the GitHub
   Environment `site-manager-<site>` must already exist). The Gateway
   rejects invented site IDs without a matching protected Environment.
   A multi-site client catalog/UI remains a separate roadmap cut; this
   action does not enumerate tenants or expose Environment secrets.
2. ChatGPT submits `site.overview`, reviews the short summary and
   clearly distinguishes detected support from authorization.
3. If a user requests a specific change, ChatGPT uses the existing
   `site.read` discovery evidence for the actual target IDs and sends
   only the appropriate typed `content.update`, `navigation.update`
   or `seo.update` command with `stage=plan`.
4. Before applying, ChatGPT **shows the target and exact requested change
   from the user's intent** and the WordPress plan metadata, including
   reversibility/expiry. Raw before/after WordPress content is *not* saved
   in GitHub; do not claim that the sanitized result contains a full
   value-level preview. If a value-level preview is needed, develop an
   independent, private, capability-governed inspection feature.
5. Only after explicit user confirmation does a new `apply` command
   carry `confirmed=true` with that WordPress plan ID. Verification
   must come from WordPress; rollback requires a separate authorization.
   The normal audit result remains in GitHub.

SM-4I proves only the **read-only, operator-friendly entry point**. It
does not claim to provide client-wide site selection, a full plan-preview
UI, or generalized multi-tenant onboarding. Those must retain the same
site/environment isolation and live WordPress authority in later cuts.

The operator overview is intentionally platform-aware but not
WordPress-only: non-WordPress platforms can return their own
`discovery.platform` and capability graph without masquerading as
WordPress. Unknown owners/providers remain visible as gaps, not
assumed writable adapters.


## SM-4J — verified logical-site catalog and operator selection

The first multi-client operator selection slice is a **proof-backed catalog**,
not a live scan of GitHub Environments or an MCP discovery of unknown sites.

Read `config/site-manager-site-catalog.json` via the connected trusted
GitHub repository and validate its enrolled logical site IDs against their
exact `site.overview` results in `commands/site-manager/results/`.
`python3 scripts/site_manager_site_catalog.py list` produces the
bounded, private-data-free operator view. Each site is shown as
**previously verified**, with its original verification date; its current
connectivity and write privileges are **not** implied.

The Command Gateway resolver refuses unenrolled IDs before selecting
`site-manager-<site>` as its protected GitHub Environment. The runner
revalidates enrollment before calling MCP. Site identity and credentials
remain protected by the actual Environment and WordPress administrator.

After selecting a site, issue a **new `site.overview`** command to
revalidate WordPress's actual state through the existing canonical path.
A catalog listing is never an authorization for `content.update`,
`navigation.update` or `seo.update`.

Only the first EMMake acceptance site currently has an enrolled,
GitHub-persisted, real `site.overview` proof. Other clients are not
listed unless separately registered and verified. Adding new sites
requires separate site bootstrap, protected environment setup and a
reviewed enrollment with a matching trusted proof. The client website
itself never needs to run GitHub.

The complete contract, limitations, enrollment procedure and two-site
security regression are documented in [SITE_CATALOG.md](SITE_CATALOG.md).


## SM-4L — Bounded published-content target selection for EMMAKE delivery

The operator can now use a **separate read-only** business action to
discover IDs of real public WordPress pages and posts before planning any
edit. The action does **not** inspect private drafts and does not read
body text, excerpts, post metadata, passwords or unpublished plans.

```json
{
  "version": "1",
  "command_id": "cmd-content-targets-example-0001",
  "site": "acceptance-site",
  "action": "site.content_targets",
  "requested_by": "chatgpt",
  "payload": {}
}
```

The trusted Gateway maps `site.content_targets` exclusively to the
`site_content_targets` MCP tool under the already-required `site.read`
scope. The MCP client calls only the new fixed WordPress REST
`GET /site-manager/v1/content/targets` endpoint. The endpoint returns
**published** `page` and `post` metadata, capped at 80 pages and 40
posts, including each content ID, public title, slug, site-relative
permalink, modified GMT and homepage marker. If the cap is exceeded,
`pages_truncated` or `posts_truncated` is true, and the operator
must not claim to have inventoried the entire site.

WordPress refuses any target whose status changed to private/draft or
whose generated permalink points outside the current site's HTTPS
base path. The Gateway independently validates the exact public-only
response shape and connection identity, permitted native post type,
published status, URL containment, bounded list lengths, ID uniqueness
and homepage consistency. It persists **only the safe, whitelisted
published metadata** as an auditable, read-only result.

This action **does not authorize editing**. Once a user selects a
published WordPress ID, follow the already-scoped `content.update`
`plan` → separate, explicit `apply` → verify → rollback sequence.
For EMMAKE, homepage ID 7 is known from the live discovery, but the
published content selector must verify that it is actually present
before treating it as an editable target. No source content or original
values are stored in the GitHub result; the separate **private
before/after preview** still needs its own accepted contract.

Deployment: WordPress plugin **0.11.7** and the matching immutable MCP
image must be installed/deployed before a real Gateway
`site.content_targets` can succeed. Adding the Gateway action to
`main` alone is not deployment. The production acceptance is read-only
and requires no permission to mutate any WordPress page.

This is the **first cut** in [EMMAKE delivery #73](https://github.com/Emmakex/Site-Manager/issues/73).
It does not imply finished publication, SEO, navigation, visual UX,
forms, accessibility or launch readiness.


## SM-4M — Governance without another plugin: inspect SEO/GEO Theme slots

A WordPress site needs **Site Manager + SEO/GEO Theme**, not a third
SEO/GEO Manager plugin. The Theme owns Corporate rendering and exposes
the canonical `corporate-home-v1` semantic model through its installed
`CorporateHomeModelResolver` and `seo_geo_theme_preset_document()`
contract. Site Manager uses those *public Theme interfaces*, not copies
of its block renderer, private metadata, CSS or HTML structure.

Read-only operator intent:

```json
{
  "version": "1",
  "command_id": "cmd-theme-model-example-0001",
  "site": "acceptance-site",
  "action": "site.theme_model",
  "requested_by": "chatgpt",
  "payload": {}
}
```

The Gateway maps exactly `site.theme_model` → `site_theme_model`
MCP tool → authenticated `GET /site-manager/v1/theme/model` in
WordPress, all under the **existing** `site.read` scope. The caller
cannot supply post IDs, model IDs, arbitrary URLs, WordPress meta keys,
block markup or edit values. The WordPress route selects only the
actual **published homepage**, resolves its live active Theme contract,
and responds with the Corporate semantic model's slot IDs, type,
required/completion status and source. Text, link labels/targets,
Gutenberg blocks and private content values are **never included**.
The Gateway independently rejects malformed, duplicate, unbounded
or unsafe slot evidence before persisting sanitized public structure.

This inspection alone does not verify or grant any write authority:
every returned slot has `writable_via_site_manager=false` and the
model has `editing_ready=false`. It is **not** a full visual design
review, private content preview, mutation API or field acceptance for
EMMAKE. The next accepted work must implement strict, server-side
semantic-slot change plans with a private before/after preview,
optimistic concurrency, explicit confirmation, exact rendered Verify
and stale-safe rollback, preserving the Theme as renderer.

Release integration requires WordPress Site Manager **0.11.8**
and the matching MCP image before a real `site.theme_model` command
can pass on `emmake.com/nuevaweb/`. WordPress 0.11.7 remains
operational for the existing content/SEO/navigation tools until then.

EMMAKE remains the only active implementation. The full web must be
finished and tested at `/nuevaweb` before migration to the primary
domain; preserve prior successful content migration and do not repeat
Reset/Hydration.
