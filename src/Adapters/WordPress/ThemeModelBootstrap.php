<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use InvalidArgumentException;
use LogicException;
use RuntimeException;
use SiteManager\Core\Model\ExecutionResult;
use SiteManager\Core\Model\OperationRequest;
use SiteManager\Core\Model\Plan;
use SiteManager\Core\Model\RollbackResult;
use SiteManager\Core\Model\VerificationResult;
use SiteManager\Core\Planning\PlanFactory;
use SiteManager\Core\Support\CanonicalJson;

/**
 * First-time adoption of a Theme-owned semantic homepage in an existing WP site.
 *
 * This adapter does not modify post_content, select a page or own rendering.
 * The Theme must accept the exact, fully-authored semantic contract.
 * Existing theme.update remains the separate operation after adoption.
 */
final class ThemeModelBootstrap
{
    private const MODEL_ID = 'corporate-home-v1';
    private const MAX_SLOTS = 100;
    private const MAX_TEXT_BYTES = 2000;
    private const MAX_LINK_LABEL_BYTES = 180;

    /** @param array<string,mixed> $fields */
    public function plan(array $fields, string $reason): Plan
    {
        $state = $this->state();
        $this->assertCanEdit($state['post_id']);
        if ($state['already_resolved'] || $state['previous_override'] !== null) {
            throw new LogicException('Theme homepage is already governed or has an existing provider.');
        }
        $slots = $this->validateFields($fields, $state['definitions'], $state['site_url']);
        $model = [
            'model_id'=>self::MODEL_ID,
            'source'=>'site-manager-native-override',
            'slots'=>$slots,
        ];
        $snapshot = (new WordPressAdapter())->discover();
        $request = new OperationRequest(
            capabilityId:'content.write',
            operation:'theme.bootstrap',
            payload:['slot_ids'=>array_keys($slots)],
            write:true,
            riskClass:'editorial-review',
            confirmationClass:'explicit',
            reason:$reason,
        );
        return (new PlanFactory())->create(
            adapter:'seo-geo-theme-semantic-bootstrap',
            fingerprint:$snapshot->fingerprint,
            request:$request,
            changes:[
                'target'=>['post_id'=>$state['post_id'], 'model_id'=>self::MODEL_ID],
                'precondition_fingerprint'=>$state['fingerprint'],
                'after_model'=>$model,
                'fields'=>array_keys($slots),
            ],
            snapshot:[
                'before_fingerprint'=>$state['fingerprint'],
                'before_override'=>$state['previous_override'],
                'post_content_hash'=>$state['post_content_hash'],
            ],
            reversible:true,
        );
    }

