# Site Manager MCP deployment requests

This directory is the governed GitHub transport for production MCP deployment intent. It is infrastructure orchestration behind the canonical operator flow, not a place for credentials.

## Namespaces

- `examples/` — safe examples only.
- `pending/` — exactly one added/modified JSON request may execute per trusted push.
- `results/` — sanitized, fingerprinted deployment results committed by the deployment workflow.

## Request v1

```json
{
  "version": "1",
  "deployment_id": "deploy-example-site-0001",
  "site": "example-site",
  "action": "deploy",
  "image_ref": "ghcr.io/emmakex/site-manager-mcp@sha256:<digest>",
  "requested_by": "chatgpt"
}
```

A rollback request uses `"action":"rollback"` and `"image_ref":null`.

Requests must never contain WordPress URLs, MCP URLs, connection ids, tokens, SSH hosts/users/keys or arbitrary shell/REST instructions. Those values come only from the protected GitHub Environment `site-manager-<site>`.

Each request receives a canonical SHA-256 fingerprint. An exact previously successful replay is accepted without another infrastructure mutation. A reused `deployment_id` with different content fails closed.

Deleting only a pending request is cleanup and must not deploy or roll back anything.
