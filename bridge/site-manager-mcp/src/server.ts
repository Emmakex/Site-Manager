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

type ThemeTextPlanInput = {
  fields: Record<string, string>;
  reason?: string;
};

type ThemeBootstrapPlanInput = {
  fields: Record<string, string | { label: string; url: string }>;
  reason?: string;
};

const themeBootstrapPlanSchema = fromJsonSchema<ThemeBootstrapPlanInput>({
  type: 'object',
  additionalProperties: false,
  required: ['fields'],
  properties: {
    fields: {
      type: 'object',
      minProperties: 1,
      maxProperties: 100,
      patternProperties: {
        '^[a-z0-9][a-z0-9-]{0,79}$': {
          oneOf: [
            { type: 'string', minLength: 1, maxLength: 2000 },
            {
              type: 'object',
              additionalProperties: false,
              required: ['label', 'url'],
              properties: {
                label: { type: 'string', minLength: 1, maxLength: 180 },
                url: { type: 'string', minLength: 10, maxLength: 2048, pattern: '^https://' },
              },
            },
          ],
        },
      },
      additionalProperties: false,
    },
    reason: { type: 'string', maxLength: 500 },
  },
});

type ThemePackagePlanInput = {
  theme_slug: string;
  package_sha256: string;
  package_base64: string;
  source_commit_sha: string;
  source_run_id: number;
  source_artifact_id: number;
  reason?: string;
};

const themePackagePlanSchema = fromJsonSchema<ThemePackagePlanInput>({
  type: 'object',
  additionalProperties: false,
  required: ['theme_slug', 'package_sha256', 'package_base64',
    'source_commit_sha', 'source_run_id', 'source_artifact_id'],
  properties: {
    theme_slug: { type: 'string', pattern: '^[a-z0-9][a-z0-9-]{1,79}$' },
    package_sha256: { type: 'string', pattern: '^[a-f0-9]{64}$' },
    package_base64: { type: 'string', minLength: 128, maxLength: 27000000, pattern: '^[A-Za-z0-9+/]+={0,2}$' },
    source_commit_sha: { type: 'string', pattern: '^[a-f0-9]{40}$' },
    source_run_id: { type: 'integer', minimum: 1 },
    source_artifact_id: { type: 'integer', minimum: 1 },
    reason: { type: 'string', maxLength: 500 },
  },
});

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

