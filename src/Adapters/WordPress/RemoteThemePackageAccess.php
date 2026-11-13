<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use DomainException;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;

/**
 * WordPress-side governed package plan / apply / rollback.
 *
 * The trusted Gateway, not WordPress users, resolves and verifies the official
 * CI artifact before invoking "plan". This class rechecks bytes, installed
 * identity, scopes and site generation without trusting a caller-supplied path.
 * No arbitrary downloads, URLs, PHP, shell or user-selected WP REST routes.
 */
final class RemoteThemePackageAccess
{
    public static function boot(): void
    {
        add_action('rest_api_init', static function (): void {
            foreach (['plan', 'apply', 'rollback'] as $stage) {
                register_rest_route('site-manager/v1', '/write/theme/package/' . $stage, [
                    'methods'=>\WP_REST_Server::CREATABLE,
                    'permission_callback'=>static fn(\WP_REST_Request $request): bool =>
                        RemoteWriteAccess::authorizeWriteRequest($request, 'theme.package.write'),
                    'callback'=>[self::class, $stage],
                ], true);
            }
        }, 36);
    }

    public static function plan(\WP_REST_Request $request): \WP_REST_Response
    {
        $input = $request->get_json_params();
        $required = ['theme_slug', 'package_sha256', 'package_base64', 'source_commit_sha', 'source_run_id', 'source_artifact_id'];
        if (! self::keys($input, $required, ['reason'])) {
            return self::error(400, 'invalid_theme_package_plan');
        }
        $slug = $input['theme_slug'];
        $digest = $input['package_sha256'];
        $base64 = $input['package_base64'];
        if (! is_string($slug) || ! is_string($digest)
            || ! is_string($base64) || strlen($base64) > 27_000_000
            || ! is_string($input['source_commit_sha'])
            || ! preg_match('/^[a-f0-9]{40}$/D', $input['source_commit_sha'])
            || ! is_int($input['source_run_id']) || $input['source_run_id'] < 1
            || ! is_int($input['source_artifact_id']) || $input['source_artifact_id'] < 1
            || (isset($input['reason']) && (! is_string($input['reason']) || strlen($input['reason']) > 500))) {
            return self::error(400, 'invalid_theme_package_plan');
        }

        $decoded = base64_decode($base64, true);
        unset($base64, $input['package_base64']);
        if (! is_string($decoded)) {
            return self::error(400, 'invalid_theme_package_encoding');
        }

        $stage = new ThemePackageStagingStore();
        $stored = null;
        try {
            $adapter = new WordPressAdapter();
            $snapshot = $adapter->discover();
            $capability = $adapter->capabilities($snapshot)->get('theme.package.write');
            if ($capability?->governable !== true) {
                throw new DomainException('theme_package_not_governable');
            }
            if (! function_exists('get_stylesheet') || get_stylesheet() !== $slug
                || ! function_exists('get_template') || get_template() !== $slug) {
                throw new DomainException('theme_package_not_active_or_child');
            }
            $stored = $stage->stage($decoded, $slug, $digest);
            unset($decoded);

            $previous = (new ThemePackageTreeFingerprint())->installed(
                (string) get_stylesheet_directory(), $slug
            );
            $connection = (new RemoteConnectionStore())->publicState();
            $connectionId = (string) ($connection['connection_id'] ?? '');
            $generation = (int) ($connection['generation'] ?? 0);
            if ($connectionId === '' || $generation < 1) {
                throw new DomainException('theme_package_connection_missing');
            }
            $plan = new Plan(
                id:'sm-plan-' . bin2hex(random_bytes(12)),
                adapter:'wordpress-theme-package',
                fingerprint:$snapshot->fingerprint,
                request:new OperationRequest(
                    capabilityId:'theme.package.write',
                    operation:'theme.package.deploy',
                    payload:[
                        'theme_slug'=>$slug,
                        'package_sha256'=>$digest,
                        'source_commit_sha'=>$input['source_commit_sha'],
                        'source_run_id'=>$input['source_run_id'],
                        'source_artifact_id'=>$input['source_artifact_id'],
                    ],
                    write:true,
                    riskClass:'code-deploy',
                    confirmationClass:'explicit',
                    reason:(string) ($input['reason'] ?? 'Official CI Theme update'),
                ),
                changes:[
                    'target'=>['theme_slug'=>$slug],
                    'before_tree_sha256'=>$previous['tree_sha256'],
                    'after_tree_sha256'=>$stored['tree_sha256'],
                    'package_sha256'=>$digest,
                    'theme_version'=>$stored['theme_version'],
                ],
                snapshot:[
                    'staged_package_path'=>$stored['path'], // WordPress private option only.
                    'staged_package_sha256'=>$digest,
                    'original_tree_sha256'=>$previous['tree_sha256'],
                ],
                reversible:true,
                createdAt:gmdate(DATE_W3C),
            );
            $saved = (new RemotePlanStore())->save($plan, $connectionId, $generation);
            return self::response(200, [
                'stage'=>'plan',
                'status'=>'planned',
                'plan'=>self::safeSummary($saved),
                'preview'=>[
                    'theme_slug'=>$slug,
                    'before_tree_sha256'=>$previous['tree_sha256'],
                    'after_tree_sha256'=>$stored['tree_sha256'],
                    'package_sha256'=>$digest,
                    'theme_version'=>$stored['theme_version'],
                    'file_count'=>$stored['file_count'],
                    'source_commit_sha'=>$input['source_commit_sha'],
                    'source_run_id'=>$input['source_run_id'],
                    'source_artifact_id'=>$input['source_artifact_id'],
                    'confirmation_required'=>true,
                    'values_or_paths_included'=>false,
                ],
            ]);
        } catch (DomainException $error) {
            if (is_array($stored)) {
                try { $stage->release($stored['path']); } catch (\Throwable) {}
            }
            return self::error(409, $error->getMessage());
        } catch (\Throwable) {
            if (is_array($stored)) {
                try { $stage->release($stored['path']); } catch (\Throwable) {}
            }
            return self::error(500, 'theme_package_plan_failed');
        }
    }

