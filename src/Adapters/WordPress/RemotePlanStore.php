<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;

final class RemotePlanStore
{
    public const OPTION = 'site_manager_remote_plans';
    public const TTL_SECONDS = 900;
    public const RETENTION_SECONDS = 86400;
    private const MAX_RECORDS = 30;

    /** @return array<string,mixed> */
    public function save(Plan $plan, string $connectionId, int $connectionGeneration): array
    {
        $records = $this->records();
        $now = time();
        $records[$plan->id] = [
            'schema_version'=>1,
            'connection_id'=>$connectionId,
            'connection_generation'=>$connectionGeneration,
            'status'=>'planned',
            'created_at'=>$plan->createdAt,
            'created_epoch'=>$now,
            'expires_epoch'=>$now + self::TTL_SECONDS,
            'retain_until_epoch'=>$now + self::RETENTION_SECONDS,
            'plan'=>$this->serializePlan($plan),
            'execution'=>null,
            'verification'=>null,
            'rollback'=>null,
            'applied_at'=>'',
            'rolled_back_at'=>'',
        ];
        $this->persist($records);
        return $this->summary($records[$plan->id]);
    }

    public function pending(string $planId, string $connectionId, int $connectionGeneration): ?Plan
    {
        $record = $this->record($planId, $connectionId, $connectionGeneration);
        if (null === $record
            || 'planned' !== (string) ($record['status'] ?? '')
            || (int) ($record['expires_epoch'] ?? 0) < time()
        ) {
            return null;
        }
        return $this->hydratePlan((array) ($record['plan'] ?? []));
    }

    /** @return array<string,mixed>|null */
    public function applied(string $planId, string $connectionId, int $connectionGeneration): ?array
    {
        $record = $this->record($planId, $connectionId, $connectionGeneration);
        if (null === $record || 'applied' !== (string) ($record['status'] ?? '')) {
            return null;
        }
        $plan = $this->hydratePlan((array) ($record['plan'] ?? []));
        $execution = $this->hydrateExecution((array) ($record['execution'] ?? []));
        if (null === $plan || null === $execution) {
            return null;
        }
        return ['plan'=>$plan,'execution'=>$execution,'record'=>$record];
    }

    public function markApplied(Plan $plan, ExecutionResult $execution, VerificationResult $verification): array
    {
        $records = $this->records();
        if (! isset($records[$plan->id]) || ! is_array($records[$plan->id])) {
            return [];
        }
        $records[$plan->id]['status'] = $execution->success ? 'applied' : 'apply-failed';
        $records[$plan->id]['execution'] = $execution->jsonSerialize();
        $records[$plan->id]['verification'] = $verification->jsonSerialize();
        $records[$plan->id]['applied_at'] = gmdate(DATE_W3C);
        $this->persist($records);
        return $this->summary($records[$plan->id]);
    }

    public function markRejected(Plan $plan, string $status, array $evidence = []): array
    {
        $records = $this->records();
        if (! isset($records[$plan->id]) || ! is_array($records[$plan->id])) {
            return [];
        }
        $records[$plan->id]['status'] = $status;
        $records[$plan->id]['verification'] = [
            'verified'=>false,
            'status'=>$status,
            'evidence'=>$evidence,
        ];
        $records[$plan->id]['applied_at'] = gmdate(DATE_W3C);
        $this->persist($records);
        return $this->summary($records[$plan->id]);
    }

    public function markRolledBack(Plan $plan, RollbackResult $rollback): array
    {
        $records = $this->records();
        if (! isset($records[$plan->id]) || ! is_array($records[$plan->id])) {
            return [];
        }
        $records[$plan->id]['status'] = $rollback->success ? 'rolled-back' : 'rollback-failed';
        $records[$plan->id]['rollback'] = $rollback->jsonSerialize();
        $records[$plan->id]['rolled_back_at'] = gmdate(DATE_W3C);
        $this->persist($records);
        return $this->summary($records[$plan->id]);
    }

    /** @return array<string,mixed>|null */
    private function record(string $planId, string $connectionId, int $connectionGeneration): ?array
    {
        if ('' === $planId || '' === $connectionId || $connectionGeneration < 1) {
            return null;
        }
        $records = $this->records();
        $record = $records[$planId] ?? null;
        if (! is_array($record)) {
            return null;
        }
        if ((string) ($record['connection_id'] ?? '') !== $connectionId
            || (int) ($record['connection_generation'] ?? 0) !== $connectionGeneration
            || (int) ($record['retain_until_epoch'] ?? 0) < time()
        ) {
            return null;
        }
        return $record;
    }

    /** @return array<string,array<string,mixed>> */
    private function records(): array
    {
        $stored = get_option(self::OPTION, []);
        if (! is_array($stored)) {
            return [];
        }
        $records = [];
        foreach ($stored as $id => $record) {
            if (is_string($id) && is_array($record)) {
                $records[$id] = $record;
            }
        }
        return $records;
    }

