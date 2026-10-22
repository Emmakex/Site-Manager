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

- `content.update`;
- `navigation.update`;
- `seo.update`.

Each must reuse Site Manager's existing `plan → explicit confirmation → apply → verify → rollback` contracts. The Gateway must never bypass Site Manager by writing WordPress/provider storage directly.