const themeTextPlanSchema = fromJsonSchema<ThemeTextPlanInput>({
  type: 'object',
  additionalProperties: false,
  required: ['fields'],
  properties: {
    fields: {
      type: 'object',
      minProperties: 1,
      maxProperties: 5,
      patternProperties: {
        '^[a-z0-9][a-z0-9-]{0,79}$': { type: 'string', minLength: 1, maxLength: 2000 },
      },
      additionalProperties: false,
    },
    reason: { type: 'string', maxLength: 500 },
  },
});

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
    { name: 'site-manager-mcp', version: '0.5.0' },
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

  if (scopes.has('site.read')) {
    server.registerTool(
      'site_content_targets',
      {
        title: 'List published WordPress pages and posts',
        description: 'Read a bounded published-only list of native WordPress content targets, including homepage ID, titles and permalinks. No private drafts, bodies, excerpts or arbitrary post meta are returned.',
        annotations: { readOnlyHint: true, openWorldHint: false },
      },
      async () => {
        try {
          return toolResult(await client.contentTargets());
        } catch {
          return toolError('Site Manager could not read published WordPress content targets.');
        }
      },
    );
  }

  if (scopes.has('site.read')) {
    server.registerTool(
      'site_content_page',
      {
        title: 'Read one page of published WordPress content',
        description: 'Read only published metadata in one fixed-size post/page batch, with total and next-page indicator. Never returns drafts or article bodies.',
        inputSchema: fromJsonSchema<{ post_type: 'post' | 'page'; page: number }>({
          type: 'object',
          additionalProperties: false,
          required: ['post_type', 'page'],
          properties: {
            post_type: { type: 'string', enum: ['post', 'page'] },
            page: { type: 'integer', minimum: 1, maximum: 10000 },
          },
        }),
        annotations: { readOnlyHint: true, openWorldHint: false },
      },
      async ({ post_type, page }) => {
        try {
          return toolResult(await client.contentPage({ post_type, page }));
        } catch {
          return toolError('Site Manager could not read the bounded public content page.');
        }
      },
    );
  }

  if (scopes.has('site.read')) {
    server.registerTool(
      'site_theme_model',
      {
        title: 'Inspect SEO/GEO Theme strategic homepage model',
        description: 'Read only semantic slot identifiers, types and completion states from the active Theme-owned Corporate homepage model. It never reveals page bodies, slot values, private metadata or write grants.',
        annotations: { readOnlyHint: true, openWorldHint: false },
      },
      async () => {
        try {
          return toolResult(await client.themeModel());
        } catch {
          return toolError('Site Manager could not inspect the active Theme semantic model.');
        }
      },
    );
  }

  if (scopes.has('site.read')) {
    server.registerTool(
      'site_theme_package_state',
      {
        title: 'Read installed WordPress Theme package fingerprint',
        description: 'Read only active Theme slug, version, complete installed file-tree SHA-256, size, file count and observation time. No paths, files, arbitrary theme selectors or write permissions.',
        annotations: { readOnlyHint: true, openWorldHint: false },
      },
      async () => {
        try {
          return toolResult(await client.themePackageState());
        } catch {
          return toolError('Site Manager could not read the installed Theme package fingerprint.');
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


  // Operator-authorized, disposable content-only acceptance. The WordPress
  // Acceptance Center owns fixture creation, the scoped plan/apply/verify/
  // rollback lifecycle and cleanup. No caller-provided post ID is accepted.
  if (scopes.has('content.write') && scopes.has('site.read')) {
    server.registerTool(
      'content_acceptance_start',
      {
        title: 'Start isolated Site Manager content acceptance',
        description: 'Create a disposable draft via WordPress Acceptance Center, update and verify its title, roll back and delete the draft. Requires an explicitly confirmed operator command; never touches existing customer posts or navigation/SEO.',
        inputSchema: fromJsonSchema<{ confirmed: true }>({
          type: 'object',
          additionalProperties: false,
          required: ['confirmed'],
          properties: { confirmed: { const: true } },
        }),
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit content-only acceptance confirmation is required.');
        }
        try {
          return toolResult(await client.remoteAcceptanceStartContentOnly());
        } catch {
          return toolError('Site Manager could not start isolated content-only acceptance.');
        }
      },
    );

    server.registerTool(
      'content_acceptance_status',
      {
        title: 'Read isolated Site Manager content acceptance status',
        description: 'Read the WordPress-owned acceptance state and verification evidence. This does not mutate WordPress.',
        annotations: { readOnlyHint: true, openWorldHint: false },
      },
      async () => {
        try {
          return toolResult(await client.remoteAcceptanceJob());
        } catch {
          return toolError('Site Manager could not read content acceptance status.');
        }
      },
    );
  }

  // Code packages have a distinct opt-in scope; content.write cannot grant it.
  if (scopes.has('theme.package.write') && scopes.has('site.read')) {
    server.registerTool(
      'theme_package_plan',
      {
        title: 'Plan an official CI WordPress Theme package',
        description: 'Stage an exact-SHA CI-attested Theme ZIP on WordPress and return a connection-bound preview. No URL, arbitrary path, script or direct installation.',
        inputSchema: themePackagePlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
      },
      async ({ theme_slug, package_sha256, package_base64, source_commit_sha, source_run_id, source_artifact_id, reason }) => {
        try {
          return toolResult(await client.themePackagePlan({
            theme_slug, package_sha256, package_base64, source_commit_sha, source_run_id, source_artifact_id,
            ...(reason === undefined ? {} : { reason }),
          }));
        } catch {
          return toolError('WordPress rejected the bounded Theme package plan.');
        }
      },
    );
    server.registerTool(
      'theme_package_apply',
      {
        title: 'Apply a confirmed Theme package plan',
        description: 'Install a staged, unexpired Theme release with WordPress-owned backup and exact-byte readback.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        try {
          return toolResult(await client.themePackageApply({ plan_id, confirmed }));
        } catch {
          return toolError('WordPress rejected Theme package apply.');
        }
      },
    );
    server.registerTool(
      'theme_package_rollback',
      {
        title: 'Restore a confirmed pre-upgrade WordPress Theme',
        description: 'Restore only the previous private snapshot belonging to an approved Theme package plan.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        try {
          return toolResult(await client.themePackageRollback({ plan_id, confirmed }));
        } catch {
          return toolError('WordPress rejected Theme package rollback.');
        }
      },
    );
  }

  if (scopes.has('site.read') && scopes.has('content.write')) {
    server.registerTool(
      'theme_update_plan',
      {
        title: 'Plan an SEO/GEO Theme Corporate homepage text change',
        description: 'Creates a short-lived WordPress-side semantic text-slot plan. Never edits Gutenberg layout, CSS or raw HTML. Only accepted Theme contract text slots, no caller-selected page.',
        inputSchema: themeTextPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
      },
      async ({ fields, reason }) => {
        try {
          return toolResult(await client.themeUpdatePlan({ fields, ...(reason === undefined ? {} : { reason }) }));
        } catch {
          return toolError('Site Manager could not plan a Theme semantic text change.');
        }
      },
    );

    server.registerTool(
      'theme_update_apply',
      {
        title: 'Apply an explicitly confirmed Theme text plan',
        description: 'Applies one previously prepared, unexpired, connected Theme text plan with exact WordPress readback verification and rollback evidence.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) return toolError('Theme plan apply requires explicit confirmation.');
        try {
          return toolResult(await client.themeUpdateApply({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager refused or failed to apply the Theme text plan.');
        }
      },
    );

    server.registerTool(
      'theme_update_rollback',
      {
        title: 'Rollback a confirmed Theme semantic text change',
        description: 'Restores the exact prior Theme-owned semantic state if no stale changes occurred, preserving WordPress source blocks.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) return toolError('Theme plan rollback requires explicit confirmation.');
        try {
          return toolResult(await client.themeUpdateRollback({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager refused or failed to roll back the Theme text plan.');
        }
      },
    );
  }

  if (scopes.has('site.read') && scopes.has('content.write')) {
    server.registerTool(
      'theme_bootstrap_plan',
      {
        title: 'Plan first-time Corporate homepage semantic adoption',
        description: 'Use Theme-native required text/internal links on the discovered published homepage only. No external providers, proof claims or arbitrary WordPress content edits.',
        inputSchema: themeBootstrapPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
      },
      async ({ fields, reason }) => {
        try {
          return toolResult(await client.themeBootstrapPlan({ fields, ...(reason === undefined ? {} : { reason }) }));
        } catch {
          return toolError('Site Manager refused or could not plan first-time Theme adoption.');
        }
      },
    );

    server.registerTool(
      'theme_bootstrap_apply',
      {
        title: 'Apply confirmed first-time Theme adoption',
        description: 'Apply only an accepted, fresh, WordPress-owned semantic bootstrap plan after explicit confirmation. Preserve the existing Gutenberg source body.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) return toolError('First-time Theme adoption requires explicit confirmation.');
        try {
          return toolResult(await client.themeBootstrapApply({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager refused or could not apply Theme adoption.');
        }
      },
    );

    server.registerTool(
      'theme_bootstrap_rollback',
      {
        title: 'Rollback confirmed first-time Theme adoption',
        description: 'Remove only the Site Manager-owned model while the verified after-state is unchanged. Restore original page rendering and Gutenberg body.',
        inputSchema: confirmedPlanSchema,
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ plan_id, confirmed }) => {
        if (confirmed !== true) return toolError('Theme adoption rollback requires explicit confirmation.');
        try {
          return toolResult(await client.themeBootstrapRollback({ plan_id, confirmed: true }));
        } catch {
          return toolError('Site Manager refused or could not roll back Theme adoption.');
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

  // A confirmed, bounded fixture test backed by WordPress Acceptance Center.
  // No menu IDs, client content or routes can be selected by the caller.
  if (scopes.has('navigation.write') && scopes.has('site.read')) {
    server.registerTool(
      'navigation_acceptance_start',
      {
        title: 'Start isolated Site Manager navigation acceptance',
        description: 'Create one unassigned temporary classic menu/item, plan and verify an update, roll back and delete the menu. Never touches assigned customer menus, content or SEO.',
        inputSchema: fromJsonSchema<{ confirmed: true }>({
          type: 'object',
          additionalProperties: false,
          required: ['confirmed'],
          properties: { confirmed: { const: true } },
        }),
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit isolated navigation acceptance confirmation is required.');
        }
        try {
          return toolResult(await client.remoteAcceptanceStartNavigationOnly());
        } catch {
          return toolError('Site Manager could not start isolated navigation acceptance.');
        }
      },
    );

    server.registerTool(
      'navigation_acceptance_status',
      {
        title: 'Read isolated Site Manager navigation acceptance progress',
        description: 'Read the current WordPress-owned acceptance run and its verification evidence; no WordPress mutation.',
        annotations: { readOnlyHint: true, openWorldHint: false },
      },
      async () => {
        try {
          return toolResult(await client.remoteAcceptanceJob());
        } catch {
          return toolError('Site Manager could not read navigation acceptance status.');
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

  // A confirmed SEO-only acceptance run uses a disposable WordPress-owned
  // draft and the real resolved provider. No client-selected post or meta key.
  if (scopes.has('site.read') && scopes.has('content.write') && scopes.has('seo.write')) {
    server.registerTool(
      'seo_acceptance_start',
      {
        title: 'Start isolated WordPress SEO acceptance',
        description: 'Create a temporary unpublished page via the WordPress Acceptance Center; run provider-governed SEO plan, verified apply, rollback and fixture deletion. No customer pages, navigation or arbitrary metadata are touched.',
        inputSchema: fromJsonSchema<{ confirmed: true }>({
          type: 'object',
          additionalProperties: false,
          required: ['confirmed'],
          properties: { confirmed: { const: true } },
        }),
        annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false },
      },
      async ({ confirmed }) => {
        if (confirmed !== true) {
          return toolError('Explicit SEO-only acceptance confirmation is required.');
        }
        try {
          return toolResult(await client.remoteAcceptanceStartSeoOnly());
        } catch {
          return toolError('Site Manager could not start isolated SEO acceptance.');
        }
      },
    );

    server.registerTool(
      'seo_acceptance_status',
      {
        title: 'Read isolated WordPress SEO acceptance state',
        description: 'Read WordPress-owned acceptance progress and lifecycle evidence without mutating WordPress.',
        annotations: { readOnlyHint: true, openWorldHint: false },
      },
      async () => {
        try {
          return toolResult(await client.remoteAcceptanceJob());
        } catch {
          return toolError('Site Manager could not read SEO acceptance status.');
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
