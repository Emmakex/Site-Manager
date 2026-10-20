# SEO provider ownership contract

Site Manager separates **ownership discovery** from **write governance**.

Detecting an SEO plugin, theme family, registered meta key, hook, or declared contract is evidence only. None of those signals grants `seo.write`.

## Resolution model

For each WordPress installation Site Manager builds `inventory.seo_authority` with:

- `state`
- `authority_resolved`
- `authority`
- `candidates`
- `conflict`
- `write_adapter_ready`
- `safe_to_write_seo_metadata`
- `outputs`
- bounded local evidence
- `next_action`

A single positively identified owner may be marked understood while still being non-governable. Multiple plausible owners produce a conflict and Site Manager must not select one silently.

## Optional provider declaration

Themes/plugins may describe SEO ownership through the WordPress filter:

```php
add_filter('site_manager_seo_provider_contracts', function (array $contracts): array {
    $contracts[] = [
        'id' => 'example-seo-provider',
        'type' => 'plugin', // or theme-native
        'label' => 'Example SEO Provider',
        'adapter' => 'example-seo-provider',
        'theme_stylesheets' => [],
        'plugin_files' => ['example-seo/example-seo.php'],
        'owned_outputs' => [
            'title-meta',
            'canonical',
            'robots',
            'open-graph',
            'schema',
        ],
    ];
    return $contracts;
});
```

The declaration must contain no credentials, secrets, arbitrary callbacks or direct database instructions. It is serialized as discovery evidence.

## Write-adapter rule

A provider declaration is **not** a write adapter.

Before `seo.write` becomes governable, the provider-specific implementation must prove all of the following:

1. the provider owns the target field/output;
2. the current value can be read before planning;
3. the plan is non-mutating;
4. the exact provider-owned state is snapshotted;
5. apply requires explicit confirmation;
6. stale target/provider state rejects apply;
7. writes are bounded to documented provider fields;
8. the resulting frontend/provider state is verified;
9. rollback restores the prior provider-owned state when technically reversible;
10. audit contains no secrets and identifies provider, target, plan and outcome.

Only code-backed provider adapters may set the final `safe_to_write_seo_metadata` decision. Discovery filters or external declarations must never be able to enable writes by themselves.

## Controlled SEO/GEO theme family

The existing SEO/GEO Starter family is recognized as a known `theme-native` owner under id `seo-geo-theme` when its stylesheet/template belongs to the `seo-geo-theme*` family.

Historical migration/readiness evidence identified these public output surfaces as theme-owned:

- title/meta
- canonical
- robots
- Open Graph
- schema
- hreflang
- sitemap

This built-in recognition is intentionally **read-only ownership evidence** until a concrete `seo-geo-theme-native` write adapter is implemented and verified against the current theme contract.

The theme is an acceptance fixture/provider, never a Site Manager Core dependency.
