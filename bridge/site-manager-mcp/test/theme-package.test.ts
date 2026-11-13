import test from 'node:test';
import assert from 'node:assert/strict';
import { SiteManagerClient } from '../src/site-manager-client.js';

const bearer = 'a'.repeat(64);
const config = {
  restBase: new URL('https://wordpress.example.test/wp-json/site-manager/v1/'),
  token: bearer,
  connectionId: 'smc_' + 'b'.repeat(32),
  timeoutMs: 10000,
};

test('Theme code deployment routes are isolated from semantic Theme updates and use only authenticated POST', async () => {
  const calls: Array<{ path: string; method: string | undefined; json: Record<string, unknown>; authorization: string | null }> = [];
  const fakeFetch = (async (input: URL | RequestInfo, init?: RequestInit) => {
    const url = input instanceof URL ? input : new URL(String(input));
    calls.push({
      path: url.pathname,
      method: init?.method,
      json: typeof init?.body === 'string' ? JSON.parse(init.body) : {},
      authorization: new Headers(init?.headers).get('authorization'),
    });
    return new Response(JSON.stringify({ data: { status: 'planned', verified: false } }), { status: 200 });
  }) as typeof fetch;

  const client = new SiteManagerClient(config, fakeFetch);
  await client.themePackagePlan({
    theme_slug: 'client-theme',
    package_sha256: 'a'.repeat(64),
    package_base64: 'QUJD',
    source_commit_sha: 'b'.repeat(40),
    source_run_id: 42,
    source_artifact_id: 97,
  });
  await client.themePackageApply({ plan_id: 'sm-plan-' + 'c'.repeat(24), confirmed: true });
  await client.themePackageRollback({ plan_id: 'sm-plan-' + 'c'.repeat(24), confirmed: true });
  assert.deepEqual(calls.map(x => x.path), [
    '/wp-json/site-manager/v1/write/theme/package/plan',
    '/wp-json/site-manager/v1/write/theme/package/apply',
    '/wp-json/site-manager/v1/write/theme/package/rollback',
  ]);
  assert.ok(calls.every(x => x.method === 'POST' && x.authorization === `Bearer ${bearer}`));
  assert.deepEqual(Object.keys(calls[0].json).sort(), [
    'package_base64', 'package_sha256', 'source_artifact_id', 'source_commit_sha', 'source_run_id', 'theme_slug',
  ]);
  assert.deepEqual(Object.keys(calls[1].json).sort(), ['confirmed','plan_id']);
  assert.deepEqual(calls[1].json, { plan_id: 'sm-plan-' + 'c'.repeat(24), confirmed: true });
  assert.equal(JSON.stringify(calls).includes('shell_command'), false);
  assert.equal(JSON.stringify(calls).includes('package_url'), false);
});

test('WordPress package API failure sanitizes bearer and does not leak a package string', async () => {
  const fakeFetch = (async () => new Response(JSON.stringify({
    error: { code: 'invalid_package', secret: bearer, path: '/tmp/private' },
  }), { status: 409 })) as typeof fetch;
  const client = new SiteManagerClient(config, fakeFetch);
  await assert.rejects(() => client.themePackagePlan({
    theme_slug: 'client-theme',
    package_sha256: 'a'.repeat(64),
    package_base64: 'QUJD',
    source_commit_sha: 'b'.repeat(40),
    source_run_id: 42,
    source_artifact_id: 97,
  }), (error: unknown) => {
    assert.ok(error instanceof Error);
    assert.equal(error.message.includes(bearer), false);
    assert.equal(error.message.includes('/tmp'), false);
    return true;
  });
});
