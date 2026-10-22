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
- Site Manager bearer;
- MCP runtime/container instance for this phase;
- stable internal edge alias;
- protected GitHub Environment used by the Command Gateway.

This keeps credentials and failure domains isolated while the Site Manager Core and Command Gateway remain generic.

## Production Compose contract

Use `compose.edge.yaml`, not the local/persistent loopback `compose.yaml`.

Required deployment variables:

- `SITE_MANAGER_MCP_IMAGE_REF` — immutable GHCR image reference including `@sha256:...`;
- `SITE_MANAGER_MCP_EDGE_ALIAS` — unique internal alias such as `site-manager-mcp-example-site`;
- `SITE_MANAGER_REST_BASE` — exact HTTPS Site Manager REST base for the WordPress installation;
- `SITE_MANAGER_CONNECTION_ID` — connection id created by Site Manager;
- `SITE_MANAGER_BEARER` — site-specific bearer, stored only in deployment secrets/operator-owned environment.

The production service:

- exposes only container port 8787 to the Docker network;
- joins only the external `kairoseth-public-edge` network;
- publishes no host ports;
- runs read-only with `/tmp` tmpfs;
- drops all Linux capabilities;
- enables `no-new-privileges`;
- uses a PID limit and healthcheck;
- restarts unless explicitly stopped.

## Public routing

The shared Kairoseth edge owns the public route. Site Manager must not ship or start a second public Caddy/Nginx process.

For each site, the edge maps one explicit public path or hostname to that site's stable internal alias. Example logical mapping:

```text
https://mcp.kairoseth.com/site-manager/example-site/mcp
→ site-manager-mcp-example-site:8787/mcp
```

The edge configuration is infrastructure-specific and belongs in the Kairoseth edge repository, not Site Manager Core.

The public route must expose only the MCP transport path required by the Command Gateway. `/healthz` may remain internal unless an explicit bounded edge health route is needed.

## Image publication

`.github/workflows/mcp-image.yml` publishes the Site Manager MCP container to GHCR from `main` and records the immutable digest as a workflow artifact. Production deployment must consume the digest form, never a mutable `latest` tag.

Example accepted reference shape:

```text
ghcr.io/emmakex/site-manager-mcp@sha256:<64 hex chars>
```

## Command Gateway environment

A site with logical id `example-site` uses GitHub Environment:

```text
site-manager-example-site
```

with:

- variable `SITE_MANAGER_SITE_ID=example-site`;
- variable `SITE_MANAGER_MCP_BASE_URL=<public MCP URL for this site's route>`;
- secret `SITE_MANAGER_MCP_TOKEN=<site-specific production bearer>`.

The bearer is never committed to Git and never placed in a command JSON.

## Acceptance

For the first site:

1. deploy one healthy internal Site Manager MCP runtime using an immutable image digest;
2. attach it to `kairoseth-public-edge` with a unique alias;
3. add the explicit edge route without changing the existing Research Manager `/mcp` route;
4. configure the site's protected GitHub Environment;
5. submit one `site.read` command through `commands/site-manager/pending/`;
6. confirm the Gateway returns a sanitized successful result from the real WordPress state;
7. replay the exact command and prove no second MCP execution occurs.

Only after this round trip is green should write business actions be added to the Gateway.
