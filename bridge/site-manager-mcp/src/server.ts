import { McpServer, fromJsonSchema } from '@modelcontextprotocol/server';
import type { SiteManagerClient } from './site-manager-client.js';

function toolResult(data: unknown) {
  return {
    content: [{ type: 'text' as const, text: JSON.stringify(data, null, 2) }],
    structuredContent: { data },
  };
}

function toolError(message = 'Site Manager MCP could not complete the requested operation.') {
  return {
    isError: true,
    content: [{ type: 'text' as const, text: message }],
  };
}

type ContentPlanInput = {
  post_id: number;
  fields: Record<string, string>;
  reason?: string;
};

type ConfirmedPlanInput = {
  plan_id: string;
  confirmed: boolean;
};

const contentPlanSchema = fromJsonSchema<ContentPlanInput>({
  type: 'object',
  additionalProperties: false,
  properties: {
    post_id: { type: 'integer', minimum: 1 },
    fields: {
      type: 'object',
      minProperties: 1,
      additionalProperties: false,
      properties: {
        title: { type: 'string' },
        slug: { type: 'string' },
        content: { type: 'string' },
        excerpt: { type: 'string' },
        status: { type: 'string', enum: ['draft', 'publish', 'private', 'pending'] },
      },
    },
    reason: { type: 'string', maxLength: 500 },
  },
  required: ['post_id', 'fields'],
});

const confirmedPlanSchema = fromJsonSchema<ConfirmedPlanInput>({
  type: 'object',
  additionalProperties: false,
  properties: {
    plan_id: { type: 'string', minLength: 1, maxLength: 200 },
    confirmed: { type: 'boolean' },
  },
  required: ['plan_id', 'confirmed'],
});

export function createSiteManagerMcpServer(
  client: SiteManagerClient,
  connectionId: string,
  grantedScopes: readonly string[] = ['site.read', 'site.diagnostics'],
): McpServer {
  const scopes = new Set(grantedScopes);
  const server = new McpServer(
    { name: 'site-manager-mcp', version: '0.2.0' },
    {
      instructions: `This MCP endpoint is bound to Site Manager connection ${connectionId}. Tools are exposed only for scopes currently granted by WordPress Site Manager. Writes must always use plan -> explicit confirmation -> apply -> verify, and rollback when requested. Never infer or construct arbitrary REST, database, filesystem or shell routes. Site Manager remains the source of truth for capabilities, provider ownership and authorization.`,
      capabilities: { tools: { listChanged: false } },
      maxToolInputElements: 64,
    },
  );

  if (scopes.has('site.read')) {
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
          return toolError('Site Manager MCP could not complete the requested read operation.');
        }
      },
    );
  }

  if (scopes.has('site.diagnostics')) {
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
          return toolError('Site Manager MCP could not complete the requested diagnostic read.');
        }
      },
    );
  }

  if (scopes.has('content.write')) {
    server.registerTool(
      'content_update_plan',
      {
        title: 'Plan a WordPress content update',
        description: 'Create a short-lived Site Manager plan for a native WordPress post/page update. This does not apply the content change.',
        inputSchema: contentPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
      },
      async ({ post_id, fields, reason }) => {
        try {
          return toolResult(await client.contentUpdatePlan({ post_id, fields, ...(reason === undefined ? {} : { reason }) }));
        } catch {
          return toolError('Site Manager rejected or could not create the content update plan.');
        }
      },
    );

    server.registerTool(
      'content_update_apply',
      {
        title: 'Apply a confirmed WordPress content update plan',
        description: 'Apply exactly one previously created Site Manager content plan. Requires confirmed=true; stale or expired plans fail closed and verification is returned.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit confirmation is required before applying a Site Manager write plan.');
        }
        try {
          return toolResult(await client.contentUpdateApply({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager rejected or could not apply the content update plan.');
        }
      },
    );

    server.registerTool(
      'content_update_rollback',
      {
        title: 'Rollback an applied WordPress content update plan',
        description: 'Rollback a verified Site Manager content update while its rollback evidence is retained. Requires confirmed=true and fails closed if the target changed after apply.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit confirmation is required before rolling back a Site Manager write plan.');
        }
        try {
          return toolResult(await client.contentUpdateRollback({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager rejected or could not roll back the content update plan.');
        }
      },
    );
  }

  return server;
}