    /** @param array<string,array<string,mixed>> $records */
    private function persist(array $records): void
    {
        $now = time();
        foreach ($records as $id => $record) {
            if ((int) ($record['retain_until_epoch'] ?? 0) < $now) {
                unset($records[$id]);
            }
        }
        uasort($records, static fn(array $a, array $b): int => (int) ($b['created_epoch'] ?? 0) <=> (int) ($a['created_epoch'] ?? 0));
        if (count($records) > self::MAX_RECORDS) {
            $records = array_slice($records, 0, self::MAX_RECORDS, true);
        }
        update_option(self::OPTION, $records, false);
    }

    /** @return array<string,mixed> */
    private function serializePlan(Plan $plan): array
    {
        return [
            'id'=>$plan->id,
            'adapter'=>$plan->adapter,
            'fingerprint'=>$plan->fingerprint,
            'request'=>$plan->request->jsonSerialize(),
            'changes'=>$plan->changes,
            'snapshot'=>$plan->snapshot,
            'reversible'=>$plan->reversible,
            'created_at'=>$plan->createdAt,
        ];
    }

    /** @param array<string,mixed> $data */
    private function hydratePlan(array $data): ?Plan
    {
        $request = is_array($data['request'] ?? null) ? $data['request'] : [];
        if ('' === (string) ($data['id'] ?? '') || '' === (string) ($request['capability_id'] ?? '')) {
            return null;
        }
        $operationRequest = new OperationRequest(
            capabilityId: (string) ($request['capability_id'] ?? ''),
            operation: (string) ($request['operation'] ?? ''),
            payload: is_array($request['payload'] ?? null) ? $request['payload'] : [],
            write: ! empty($request['write']),
            riskClass: (string) ($request['risk_class'] ?? 'low'),
            confirmationClass: (string) ($request['confirmation_class'] ?? 'none'),
            reason: (string) ($request['reason'] ?? ''),
        );
        return new Plan(
            id: (string) $data['id'],
            adapter: (string) ($data['adapter'] ?? ''),
            fingerprint: (string) ($data['fingerprint'] ?? ''),
            request: $operationRequest,
            changes: is_array($data['changes'] ?? null) ? $data['changes'] : [],
            snapshot: is_array($data['snapshot'] ?? null) ? $data['snapshot'] : [],
            reversible: ! empty($data['reversible']),
            createdAt: (string) ($data['created_at'] ?? ''),
        );
    }

    /** @param array<string,mixed> $data */
    private function hydrateExecution(array $data): ?ExecutionResult
    {
        if (! array_key_exists('success', $data) || '' === (string) ($data['status'] ?? '')) {
            return null;
        }
        return new ExecutionResult(
            success: ! empty($data['success']),
            status: (string) $data['status'],
            data: is_array($data['data'] ?? null) ? $data['data'] : [],
            snapshotId: (string) ($data['snapshot_id'] ?? ''),
        );
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function summary(array $record): array
    {
        $plan = is_array($record['plan'] ?? null) ? $record['plan'] : [];
        $request = is_array($plan['request'] ?? null) ? $plan['request'] : [];
        $changes = is_array($plan['changes'] ?? null) ? $plan['changes'] : [];
        $fieldSource = is_array($changes['fields'] ?? null)
            ? $changes['fields']
            : (is_array($changes['semantic_fields'] ?? null) ? $changes['semantic_fields'] : []);
        return [
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'connection_id'=>(string) ($record['connection_id'] ?? ''),
            'connection_generation'=>(int) ($record['connection_generation'] ?? 0),
            'status'=>(string) ($record['status'] ?? ''),
            'capability_id'=>(string) ($request['capability_id'] ?? ''),
            'operation'=>(string) ($request['operation'] ?? ''),
            'target'=>is_array($changes['target'] ?? null) ? $changes['target'] : [],
            'fields'=>array_keys($fieldSource),
            'site_fingerprint'=>(string) ($plan['fingerprint'] ?? ''),
            'reversible'=>! empty($plan['reversible']),
            'confirmation_required'=>'explicit' === (string) ($request['confirmation_class'] ?? ''),
            'created_at'=>(string) ($record['created_at'] ?? ''),
            'expires_at'=>gmdate(DATE_W3C, (int) ($record['expires_epoch'] ?? 0)),
            'rollback_retained_until'=>gmdate(DATE_W3C, (int) ($record['retain_until_epoch'] ?? 0)),
            'applied_at'=>(string) ($record['applied_at'] ?? ''),
            'rolled_back_at'=>(string) ($record['rolled_back_at'] ?? ''),
            'execution'=>is_array($record['execution'] ?? null) ? $record['execution'] : null,
            'verification'=>is_array($record['verification'] ?? null) ? $record['verification'] : null,
            'rollback'=>is_array($record['rollback'] ?? null) ? $record['rollback'] : null,
        ];
    }
}
