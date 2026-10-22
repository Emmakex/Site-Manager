# Site Manager command transport

This directory is the GitHub transport surface for the canonical Site Manager governance flow.

- `examples/` contains safe command examples.
- `pending/` is the only trusted command execution namespace.
- `results/` contains sanitized, fingerprinted execution results for ChatGPT readback.

Commands carry business intent only. They never contain MCP URLs, bearer tokens, MCP tool names, arbitrary WordPress REST routes, PHP, SQL, shell or filesystem instructions.

See `docs/COMMAND_GATEWAY.md` and the repository-wide `ENGINEERING_RULES.md`.
