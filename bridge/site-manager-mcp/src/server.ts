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

type NavigationPlanInput = {
  provider: 'wordpress-classic-menu' | 'wordpress-block-navigation';
  target_id: number;
  fields: Record<string, unknown>;
  reason?: string;
};

type SeoPlanInput = {
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

const navigationPlanSchema = fromJsonSchema<NavigationPlanInput>({
  type: 'object',
  additionalProperties: false,
  properties: {
    provider: {
      type: 'string',
      enum: ['wordpress-classic-menu', 'wordpress-block-navigation'],
    },
    target_id: { type: 'integer', minimum: 1 },
    fields: {
      type: 'object',
      minProperties: 1,
      additionalProperties: false,
      properties: {
        title: { type: 'string', maxLength: 500 },
        url: { type: 'string', maxLength: 4096 },
        description: { type: 'string', maxLength: 5000 },
        attr_title: { type: 'string', maxLength: 500 },
        target: { type: 'string', enum: ['', '_blank'] },
        classes: {
          oneOf: [
            { type: 'string', maxLength: 2000 },
            { type: 'array', maxItems: 50, items: { type: 'string', maxLength: 100 } },
          ],
        },
        xfn: { type: 'string', maxLength: 2000 },
        content: { type: 'string', maxLength: 200000 },
        status: { type: 'string', enum: ['draft', 'publish'] },
      },
    },
    reason: { type: 'string', maxLength: 500 },
  },
  required: ['provider', 'target_id', 'fields'],
});

const seoPlanSchema = fromJsonSchema<SeoPlanInput>({
  type: 'object',
  additionalProperties: false,
  properties: {
    post_id: { type: 'integer', minimum: 1 },
    fields: {
      type: 'object',
      minProperties: 1,
      additionalProperties: false,
      properties: {
        title: { type: 'string', maxLength: 500 },
        description: { type: 'string', maxLength: 5000 },
        canonical: { type: 'string', maxLength: 4096 },
        indexability: { type: 'string', enum: ['default', 'index', 'noindex'] },
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
    { name: 'site-manager-mcp', version: '0.4.0' },
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

  if (scopes.has('navigation.write')) {
    server.registerTool(
      'navigation_update_plan',
      {
        title: 'Plan a WordPress navigation update',
        description: 'Create a short-lived Site Manager plan for one discovered classic-menu item or block-navigation target. Planning never mutates navigation.',
        inputSchema: navigationPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
      },
      async ({ provider, target_id, fields, reason }) => {
        try {
          return toolResult(await client.navigationUpdatePlan({ provider, target_id, fields, ...(reason === undefined ? {} : { reason }) }));
        } catch {
          return toolError('Site Manager rejected or could not create the navigation update plan.');
        }
      },
    );

    server.registerTool(
      'navigation_update_apply',
      {
        title: 'Apply a confirmed WordPress navigation update plan',
        description: 'Apply exactly one previously created Site Manager navigation plan. Requires confirmed=true; provider ownership and stale-target guards remain enforced by WordPress.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit confirmation is required before applying a Site Manager write plan.');
        }
        try {
          return toolResult(await client.navigationUpdateApply({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager rejected or could not apply the navigation update plan.');
        }
      },
    );

    server.registerTool(
      'navigation_update_rollback',
      {
        title: 'Rollback an applied WordPress navigation update plan',
        description: 'Rollback a verified Site Manager navigation update while its rollback evidence is retained. Requires confirmed=true and fails closed if the target changed after apply.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit confirmation is required before rolling back a Site Manager write plan.');
        }
        try {
          return toolResult(await client.navigationUpdateRollback({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager rejected or could not roll back the navigation update plan.');
        }
      },
    );
  }

  if (scopes.has('seo.write')) {
    server.registerTool(
      'seo_update_plan',
      {
        title: 'Plan a WordPress SEO update',
        description: 'Create a short-lived provider-aware Site Manager SEO plan for one WordPress post/page. Planning never mutates SEO state; WordPress resolves the real SEO authority and writable fields.',
        inputSchema: seoPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
      },
      async ({ post_id, fields, reason }) => {
        try {
          return toolResult(await client.seoUpdatePlan({ post_id, fields, ...(reason === undefined ? {} : { reason }) }));
        } catch {
          return toolError('Site Manager rejected or could not create the SEO update plan.');
        }
      },
    );

    server.registerTool(
      'seo_update_apply',
      {
        title: 'Apply a confirmed WordPress SEO update plan',
        description: 'Apply exactly one previously created Site Manager SEO plan. Requires confirmed=true; provider authority, plan scope, stale-target protection and verification remain enforced by WordPress.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit confirmation is required before applying a Site Manager write plan.');
        }
        try {
          return toolResult(await client.seoUpdateApply({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager rejected or could not apply the SEO update plan.');
        }
      },
    );

    server.registerTool(
      'seo_update_rollback',
      {
        title: 'Rollback an applied WordPress SEO update plan',
        description: 'Rollback a verified provider-aware Site Manager SEO update while retained rollback evidence remains valid. Requires confirmed=true and fails closed on target drift.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit confirmation is required before rolling back a Site Manager write plan.');
        }
        try {
          return toolResult(await client.seoUpdateRollback({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager rejected or could not roll back the SEO update plan.');
        }
      },
    );
  }

  return server;
}