    public static function apply(\WP_REST_Request $request): \WP_REST_Response
    {
        $input = $request->get_json_params();
        if (! self::approved($input)) {
            return self::error(409, 'theme_package_confirmation_required');
        }
        $connection = (new RemoteConnectionStore())->publicState();
        $plans = new RemotePlanStore();
        $plan = $plans->pending(
            $input['plan_id'], (string) ($connection['connection_id'] ?? ''),
            (int) ($connection['generation'] ?? 0)
        );
        if (! $plan instanceof Plan || ! self::belongs($plan)) {
            return self::error(409, 'theme_package_plan_unavailable');
        }
        $stored = $plan->snapshot['staged_package_path'] ?? null;
        $payload = $plan->request->payload;
        $changes = $plan->changes;
        if (! is_string($stored)) {
            return self::error(409, 'theme_package_stage_unavailable');
        }

        try {
            $current = (new WordPressAdapter())->discover();
            if (! hash_equals($plan->fingerprint, $current->fingerprint)) {
                $plans->markRejected($plan, 'stale-site-package-plan');
                throw new DomainException('stale_site_package_plan');
            }
            $stage = new ThemePackageStagingStore();
            $proof = $stage->verify($stored, $payload['theme_slug'], $payload['package_sha256']);
            if ($proof['tree_sha256'] !== $changes['after_tree_sha256']) {
                throw new DomainException('theme_package_stage_mismatch');
            }
            $result = (new ThemePackageInstallEngine())->apply(
                $stored,
                $payload['theme_slug'],
                $payload['package_sha256'],
                $changes['before_tree_sha256'],
                $changes['after_tree_sha256'],
                true
            );
            $success = ($result['verified'] ?? false) === true;
            $execution = new ExecutionResult(
                success:$success,
                status:(string) $result['status'],
                data:[
                    'theme_slug'=>$payload['theme_slug'],
                    'backup'=>$result['backup'] ?? null, // WordPress-only audit.
                    'before_tree_sha256'=>$changes['before_tree_sha256'],
                    'after_tree_sha256'=>$changes['after_tree_sha256'],
                ]
            );
            $verification = new VerificationResult(
                verified:$success,
                status:$success ? 'verified' : (string) $result['status'],
                evidence:[
                    'expected_tree_sha256'=>$changes['after_tree_sha256'],
                    'installed_readback_verified'=>$success,
                    'automatic_restore_verified'=>($result['recovered'] ?? false) === true,
                ]
            );
            $summary = $plans->markApplied($plan, $execution, $verification);
            try { $stage->release($stored); } catch (\Throwable) {}
            return self::response($success ? 200 : 409, [
                'stage'=>'apply',
                'status'=>$result['status'],
                'verified'=>$success,
                'plan'=>self::safeSummary($summary),
                'verification'=>[
                    'verified'=>$success,
                    'status'=>$verification->status,
                    'installed_tree_sha256'=>$success ? $changes['after_tree_sha256'] : null,
                ],
                'rollback_available'=>$success && is_array($result['backup'] ?? null),
                'recovered'=>($result['recovered'] ?? false) === true,
            ]);
        } catch (DomainException $error) {
            return self::error(409, $error->getMessage());
        } catch (\Throwable) {
            return self::error(500, 'theme_package_apply_failed');
        }
    }

