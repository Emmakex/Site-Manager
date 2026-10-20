import test from 'node:test';
import assert from 'node:assert/strict';
import { loadConfig } from '../src/config.js';
import { secureBearerMatches } from '../src/http.js';
import { SiteManagerClient, SiteManagerClientError } from '../src/site-manager-client.js';

const secret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
const connectionId = 'smc_0123456789abcdef0123456789abcdef';

function baseConfig() {
  return {
    restBase: new URL('https://example.test/wp-json/site-manager/v1/'),
    token: secret,
    connectionId,
    timeoutMs: 5000,
  };
}

test('configuration requires HTTPS for non-local Site Manager targets', () => {
  assert.throws(() => loadConfig({
    SITE_MANAGER_REST_BASE: 'http://example.test/wp-json/site-manager/v1/',
    SITE_MANAGER_TOKEN: secret,
    SITE_MANAGER_CONNECTION_ID: connectionId,
  }), /HTTPS/);

  const local = loadConfig({
    SITE_MANAGER_REST_BASE: 'http://127.0.0.1:8080/wp-json/site-manager/v1/',
    SITE_MANAGER_TOKEN: secret,
    SITE_MANAGER_CONNECTION_ID: connectionId,
  });
  assert.equal(local.restBase.protocol, 'http:');
});

test('connection scope discovery uses only authenticated connection endpoint', async () => {
  const requests: Array<{ path: string; auth: string | null }> = [];
  const fakeFetch = (async (input: URL | RequestInfo, init?: RequestInit) => {
    const url = input instanceof URL ? input : new URL(String(input));
    const headers = new Headers(init?.headers);
    requests.push({ path: url.pathname, auth: headers.get('authorization') });
    return new Response(JSON.stringify({ data: { connection_id: connectionId, scopes: ['site.read', 'content.write'] } }), { status: 200 });
  }) as typeof fetch;

  const client = new SiteManagerClient(baseConfig(), fakeFetch);
  assert.deepEqual(await client.grantedScopes(), ['site.read', 'content.write']);
  assert.deepEqual(requests, [{ path: '/wp-json/site-manager/v1/connection', auth: `Bearer ${secret}` }]);
});

test('site_read uses only discovery and capabilities with the configured bearer', async () => {
  const requests: Array<{ url: string; auth: string | null; redirect: RequestRedirect | undefined }> = [];
  const fakeFetch = (async (input: URL | RequestInfo, init?: RequestInit) => {
    const url = input instanceof URL ? input : new URL(String(input));
    const headers = new Headers(init?.headers);
    requests.push({ url: url.toString(), auth: headers.get('authorization'), redirect: init?.redirect });
    const resource = url.pathname.endsWith('/discovery') ? 'discovery' : 'capabilities';
    return new Response(JSON.stringify({ data: { resource } }), {
      status: 200,
      headers: { 'content-type': 'application/json' },
    });
  }) as typeof fetch;

  const client = new SiteManagerClient(baseConfig(), fakeFetch);
  const result = await client.siteRead();

  assert.equal(result.connection_id, connectionId);
  assert.deepEqual(result.discovery, { resource: 'discovery' });
  assert.deepEqual(result.capabilities, { resource: 'capabilities' });
  assert.deepEqual(requests.map((request) => new URL(request.url).pathname), [
    '/wp-json/site-manager/v1/discovery',
    '/wp-json/site-manager/v1/capabilities',
  ]);
  assert.ok(requests.every((request) => request.auth === `Bearer ${secret}`));
  assert.ok(requests.every((request) => request.redirect === 'error'));
  assert.equal(JSON.stringify(result).includes(secret), false);
});

test('site_diagnostics uses only the diagnostics resource', async () => {
  const requests: string[] = [];
  const fakeFetch = (async (input: URL | RequestInfo) => {
    const url = input instanceof URL ? input : new URL(String(input));
    requests.push(url.pathname);
    return new Response(JSON.stringify({ data: { ready_for_read: true } }), { status: 200 });
  }) as typeof fetch;

  const client = new SiteManagerClient(baseConfig(), fakeFetch);
  const result = await client.diagnostics();
  assert.deepEqual(requests, ['/wp-json/site-manager/v1/diagnostics']);
  assert.deepEqual(result.diagnostics, { ready_for_read: true });
});

