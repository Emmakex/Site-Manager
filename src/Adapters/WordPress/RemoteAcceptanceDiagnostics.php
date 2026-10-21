<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class RemoteAcceptanceDiagnostics
{
    /** @return array<string,mixed> */
    public function state(): array
    {
        $state = (new RemoteAcceptanceService())->bridgeState();
        $artifacts = is_array($state['artifacts'] ?? null) ? $state['artifacts'] : [];
        if ([] === $artifacts) {
            return $state;
        }

        $connection = (new RemoteConnectionStore())->publicState();
        $connectionId = (string) ($connection['connection_id'] ?? '');
        $generation = (int) ($connection['generation'] ?? 0);
        $records = get_option(RemotePlanStore::OPTION, []);
        if (! is_array($records)) {
            return $state;
        }

        $evidence = [];
        foreach (['content','navigation','seo'] as $kind) {
            $planId = (string) ($artifacts[$kind . '_plan_id'] ?? '');
            if (! str_starts_with($planId, 'sm-plan-')) {
                continue;
            }
            $record = $records[$planId] ?? null;
            if (! is_array($record)
                || (string) ($record['connection_id'] ?? '') !== $connectionId
                || (int) ($record['connection_generation'] ?? 0) !== $generation
            ) {
                continue;
            }
            $evidence[$kind] = $this->recordEvidence($record);
        }

        if ([] !== $evidence) {
            $state['plan_evidence'] = $evidence;
        }
        return $state;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function recordEvidence(array $record): array
    {
        $plan = is_array($record['plan'] ?? null) ? $record['plan'] : [];
        $request = is_array($plan['request'] ?? null) ? $plan['request'] : [];
        $changes = is_array($plan['changes'] ?? null) ? $plan['changes'] : [];
        $execution = is_array($record['execution'] ?? null) ? $record['execution'] : [];
        $executionData = is_array($execution['data'] ?? null) ? $execution['data'] : [];
        $verification = is_array($record['verification'] ?? null) ? $record['verification'] : [];
        $verificationEvidence = is_array($verification['evidence'] ?? null) ? $verification['evidence'] : [];
        $rollback = is_array($record['rollback'] ?? null) ? $record['rollback'] : [];
        $rollbackEvidence = is_array($rollback['evidence'] ?? null) ? $rollback['evidence'] : [];

        $safeRollback = [];
        foreach (['provider','target_id','expected_fingerprint','actual_fingerprint','error'] as $key) {
            if (array_key_exists($key, $rollbackEvidence) && is_scalar($rollbackEvidence[$key])) {
                $safeRollback[$key] = substr((string) $rollbackEvidence[$key], 0, 500);
            }
        }
        if (is_array($rollbackEvidence['restored_fields'] ?? null)) {
            $safeRollback['restored_fields'] = array_values(array_slice(array_map('strval', $rollbackEvidence['restored_fields']), 0, 20));
        }
        if (is_array($rollbackEvidence['mismatch_fields'] ?? null)) {
            $safeRollback['mismatch_fields'] = array_values(array_slice(array_map('strval', $rollbackEvidence['mismatch_fields']), 0, 20));
        }

        $target = is_array($changes['target'] ?? null) ? $changes['target'] : [];
        $result = [
            'plan_id'=>(string) ($plan['id'] ?? ''),
            'status'=>(string) ($record['status'] ?? ''),
            'capability_id'=>(string) ($request['capability_id'] ?? ''),
            'operation'=>(string) ($request['operation'] ?? ''),
            'target'=>[
                'provider'=>(string) ($target['provider'] ?? ''),
                'target_id'=>(int) ($target['target_id'] ?? 0),
            ],
            'execution_status'=>(string) ($execution['status'] ?? ''),
            'after_fingerprint'=>(string) ($executionData['after_fingerprint'] ?? ''),
            'verification_status'=>(string) ($verification['status'] ?? ''),
            'verified'=>! empty($verification['verified']),
            'verification_fingerprint'=>(string) ($verificationEvidence['fingerprint'] ?? ''),
            'rollback_status'=>(string) ($rollback['status'] ?? ''),
            'rollback_success'=>! empty($rollback['success']),
            'rollback_evidence'=>$safeRollback,
            'applied_at'=>(string) ($record['applied_at'] ?? ''),
            'rolled_back_at'=>(string) ($record['rolled_back_at'] ?? ''),
        ];

        return $result;
    }
}
