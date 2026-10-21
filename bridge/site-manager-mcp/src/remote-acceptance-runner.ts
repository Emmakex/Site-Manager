import { SiteManagerClient } from './site-manager-client.js';

type JsonRecord = Record<string, unknown>;

const IDLE_POLL_MS = 20_000;
const ACTIVE_MIN_PAUSE_MS = 2_000;

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function asRecord(value: unknown): JsonRecord {
  return isRecord(value) ? value : {};
}

function asString(value: unknown): string {
  return typeof value === 'string' ? value : '';
}

function asPositiveInt(value: unknown): number {
  const numeric = Number(value);
  return Number.isInteger(numeric) && numeric > 0 ? numeric : 0;
}

function extractPlanId(value: unknown): string {
  const root = asRecord(value);
  const plan = asRecord(root.plan);
  const id = asString(plan.plan_id);
  return id.startsWith('sm-plan-') ? id : '';
}

function jobPlanId(job: JsonRecord, kind: 'content' | 'navigation' | 'seo'): string {
  const artifacts = asRecord(job.artifacts);
  const id = asString(artifacts[`${kind}_plan_id`]);
  return id.startsWith('sm-plan-') ? id : '';
}

function compactWriteEvidence(value: unknown): JsonRecord {
  const root = asRecord(value);
  const verification = asRecord(root.verification);
  const rollback = asRecord(root.rollback);
  const plan = asRecord(root.plan);
  const execution = asRecord(root.execution);
  const evidence: JsonRecord = {
    status: asString(root.status),
    plan_id: asString(plan.plan_id),
    execution_status: asString(execution.status),
    verification_status: asString(verification.status),
    verified: verification.verified === true,
    rollback_status: asString(rollback.status),
    rollback_success: rollback.success === true,
  };
  const verificationEvidence = asRecord(verification.evidence);
  if (typeof verificationEvidence.fingerprint === 'string') {
    evidence.fingerprint = verificationEvidence.fingerprint;
  }
  const mismatches = asRecord(verificationEvidence.mismatches);
  evidence.mismatch_count = Object.keys(mismatches).length;
  return evidence;
}

async function report(
  client: SiteManagerClient,
  runId: string,
  step: string,
  success: boolean,
  evidence: JsonRecord,
): Promise<void> {
  await client.remoteAcceptanceReport({
    run_id: runId,
    step,
    success,
    evidence,
  });
}