test('content, navigation and SEO write methods use only fixed Site Manager write routes and POST JSON', async () => {
  const requests: Array<{ path: string; method: string | undefined; body: unknown; auth: string | null; userAgent: string | null }> = [];
  const fakeFetch = (async (input: URL | RequestInfo, init?: RequestInit) => {
    const url = input instanceof URL ? input : new URL(String(input));
    const headers = new Headers(init?.headers);
    const body = typeof init?.body === 'string' ? JSON.parse(init.body) : null;
    requests.push({
      path: url.pathname,
      method: init?.method,
      body,
      auth: headers.get('authorization'),
      userAgent: headers.get('user-agent'),
    });
    return new Response(JSON.stringify({ data: { ok: true, path: url.pathname } }), { status: 200 });
  }) as typeof fetch;

  const client = new SiteManagerClient(baseConfig(), fakeFetch);
  await client.contentUpdatePlan({ post_id: 77, fields: { title: 'New title' }, reason: 'Contract test' });
  await client.contentUpdateApply({ plan_id: 'plan_content_1', confirmed: true });
  await client.contentUpdateRollback({ plan_id: 'plan_content_1', confirmed: true });
  await client.navigationUpdatePlan({
    provider: 'wordpress-classic-menu',
    target_id: 88,
    fields: { title: 'New navigation title' },
    reason: 'Navigation contract test',
  });
  await client.navigationUpdateApply({ plan_id: 'plan_navigation_1', confirmed: true });
  await client.navigationUpdateRollback({ plan_id: 'plan_navigation_1', confirmed: true });
  await client.seoUpdatePlan({
    post_id: 99,
    fields: { description: 'Updated SEO description' },
    reason: 'SEO contract test',
  });
  await client.seoUpdateApply({ plan_id: 'plan_seo_1', confirmed: true });
  await client.seoUpdateRollback({ plan_id: 'plan_seo_1', confirmed: true });

  assert.deepEqual(requests.map((request) => request.path), [
    '/wp-json/site-manager/v1/write/content/plan',
    '/wp-json/site-manager/v1/write/content/apply',
    '/wp-json/site-manager/v1/write/content/rollback',
    '/wp-json/site-manager/v1/write/navigation/plan',
    '/wp-json/site-manager/v1/write/navigation/apply',
    '/wp-json/site-manager/v1/write/navigation/rollback',
    '/wp-json/site-manager/v1/write/seo/plan',
    '/wp-json/site-manager/v1/write/seo/apply',
    '/wp-json/site-manager/v1/write/seo/rollback',
  ]);
  assert.ok(requests.every((request) => request.method === 'POST'));
  assert.ok(requests.every((request) => request.auth === `Bearer ${secret}`));
  assert.ok(requests.every((request) => request.userAgent === 'site-manager-mcp/0.4.0'));
  assert.deepEqual(requests[0]?.body, { post_id: 77, fields: { title: 'New title' }, reason: 'Contract test' });
  assert.deepEqual(requests[1]?.body, { plan_id: 'plan_content_1', confirmed: true });
  assert.deepEqual(requests[3]?.body, {
    provider: 'wordpress-classic-menu',
    target_id: 88,
    fields: { title: 'New navigation title' },
    reason: 'Navigation contract test',
  });
  assert.deepEqual(requests[4]?.body, { plan_id: 'plan_navigation_1', confirmed: true });
  assert.deepEqual(requests[6]?.body, {
    post_id: 99,
    fields: { description: 'Updated SEO description' },
    reason: 'SEO contract test',
  });
  assert.deepEqual(requests[7]?.body, { plan_id: 'plan_seo_1', confirmed: true });
});

test('client errors are sanitized and never contain the bearer token', async () => {
  const fakeFetch = (async () => new Response(JSON.stringify({ message: secret }), { status: 403 })) as typeof fetch;
  const client = new SiteManagerClient(baseConfig(), fakeFetch);

  await assert.rejects(
    () => client.siteRead(),
    (error: unknown) => {
      assert.ok(error instanceof SiteManagerClientError);
      assert.equal(error.message.includes(secret), false);
      assert.match(error.message, /HTTP 403/);
      return true;
    },
  );

  await assert.rejects(
    () => client.contentUpdateApply({ plan_id: secret, confirmed: true }),
    (error: unknown) => {
      assert.ok(error instanceof SiteManagerClientError);
      assert.equal(error.message.includes(secret), false);
      return true;
    },
  );

  await assert.rejects(
    () => client.navigationUpdateApply({ plan_id: secret, confirmed: true }),
    (error: unknown) => {
      assert.ok(error instanceof SiteManagerClientError);
      assert.equal(error.message.includes(secret), false);
      return true;
    },
  );

  await assert.rejects(
    () => client.seoUpdateApply({ plan_id: secret, confirmed: true }),
    (error: unknown) => {
      assert.ok(error instanceof SiteManagerClientError);
      assert.equal(error.message.includes(secret), false);
      return true;
    },
  );
});

test('MCP inbound bearer uses the same Site Manager connection secret', () => {
  assert.equal(secureBearerMatches(undefined, secret), false);
  assert.equal(secureBearerMatches('Bearer wrong', secret), false);
  assert.equal(secureBearerMatches(`Bearer ${secret}`, secret), true);
});
