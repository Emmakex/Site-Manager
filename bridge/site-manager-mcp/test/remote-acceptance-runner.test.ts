import assert from 'node:assert/strict';
import test from 'node:test';
import { executeRemoteAcceptanceStep } from '../src/remote-acceptance-runner.js';
import type { SiteManagerClient } from '../src/site-manager-client.js';

test('remote acceptance runner executes exactly one content plan phase and reports bounded evidence', async () => {
  const calls: Array<{ name: string; input?: unknown }> = [];
  const fake = {
    async contentUpdatePlan(input: unknown) {
      calls.push({ name: 'contentUpdatePlan', input });
      return { plan: { plan_id: 'sm-plan-content123' }, changes: { ignored: 'large-detail' } };
    },
    async remoteAcceptanceReport(input: unknown) {
      calls.push({ name: 'remoteAcceptanceReport', input });
      return { status: 'running' };
    },
  } as unknown as SiteManagerClient;

  await executeRemoteAcceptanceStep(fake, {
    run_id: 'sm-mcp-accept-123',
    status: 'running',
    next_step: 'content_plan',
    fixture: { post_id: 77 },
    artifacts: {},
  });

  assert.equal(calls.length, 2);
  assert.equal(calls[0]?.name, 'contentUpdatePlan');
  assert.equal(calls[1]?.name, 'remoteAcceptanceReport');
  assert.deepEqual(calls[1]?.input, {
    run_id: 'sm-mcp-accept-123',
    step: 'content_plan',
    success: true,
    evidence: { plan_id: 'sm-plan-content123' },
  });
});

test('remote acceptance runner uses the persisted navigation plan id for rollback', async () => {
  const calls: Array<{ name: string; input?: unknown }> = [];
  const fake = {
    async navigationUpdateRollback(input: unknown) {
      calls.push({ name: 'navigationUpdateRollback', input });
      return {
        status: 'rolled-back',
        rollback: { success: true, status: 'rolled-back' },
      };
    },
    async remoteAcceptanceReport(input: unknown) {
      calls.push({ name: 'remoteAcceptanceReport', input });
      return { status: 'running' };
    },
  } as unknown as SiteManagerClient;

  await executeRemoteAcceptanceStep(fake, {
    run_id: 'sm-mcp-accept-456',
    status: 'running',
    next_step: 'navigation_rollback',
    fixture: { menu_item_id: 88 },
    artifacts: { navigation_plan_id: 'sm-plan-navigation456' },
  });

  assert.equal(calls.length, 2);
  assert.deepEqual(calls[0], {
    name: 'navigationUpdateRollback',
    input: { plan_id: 'sm-plan-navigation456', confirmed: true },
  });
  const report = calls[1]?.input as Record<string, unknown>;
  assert.equal(report.step, 'navigation_rollback');
  assert.equal(report.success, true);
});

test('remote acceptance cleanup is one direct bounded phase and is not double-reported', async () => {
  const calls: Array<{ name: string; input?: unknown }> = [];
  const fake = {
    async remoteAcceptanceCleanup(runId: string) {
      calls.push({ name: 'remoteAcceptanceCleanup', input: runId });
      return { status: 'completed' };
    },
    async remoteAcceptanceReport(input: unknown) {
      calls.push({ name: 'remoteAcceptanceReport', input });
      return {};
    },
  } as unknown as SiteManagerClient;

  await executeRemoteAcceptanceStep(fake, {
    run_id: 'sm-mcp-accept-789',
    status: 'cleaning',
    next_step: 'cleanup',
  });

  assert.deepEqual(calls, [{ name: 'remoteAcceptanceCleanup', input: 'sm-mcp-accept-789' }]);
});
