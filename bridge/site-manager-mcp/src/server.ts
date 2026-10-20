import { McpServer } from '@modelcontextprotocol/server';
import type { SiteManagerClient } from './site-manager-client.js';

function toolResult(data: unknown) {
  return {
    content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
    structuredContent: { data },
  };
}

function toolError() {
  return {
    isError: true,
    content: [{ type: 'text' as const, text: 'Site Manager MCP could not complete the requested read operation.' }],
  };
}

export function createSiteManagerMcpServer(client: SiteManagerClient, connectionId: string): McpServer {
  const server = new McpServer(
    { name: 'site-manager-mcp', version: '0.1.0' },
    {
      instructions: `This MCP endpoint is bound to Site Manager connection ${connectionId}. SM-4B is read-only. Use only site_read and site_diagnostics. Never infer or construct arbitrary REST, database, filesystem or shell routes. Site Manager remains the source of truth for capabilities and authorization.`,
      capabilities: { tools: { listChanged: false } },
      maxToolInputElements: 32,
    },
  );

  server.registerTool(
    'site_read',
    {
      title: 'Read Site Manager site state',
      description: 'Read the normalized Site Manager discovery snapshot and capability graph using the site.read scope.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => {
      try {
        return toolResult(await client.siteRead());
      } catch {
        return toolError();
      }
    },
  );

  server.registerTool(
    'site_diagnostics',
    {
      title: 'Read Site Manager diagnostics',
      description: 'Read the normalized Site Manager diagnostics using the site.diagnostics scope.',
      annotations: { readOnlyHint: true, openWorldHint: false },
    },
    async () => {
      try {
        return toolResult(await client.diagnostics());
      } catch {
        return toolError();
      }
    },
  );

  return server;
}