    public static function rollback(\WP_REST_Request $request): \WP_REST_Response
    {
        $input = $request->get_json_params();
        if (! self::approved($input)) {
            return self::error(409, 'theme_package_confirmation_required');
        }
        $connection = (new RemoteConnectionStore())->publicState();
        $plans = new RemotePlanStore();
        $record = $plans->applied(
            $input['plan_id'], (string) ($connection['connection_id'] ?? ''),
            (int) ($connection['generation'] ?? 0)
        );
        if (! is_array($record) || ! ($record['plan'] ?? null) instanceof Plan
            || ! ($record['execution'] ?? null) instanceof ExecutionResult
            || ! self::belongs($record['plan'])) {
            return self::error(409, 'theme_package_rollback_unavailable');
        }
        $plan = $record['plan'];
        $backup = $record['execution']->data['backup'] ?? null;
        if (! is_array($backup)) {
            return self::error(409, 'theme_package_backup_unavailable');
        }
        try {
            $result = (new ThemePackageInstallEngine())->rollback(
                $backup,
                (string) $plan->request->payload['theme_slug'],
                (string) $plan->changes['after_tree_sha256'],
                true
            );
            $verified = $result['verified'] === true;
            $summary = $plans->markRolledBack($plan, new RollbackResult(
                success:$verified,
                status:(string) $result['status'],
                evidence:['original_tree_restored'=>$verified],
            ));
            return self::response($verified ? 200 : 409, [
                'stage'=>'rollback',
                'status'=>$result['status'],
                'verified'=>$verified,
                'plan'=>self::safeSummary($summary),
                'restoration'=>[
                    'verified'=>$verified,
                    'tree_sha256'=>$verified ? $result['restored_tree_sha256'] : null,
                ],
            ]);
        } catch (DomainException $error) {
            return self::error(409, $error->getMessage());
        } catch (\Throwable) {
            return self::error(500, 'theme_package_rollback_failed');
        }
    }

    private static function belongs(Plan $plan): bool
    {
        return $plan->adapter === 'wordpress-theme-package'
            && $plan->request->capabilityId === 'theme.package.write'
            && $plan->request->operation === 'theme.package.deploy'
            && $plan->request->confirmationClass === 'explicit'
            && $plan->reversible === true;
    }

    /** @param mixed $value @param list<string> $required @param list<string> $optional */
    private static function keys(mixed $value, array $required, array $optional = []): bool
    {
        return is_array($value) && [] === array_diff($required, array_keys($value))
            && [] === array_diff(array_keys($value), array_merge($required, $optional));
    }

    private static function approved(mixed $value): bool
    {
        return self::keys($value, ['plan_id', 'confirmed'])
            && is_string($value['plan_id'])
            && preg_match('/^sm-plan-[a-f0-9]{24}$/D', $value['plan_id']) === 1
            && $value['confirmed'] === true;
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private static function safeSummary(array $summary): array
    {
        // RemotePlanStore retains backup locations and execution data inside
        // WordPress. Never serialize those back over REST/MCP/GitHub.
        return array_intersect_key($summary, array_flip([
            'plan_id', 'status', 'capability_id', 'operation', 'target',
            'reversible', 'confirmation_required', 'created_at',
            'expires_at', 'rollback_retained_until', 'applied_at', 'rolled_back_at',
        ]));
    }

    /** @param array<string,mixed> $data */
    private static function response(int $status, array $data): \WP_REST_Response
    {
        return new \WP_REST_Response(['data'=>$data], $status);
    }

    private static function error(int $status, string $code): \WP_REST_Response
    {
        // Domain error codes are constant strings; no path or content included.
        return new \WP_REST_Response(['error'=>['code'=>$code]], $status);
    }
}