    /**
     * Validate all required Theme-owned text/link fields without adding evidence,
     * choosing a page, accepting arbitrary markup or allowing unapproved URLs.
     *
     * @param array<string,mixed> $fields
     * @param array<string,array<string,mixed>> $definitions
     * @return array<string,mixed>
     */
    public function validateFields(array $fields, array $definitions, string $siteUrl): array
    {
        if ([] === $fields || count($fields) > self::MAX_SLOTS || ! str_starts_with($siteUrl, 'https://')) {
            throw new InvalidArgumentException('Semantic adoption requires bounded content and HTTPS site identity.');
        }
        $accepted = [];
        foreach ($fields as $id => $value) {
            $definition = is_string($id) ? ($definitions[$id] ?? null) : null;
            if (! is_array($definition) || ! is_string($id)
                || ! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $id)
                || ! empty($definition['requires_verification'])) {
                throw new InvalidArgumentException('Unsupported or evidence-gated semantic field.');
            }
            $type = $definition['type'] ?? null;
            if ($type === 'text') {
                if (! is_string($value) || ! $this->validText($value, self::MAX_TEXT_BYTES)) {
                    throw new InvalidArgumentException('Semantic text must be substantive plain text.');
                }
                $accepted[$id] = trim($value);
            } elseif ($type === 'link') {
                if (! is_array($value) || array_keys($value) !== ['label','url']
                    || ! is_string($value['label']) || ! $this->validText($value['label'], self::MAX_LINK_LABEL_BYTES)
                    || ! is_string($value['url']) || ! $this->validSiteLink($value['url'], $siteUrl)) {
                    throw new InvalidArgumentException('Semantic link must be an approved same-site HTTPS target.');
                }
                $accepted[$id] = ['label'=>trim($value['label']), 'url'=>$value['url']];
            } else {
                // No unverifiable proof claims, arbitrary lists or unsafe nested data.
                throw new InvalidArgumentException('Bootstrap currently accepts only text and internal links.');
            }
        }
        foreach ($definitions as $id => $definition) {
            if (true === ($definition['required'] ?? false) && ! array_key_exists($id, $accepted)) {
                throw new InvalidArgumentException('Every required semantic slot must be authored before adoption.');
            }
        }
        return $accepted;
    }

    public function apply(Plan $plan): ExecutionResult
    {
        $this->assertPlan($plan);
        $state = $this->state();
        $this->assertCanEdit($state['post_id']);
        if ($state['post_id'] !== (int) ($plan->changes['target']['post_id'] ?? 0)
            || $state['fingerprint'] !== ($plan->changes['precondition_fingerprint'] ?? null)
            || $state['already_resolved'] || $state['previous_override'] !== null) {
            return new ExecutionResult(false, 'stale-theme-bootstrap');
        }
        $model = $plan->changes['after_model'] ?? null;
        if (! is_array($model) || ($model['model_id'] ?? '') !== self::MODEL_ID
            || ($model['source'] ?? '') !== 'site-manager-native-override'
            || ! is_array($model['slots'] ?? null)) {
            return new ExecutionResult(false, 'invalid-theme-bootstrap');
        }
        try {
            $valid = $this->validateFields($model['slots'], $state['definitions'], $state['site_url']);
        } catch (InvalidArgumentException) {
            return new ExecutionResult(false, 'invalid-theme-bootstrap');
        }
        if ($valid !== $model['slots']) {
            return new ExecutionResult(false, 'noncanonical-theme-bootstrap');
        }
        if (! update_post_meta($state['post_id'], ThemeModelOverride::META_KEY, $model)) {
            return new ExecutionResult(false, 'theme-bootstrap-write-failed');
        }
        $after = $this->state();
        $ok = $after['post_id'] === $state['post_id']
            && $after['post_content_hash'] === $state['post_content_hash']
            && $after['previous_override'] === $model
            && $after['already_resolved']
            && $after['model_source'] === 'site-manager-native-override'
            && $after['model_slots'] === $valid;
        if (! $ok) {
            // Refuse to leave an invalid adoption active. No Gutenberg edits.
            if (get_post_meta($state['post_id'], ThemeModelOverride::META_KEY, true) === $model) {
                delete_post_meta($state['post_id'], ThemeModelOverride::META_KEY);
            }
            return new ExecutionResult(false, 'theme-bootstrap-readback-failed');
        }
        return new ExecutionResult(true, 'applied', [
            'post_id'=>$state['post_id'],
            'changed_slot_ids'=>array_keys($valid),
            'after_fingerprint'=>$after['fingerprint'],
        ], $plan->id);
    }

    public function verify(Plan $plan, ExecutionResult $execution): VerificationResult
    {
        $this->assertPlan($plan);
        if (! $execution->success) {
            return new VerificationResult(false, 'bootstrap-apply-not-successful');
        }
        $state = $this->state();
        $after = $plan->changes['after_model'];
        $ok = $state['post_id'] === (int) $plan->changes['target']['post_id']
            && $state['post_content_hash'] === $plan->snapshot['post_content_hash']
            && $state['fingerprint'] === ($execution->data['after_fingerprint'] ?? null)
            && $state['model_source'] === 'site-manager-native-override'
            && $state['model_slots'] === $after['slots'];
        return new VerificationResult($ok, $ok ? 'verified' : 'theme-bootstrap-verification-failed', [
            'post_id'=>$state['post_id'],
            'verified_slot_ids'=>array_keys($after['slots']),
            'fingerprint'=>$state['fingerprint'],
        ]);
    }

    public function rollback(Plan $plan, ExecutionResult $execution): RollbackResult
    {
        $this->assertPlan($plan);
        if (! $plan->reversible || ! $execution->success) {
            return new RollbackResult(false, 'bootstrap-rollback-unavailable');
        }
        $state = $this->state();
        $this->assertCanEdit($state['post_id']);
        if ($state['post_id'] !== (int) $plan->changes['target']['post_id']
            || $state['fingerprint'] !== ($execution->data['after_fingerprint'] ?? null)
            || $state['post_content_hash'] !== $plan->snapshot['post_content_hash']) {
            return new RollbackResult(false, 'stale-bootstrap-rollback');
        }
        if ($plan->snapshot['before_override'] !== null) {
            return new RollbackResult(false, 'unexpected-bootstrap-owner');
        }
        delete_post_meta($state['post_id'], ThemeModelOverride::META_KEY);
        $restored = $this->state();
        $ok = ! $restored['already_resolved']
            && $restored['previous_override'] === null
            && $restored['fingerprint'] === $plan->snapshot['before_fingerprint'];
        return new RollbackResult($ok, $ok ? 'rolled-back' : 'bootstrap-rollback-verification-failed', [
            'post_id'=>$state['post_id'],
        ]);
    }

    /**
     * @return array{post_id:int,site_url:string,definitions:array<string,array<string,mixed>>,already_resolved:bool,model_source:string,model_slots:array<string,mixed>,previous_override:mixed,post_content_hash:string,fingerprint:string}
     */
    public function state(): array
    {
        if (! function_exists('seo_geo_theme_preset_document')
            || ! class_exists('SeoGeo\\Theme\\Strategic\\CorporateHomeModelResolver')
            || ! function_exists('get_option') || ! function_exists('get_post')
            || ! function_exists('get_post_meta') || ! function_exists('home_url')) {
            throw new LogicException('Theme-owned semantic contract is unavailable.');
        }
        $siteUrl = home_url('/');
        if (! is_string($siteUrl) || ! str_starts_with($siteUrl, 'https://')
            || str_contains($siteUrl, '?') || str_contains($siteUrl, '#')) {
            throw new LogicException('Theme bootstrap requires a safe HTTPS WordPress origin.');
        }
        $id = (int) get_option('page_on_front', 0);
        $post = $id > 0 ? get_post($id) : null;
        if (! is_object($post) || (int) ($post->ID ?? 0) !== $id
            || (string) ($post->post_type ?? '') !== 'page'
            || (string) ($post->post_status ?? '') !== 'publish') {
            throw new LogicException('Only the discovered published homepage may be adopted.');
        }
        $document = seo_geo_theme_preset_document('corporate', 'content-map.json');
        $contract = is_array($document) ? ($document['native_content_models'][self::MODEL_ID] ?? null) : null;
        $defs = is_array($contract) ? ($contract['slots'] ?? null) : null;
        if (! is_array($defs) || ! array_is_list($defs) || $defs === [] || count($defs) > self::MAX_SLOTS) {
            throw new LogicException('Theme semantic contract is invalid.');
        }
        $definitions = [];
        foreach ($defs as $entry) {
            $key = is_array($entry) ? ($entry['id'] ?? null) : null;
            if (! is_string($key) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $key)
                || isset($definitions[$key]) || ! in_array($entry['type'] ?? null, ['text','link','list'], true)) {
                throw new LogicException('Theme semantic slot definitions are ambiguous.');
            }
            $definitions[$key] = $entry;
        }
        $model = (new \SeoGeo\Theme\Strategic\CorporateHomeModelResolver())->resolve($post);
        $original = get_post_meta($id, ThemeModelOverride::META_KEY, true);
        $prior = is_array($original) ? $original : null;
        $hash = hash('sha256', (string) ($post->post_content ?? ''));
        $source = is_array($model) ? (string) ($model['source'] ?? 'unknown') : 'unavailable';
        $slots = is_array($model) && is_array($model['slots'] ?? null) ? $model['slots'] : [];
        $fingerprint = hash('sha256', CanonicalJson::encode([
            'site_url'=>$siteUrl,
            'post_id'=>$id,
            'post_modified_gmt'=>(string) ($post->post_modified_gmt ?? ''),
            'content_sha256'=>$hash,
            'contract'=>$definitions,
            'model_source'=>$source,
            'model_slots'=>$slots,
            'prior_override'=>$prior,
        ]));
        return [
            'post_id'=>$id, 'site_url'=>$siteUrl,
            'definitions'=>$definitions,
            'already_resolved'=>is_array($model),
            'model_source'=>$source,
            'model_slots'=>$slots,
            'previous_override'=>$prior,
            'post_content_hash'=>$hash,
            'fingerprint'=>$fingerprint,
        ];
    }

    private function validText(string $value, int $maxBytes): bool
    {
        $value = trim($value);
        return $value !== '' && strlen($value) <= $maxBytes
            && ! preg_match('/[\x00-\x1F\x7F]/', $value)
            && strip_tags($value) === $value
            && ! preg_match('/\b(lorem ipsum|placeholder|sustituye por|replace this|texto de ejemplo)\b/iu', $value);
    }

    private function validSiteLink(string $url, string $siteUrl): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url)
            || str_contains($url, '#') || str_contains($url, '?')
            || str_contains($url, '%') || str_contains($url, '@')) {
            return false;
        }
        $parts = parse_url($url);
        $base = parse_url($siteUrl);
        if (! is_array($parts) || ! is_array($base)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($base['host'] ?? ''))
            || isset($parts['user'], $parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '/');
        $root = rtrim((string) ($base['path'] ?? '/'), '/') . '/';
        return str_starts_with($path, $root)
            && ! str_contains($path, '//') && ! str_contains($path, '/./')
            && ! str_contains($path, '/../');
    }

    private function assertCanEdit(int $id): void
    {
        if ($id < 1 || ! function_exists('current_user_can')
            || ! current_user_can('edit_post', $id)) {
            throw new LogicException('Bound WordPress principal cannot edit the published homepage.');
        }
    }

    private function assertPlan(Plan $plan): void
    {
        if ($plan->adapter !== 'seo-geo-theme-semantic-bootstrap'
            || $plan->request->capabilityId !== 'content.write'
            || $plan->request->operation !== 'theme.bootstrap'
            || $plan->request->confirmationClass !== 'explicit') {
            throw new InvalidArgumentException('Invalid or unconfirmed Theme adoption plan.');
        }
    }
}
