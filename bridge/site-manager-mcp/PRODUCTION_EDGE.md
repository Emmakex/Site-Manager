# Site Manager MCP — production shared-edge runtime

## Purpose

This is the production deployment mode behind the canonical Site Manager operator path:

```text
ChatGPT → GitHub → Command Gateway → production MCP → Site Manager → WordPress
```

It is infrastructure, not the normal operator interface. Normal commands must not require a developer terminal, SSH session or local Docker process.

## Boundary

The Site Manager MCP container remains an internal HTTP service. It does **not** publish host ports in production. Public TLS and routing belong to the shared Kairoseth edge (`kairoseth-public-edge`).

Each connected WordPress site keeps its own:

- Site Manager connection id;
- site-specific Site Manager token;
- isolated Docker Compose project `site-manager-mcp-<site>`;
- MCP runtime/container instance for this phase;
- stable internal edge alias `site-manager-mcp-<site>`;
- protected GitHub Environment `site-manager-<site>` used by deployment and the Command Gateway.

The production Compose file deliberately has no fixed top-level project name. The deploy workflow supplies `--project-name site-manager-mcp-<site>` so two WordPress installations never share a Compose project accidentally.

## Token contract in MCP 0.5.x

The bridge currently uses the same site-specific Site Manager connection token at both bounded transport boundaries:

```text
Command Gateway → MCP /mcp
                 ↓ same scoped connection token
MCP → Site Manager REST → WordPress
```

The runtime reads that secret from `SITE_MANAGER_TOKEN`. The Command Gateway stores the same secret under its environment-facing name `SITE_MANAGER_MCP_TOKEN` because, from the Gateway's point of view, it authenticates to MCP.

These are two deployment variable names for one per-site scoped/revocable Site Manager token. Site Manager generates the token as 64 lowercase hexadecimal characters and stores only its SHA-256 hash in WordPress. The plaintext token must never be committed, logged, placed in a command JSON or returned in a result.

## Production Compose contract

Use `compose.edge.yaml`, not the local/persistent loopback `compose.yaml`.

The runtime requires:

- `SITE_MANAGER_MCP_IMAGE_REF` — immutable GHCR digest reference;
- `SITE_MANAGER_MCP_EDGE_ALIAS` — `site-manager-mcp-<site>`;
- `SITE_MANAGER_REST_BASE` — exact HTTPS Site Manager REST base;
- `SITE_MANAGER_CONNECTION_ID` — `smc_...` connection id;
- `SITE_MANAGER_TOKEN` — site-specific token;
- `HOST=0.0.0.0` and `PORT=8787`, supplied internally by Compose.

CI cross-checks this Compose contract against `src/config.ts` and rejects legacy/mismatched environment names.

The production service publishes no host ports, joins the external `kairoseth-public-edge`, runs read-only with `/tmp` tmpfs, drops Linux capabilities, enables `no-new-privileges`, has a PID limit/healthcheck and restarts unless explicitly stopped.

## Public routing

The shared Kairoseth edge owns the public route. Site Manager must not ship or start a second public Caddy/Nginx process.

For each site, the edge maps one explicit public path or hostname to that site's stable internal alias. Example:

```text
https://mcp.kairoseth.com/site-manager/example-site/mcp
→ site-manager-mcp-example-site:8787/mcp
```

The edge configuration is infrastructure-specific and belongs in the Kairoseth edge repository, not Site Manager Core. The public route exposes only the MCP transport required by the Command Gateway.

## Image publication

`.github/workflows/mcp-image.yml` publishes the Site Manager MCP image to GHCR from `main` and records the immutable digest as an artifact. Production consumes only:

```text
ghcr.io/emmakex/site-manager-mcp@sha256:<64 hex chars>
```

## Per-site GitHub Environment

A logical site `example-site` uses:

```text
site-manager-example-site
```

Variables:

- `SITE_MANAGER_SITE_ID=example-site`
- `SITE_MANAGER_REST_BASE=https://example.com/wp-json/site-manager/v1/`
- `SITE_MANAGER_CONNECTION_ID=smc_...`
- `SITE_MANAGER_MCP_BASE_URL=https://mcp.kairoseth.com/site-manager/example-site/mcp`

Secret:

- `SITE_MANAGER_MCP_TOKEN=<the one-time Site Manager connection token>`

Infrastructure deployment also needs the repository/environment secrets `OVH_MCP_HOST`, `OVH_MCP_USER`, `OVH_MCP_SSH_KEY` and `OVH_MCP_SSH_KNOWN_HOSTS`. These are deployment credentials only; they never enter command JSON or the MCP result path.

## Automated deployment

`.github/workflows/site-manager-mcp-deploy.yml` is the production deployment path. It is manually dispatched as an infrastructure action, but the operator does not SSH into the server.

For `deploy` it:

1. validates the logical site against the selected protected environment;
2. validates the 64-hex Site Manager token, connection id, HTTPS REST/MCP URLs and immutable image digest;
3. configures strict SSH host trust from secrets;
4. creates a temporary 0600 production environment file without printing the token;
5. stages the generic Compose contract and deploy script over SSH;
6. deploys the isolated project `site-manager-mcp-<site>` on `kairoseth-public-edge`;
7. waits for container health;
8. runs the canonical public `site.read` through the production MCP URL using the same bounded Command Gateway runner;
9. uploads only sanitized acceptance evidence;
10. rolls back automatically if public acceptance fails;
11. removes local and remote staging material.

The remote script keeps current/previous image, environment and Compose contracts. Later rollbacks restore the last accepted deployment. If the first-ever deployment fails acceptance, rollback restores the empty pre-deployment baseline by removing that site's runtime.

## Acceptance

For the first site:

1. shared edge route is accepted without changing the existing Research Manager route;
2. deploy the latest immutable Site Manager MCP digest through the deploy workflow;
3. public `site.read` acceptance passes against real WordPress state;
4. submit a normal `site.read` command through `commands/site-manager/pending/`;
5. confirm the sanitized result is committed to `commands/site-manager/results/`;
6. replay the exact command and prove no second MCP execution occurs.

Only after this complete round trip is green should write business actions be added to the Gateway.