export async function executeRemoteAcceptanceStep(client: SiteManagerClient, job: JsonRecord): Promise<void> {
  const runId = asString(job.run_id);
  const step = asString(job.next_step);
  if (runId === '' || step === '') return;

  try {
    if (step === 'precheck') {
      const read = await client.siteRead();
      await report(client, runId, step, true, {
        connection_id: asString(read.connection_id),
        discovery_received: isRecord(read.discovery),
        capabilities_received: isRecord(read.capabilities),
      });
      return;
    }

    if (step === 'fixture_content_create') {
      await client.remoteAcceptanceCreateContentFixture(runId);
      return;
    }

    const fixture = asRecord(job.fixture);
    const postId = asPositiveInt(fixture.post_id);

    if (step === 'content_plan') {
      if (postId < 1) throw new Error('content_fixture_missing');
      const result = await client.contentUpdatePlan({
        post_id: postId,
        fields: { title: 'Site Manager MCP Acceptance Fixture Updated' },
        reason: 'Site Manager MCP acceptance content self-test',
      });
      const planId = extractPlanId(result);
      if (planId === '') throw new Error('content_plan_id_missing');
      await report(client, runId, step, true, { plan_id: planId });
      return;
    }

    if (step === 'content_apply_verify') {
      const planId = jobPlanId(job, 'content');
      if (planId === '') throw new Error('content_plan_missing');
      const result = await client.contentUpdateApply({ plan_id: planId, confirmed: true });
      const evidence = compactWriteEvidence(result);
      await report(client, runId, step, evidence.verified === true, evidence);
      return;
    }

    if (step === 'content_rollback') {
      const planId = jobPlanId(job, 'content');
      if (planId === '') throw new Error('content_plan_missing');
      const result = await client.contentUpdateRollback({ plan_id: planId, confirmed: true });
      const evidence = compactWriteEvidence(result);
      await report(client, runId, step, evidence.rollback_success === true || evidence.status === 'rolled-back', evidence);
      return;
    }

    if (step === 'fixture_navigation_create') {
      await client.remoteAcceptanceCreateNavigationFixture(runId);
      return;
    }

    const menuItemId = asPositiveInt(fixture.menu_item_id);
    if (step === 'navigation_plan') {
      if (menuItemId < 1) throw new Error('navigation_fixture_missing');
      const result = await client.navigationUpdatePlan({
        provider: 'wordpress-classic-menu',
        target_id: menuItemId,
        fields: { attr_title: 'Site Manager MCP acceptance temporary' },
        reason: 'Site Manager MCP acceptance navigation self-test',
      });
      const planId = extractPlanId(result);
      if (planId === '') throw new Error('navigation_plan_id_missing');
      await report(client, runId, step, true, { plan_id: planId });
      return;
    }

    if (step === 'navigation_apply_verify') {
      const planId = jobPlanId(job, 'navigation');
      if (planId === '') throw new Error('navigation_plan_missing');
      const result = await client.navigationUpdateApply({ plan_id: planId, confirmed: true });
      const evidence = compactWriteEvidence(result);
      await report(client, runId, step, evidence.verified === true, evidence);
      return;
    }

    if (step === 'navigation_rollback') {
      const planId = jobPlanId(job, 'navigation');
      if (planId === '') throw new Error('navigation_plan_missing');
      const result = await client.navigationUpdateRollback({ plan_id: planId, confirmed: true });
      const evidence = compactWriteEvidence(result);
      await report(client, runId, step, evidence.rollback_success === true || evidence.status === 'rolled-back', evidence);
      return;
    }

    if (step === 'seo_plan') {
      if (postId < 1) throw new Error('seo_fixture_missing');
      const result = await client.seoUpdatePlan({
        post_id: postId,
        fields: { description: 'Site Manager MCP acceptance temporary description' },
        reason: 'Site Manager MCP acceptance SEO self-test',
      });
      const planId = extractPlanId(result);
      if (planId === '') throw new Error('seo_plan_id_missing');
      await report(client, runId, step, true, { plan_id: planId });
      return;
    }

    if (step === 'seo_apply_verify') {
      const planId = jobPlanId(job, 'seo');
      if (planId === '') throw new Error('seo_plan_missing');
      const result = await client.seoUpdateApply({ plan_id: planId, confirmed: true });
      const evidence = compactWriteEvidence(result);
      await report(client, runId, step, evidence.verified === true, evidence);
      return;
    }

    if (step === 'seo_rollback') {
      const planId = jobPlanId(job, 'seo');
      if (planId === '') throw new Error('seo_plan_missing');
      const result = await client.seoUpdateRollback({ plan_id: planId, confirmed: true });
      const evidence = compactWriteEvidence(result);
      await report(client, runId, step, evidence.rollback_success === true || evidence.status === 'rolled-back', evidence);
      return;
    }

    if (step === 'cleanup') {
      await client.remoteAcceptanceCleanup(runId);
      return;
    }

    await report(client, runId, step, false, { code: 'unsupported_acceptance_step' });
  } catch {
    if (step === 'cleanup') {
      throw new Error('remote_acceptance_cleanup_failed');
    }
    try {
      await report(client, runId, step, false, { code: 'bridge_step_failed' });
    } catch {
      throw new Error('remote_acceptance_report_failed');
    }
  }
}

export interface RemoteAcceptanceRunner {
  stop(): void;
}

export function startRemoteAcceptanceRunner(client: SiteManagerClient): RemoteAcceptanceRunner {
  let stopped = false;
  let timer: NodeJS.Timeout | undefined;
  let inFlight = false;

  const schedule = (delayMs: number): void => {
    if (stopped) return;
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => void tick(), delayMs);
  };

  const tick = async (): Promise<void> => {
    if (stopped || inFlight) return;
    inFlight = true;
    let delay = IDLE_POLL_MS;
    try {
      const job = await client.remoteAcceptanceJob();
      const status = asString(job.status);
      if (['queued', 'running', 'cleaning'].includes(status) && asString(job.next_step) !== '') {
        await executeRemoteAcceptanceStep(client, job);
        const requestedPause = Number(job.pause_ms ?? ACTIVE_MIN_PAUSE_MS);
        delay = Number.isFinite(requestedPause)
          ? Math.max(ACTIVE_MIN_PAUSE_MS, requestedPause)
          : ACTIVE_MIN_PAUSE_MS;
      }
    } catch {
      delay = IDLE_POLL_MS;
    } finally {
      inFlight = false;
      schedule(delay);
    }
  };

  schedule(1000);

  return {
    stop(): void {
      stopped = true;
      if (timer) clearTimeout(timer);
    },
  };
}
