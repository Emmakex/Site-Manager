<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class SeoOwnershipProbe
{
    private const SEO_PLUGIN_IDS = ['yoast-seo', 'rank-math', 'aioseo'];
    private const SEO_GEO_THEME_OUTPUTS = [
        'title-meta',
        'canonical',
        'robots',
        'open-graph',
        'schema',
        'hreflang',
        'sitemap',
    ];

    /**
     * Inspect only local WordPress runtime metadata and bounded active-theme source evidence.
     * This method never writes and never performs HTTP requests.
     *
     * @param array<string,mixed> $theme
     * @param list<array<string,mixed>> $providers
     * @return array<string,mixed>
     */
    public function inspect(array $theme, array $providers): array
    {
        $result = $this->resolve(
            theme: $theme,
            providers: $providers,
            registeredMeta: $this->registeredMetaCandidates(),
            hookNames: $this->seoHookNames(),
            contracts: $this->declaredContracts(),
        );

        $contractInspection = (new ThemeSeoContractInspector())->inspect();
        $dataSourceTrace = (new ThemeSeoDataSourceTracer())->inspect();
        $result['evidence']['theme_contract_inspection'] = $contractInspection;
        $result['evidence']['data_source_trace'] = $dataSourceTrace;

        $authority = is_array($result['authority'] ?? null) ? $result['authority'] : [];
        $ownerId = (string) ($authority['id'] ?? '');
        $classification = is_array($dataSourceTrace['classification'] ?? null)
            ? $dataSourceTrace['classification']
            : [];
        $metaKeys = is_array($contractInspection['meta_keys'] ?? null)
            ? $contractInspection['meta_keys']
            : [];

        $semanticNativeReady = 'seo-geo-theme' === $ownerId
            && ! empty($result['authority_resolved'])
            && empty($result['conflict'])
            && ! empty($contractInspection['available'])
            && ! empty($dataSourceTrace['available'])
            && ! empty($classification['native_post_content'])
            && ! empty($classification['native_permalink'])
            && empty($classification['acf'])
            && empty($classification['theme_mod'])
            && empty($classification['custom_database'])
            && [] === $metaKeys;

        if ($semanticNativeReady) {
            $result['state'] = 'resolved-semantic-native-write-ready';
            $result['write_adapter_ready'] = true;
            $result['safe_to_write_seo_metadata'] = true;
            $result['write_mode'] = 'semantic-native-sources';
            $result['writable_inputs'] = [
                'title'=>[
                    'source'=>'post_title',
                    'reversible'=>true,
                    'side_effect'=>'Changes the native WordPress title.',
                ],
                'description'=>[
                    'source'=>'post_excerpt',
                    'reversible'=>true,
                    'side_effect'=>'Changes the native WordPress excerpt and may affect visible excerpt surfaces.',
                ],
            ];
            $result['derived_outputs'] = [
                'title-meta'=>['sources'=>['post_title'],'direct_input'=>'title'],
                'open-graph'=>['sources'=>['post_title','post_excerpt','site-identity'],'direct_input'=>null],
                'schema'=>['sources'=>['post_title','post_excerpt','content','taxonomy','site-identity'],'direct_input'=>null],
                'canonical'=>['sources'=>['permalink'],'direct_input'=>null],
                'robots'=>['sources'=>['publication/indexability runtime'],'direct_input'=>null],
                'hreflang'=>['sources'=>['permalink','language-routing'],'direct_input'=>null],
                'sitemap'=>['sources'=>['publication-state','permalink'],'direct_input'=>null],
            ];
            $result['next_action'] = 'use-semantic-native-seo-adapter';

            foreach ((array) ($result['outputs'] ?? []) as $output => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row['state'] = in_array($output, ['title-meta','open-graph','schema'], true)
                    ? 'derived-from-governed-native-sources'
                    : 'derived-read-only';
                $row['writable'] = 'title-meta' === $output;
                $result['outputs'][$output] = $row;
            }
        }

        return $result;
    }

    /**
     * Pure resolver used by contract tests and by the local runtime probe.
     * A declaration or a detected owner never grants writes by itself.
     *
     * @param array<string,mixed> $theme
     * @param list<array<string,mixed>> $providers
     * @param list<array<string,mixed>> $registeredMeta
     * @param list<string> $hookNames
     * @param list<array<string,mixed>> $contracts
     * @return array<string,mixed>
     */
    public function resolve(
        array $theme,
        array $providers,
        array $registeredMeta = [],
        array $hookNames = [],
        array $contracts = [],
    ): array {
        $pluginCandidates = [];
        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }
            $id = (string) ($provider['id'] ?? '');
            if (! in_array($id, self::SEO_PLUGIN_IDS, true)) {
                continue;
            }
            $pluginCandidates[] = [
                'id'=>$id,
                'type'=>'plugin',
                'label'=>(string) ($provider['label'] ?? $id),
                'adapter'=>$id . '-seo',
                'evidence'=>['files'=>array_values((array) ($provider['files'] ?? []))],
            ];
        }

        $themeStylesheet = (string) ($theme['stylesheet'] ?? '');
        $themeTemplate = (string) ($theme['template'] ?? '');
        $contractCandidates = $this->matchedContracts($contracts, $themeStylesheet, $themeTemplate, $providers);

        $themeCandidates = [];
        if ($this->isSeoGeoTheme($themeStylesheet, $themeTemplate)) {
            $themeCandidates[] = [
                'id'=>'seo-geo-theme',
                'type'=>'theme-native',
                'label'=>'SEO/GEO Theme',
                'adapter'=>'seo-geo-theme-native',
                'outputs'=>self::SEO_GEO_THEME_OUTPUTS,
                'evidence'=>[
                    'stylesheet'=>$themeStylesheet,
                    'template'=>$themeTemplate,
                    'source'=>'known-theme-family',
                ],
            ];
        }

        $candidates = $this->uniqueCandidates(array_merge($contractCandidates, $themeCandidates, $pluginCandidates));
        $conflict = count($candidates) > 1;
        $authority = ! $conflict && 1 === count($candidates) ? $candidates[0] : null;
        $authorityResolved = is_array($authority);
        $ownerId = $authorityResolved ? (string) ($authority['id'] ?? '') : '';
        $outputs = $authorityResolved
            ? array_values(array_unique(array_map('strval', (array) ($authority['outputs'] ?? $this->defaultOutputsFor($ownerId)))))
            : [];

        $outputMap = [];
        foreach ($outputs as $output) {
            if ('' === $output) {
                continue;
            }
            $outputMap[$output] = [
                'owner'=>$ownerId,
                'resolved'=>true,
                'writable'=>false,
                'state'=>'ownership-resolved-write-adapter-pending',
            ];
        }

        return [
            'state'=>$conflict
                ? 'conflict'
                : ($authorityResolved ? 'resolved-write-adapter-pending' : 'unresolved'),
            'authority_resolved'=>$authorityResolved,
            'authority'=>$authority,
            'candidates'=>$candidates,
            'conflict'=>$conflict,
            // The pure resolver never makes a provider writable; inspect() may promote
            // a known provider only after bounded source evidence satisfies its contract.
            'write_adapter_ready'=>false,
            'safe_to_write_seo_metadata'=>false,
            'write_mode'=>null,
            'writable_inputs'=>[],
            'derived_outputs'=>[],
            'outputs'=>$outputMap,
            'evidence'=>[
                'registered_meta_candidates'=>array_slice($this->sanitizeRegisteredMeta($registeredMeta), 0, 100),
                'hook_name_candidates'=>array_slice($this->sanitizeHookNames($hookNames), 0, 100),
                'declared_contract_count'=>count($contractCandidates),
            ],
            'next_action'=>$authorityResolved
                ? 'inspect-provider-storage-and-implement-write-adapter'
                : ($conflict ? 'resolve-seo-owner-conflict' : 'identify-seo-owner'),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function registeredMetaCandidates(): array
    {
        if (! function_exists('get_registered_meta_keys') || ! function_exists('get_post_types')) {
            return [];
        }

        $found = [];
        foreach (array_keys((array) get_post_types(['public'=>true], 'names')) as $postType) {
            $keys = (array) get_registered_meta_keys('post', (string) $postType);
            foreach ($keys as $key => $args) {
                if (! $this->looksSeoRelated((string) $key)) {
                    continue;
                }
                $found[] = [
                    'post_type'=>(string) $postType,
                    'key'=>(string) $key,
                    'type'=>(string) (is_array($args) ? ($args['type'] ?? '') : ''),
                    'single'=>(bool) (is_array($args) ? ($args['single'] ?? false) : false),
                    'show_in_rest'=>(bool) (is_array($args) ? ($args['show_in_rest'] ?? false) : false),
                ];
                if (count($found) >= 100) {
                    return $found;
                }
            }
        }
        return $found;
    }

    /** @return list<string> */
    private function seoHookNames(): array
    {
        global $wp_filter;
        if (! is_array($wp_filter)) {
            return [];
        }
        $names = [];
        foreach (array_keys($wp_filter) as $name) {
            $name = (string) $name;
            if ($this->looksSeoRelated($name)) {
                $names[] = $name;
            }
            if (count($names) >= 100) {
                break;
            }
        }
        sort($names, SORT_STRING);
        return array_values(array_unique($names));
    }

    /** @return list<array<string,mixed>> */
    private function declaredContracts(): array
    {
        if (! function_exists('apply_filters')) {
            return [];
        }
        $declared = apply_filters('site_manager_seo_provider_contracts', []);
        if (! is_array($declared)) {
            return [];
        }
        return array_values(array_filter($declared, 'is_array'));
    }

    /**
     * @param list<array<string,mixed>> $contracts
     * @param list<array<string,mixed>> $providers
     * @return list<array<string,mixed>>
     */
    private function matchedContracts(array $contracts, string $stylesheet, string $template, array $providers): array
    {
        $activePluginFiles = [];
        foreach ($providers as $provider) {
            foreach ((array) ($provider['files'] ?? []) as $file) {
                $activePluginFiles[] = (string) $file;
            }
        }

        $matched = [];
        foreach ($contracts as $contract) {
            $id = sanitize_key((string) ($contract['id'] ?? ''));
            if ('' === $id) {
                continue;
            }
            $themeStylesheets = array_map('strval', (array) ($contract['theme_stylesheets'] ?? []));
            $pluginFiles = array_map('strval', (array) ($contract['plugin_files'] ?? []));
            $themeMatch = [] !== array_intersect([$stylesheet, $template], $themeStylesheets);
            $pluginMatch = [] !== array_intersect($activePluginFiles, $pluginFiles);
            if (! $themeMatch && ! $pluginMatch) {
                continue;
            }
            $outputs = array_values(array_filter(array_map('sanitize_key', (array) ($contract['owned_outputs'] ?? []))));
            $matched[] = [
                'id'=>$id,
                'type'=>sanitize_key((string) ($contract['type'] ?? ($themeMatch ? 'theme-native' : 'plugin'))),
                'label'=>sanitize_text_field((string) ($contract['label'] ?? $id)),
                'adapter'=>sanitize_key((string) ($contract['adapter'] ?? $id)),
                'outputs'=>$outputs,
                'evidence'=>[
                    'source'=>'declared-contract',
                    'theme_match'=>$themeMatch,
                    'plugin_match'=>$pluginMatch,
                ],
            ];
        }
        return $matched;
    }

    /** @param list<array<string,mixed>> $candidates @return list<array<string,mixed>> */
    private function uniqueCandidates(array $candidates): array
    {
        $unique = [];
        foreach ($candidates as $candidate) {
            $id = (string) ($candidate['id'] ?? '');
            if ('' === $id || isset($unique[$id])) {
                continue;
            }
            $unique[$id] = $candidate;
        }
        return array_values($unique);
    }

    /** @return list<string> */
    private function defaultOutputsFor(string $ownerId): array
    {
        return 'seo-geo-theme' === $ownerId
            ? self::SEO_GEO_THEME_OUTPUTS
            : ['title-meta','canonical','robots','open-graph','schema'];
    }

    private function isSeoGeoTheme(string $stylesheet, string $template): bool
    {
        return str_starts_with($stylesheet, 'seo-geo-theme') || str_starts_with($template, 'seo-geo-theme');
    }

    private function looksSeoRelated(string $value): bool
    {
        return 1 === preg_match('/(?:^|[_\-])(seo|geo|canonical|robots?|schema|meta|description|open.?graph|og|twitter|hreflang|sitemap)(?:$|[_\-])/i', $value)
            || 1 === preg_match('/(canonical|robots|schema|hreflang|sitemap|open.?graph)/i', $value);
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function sanitizeRegisteredMeta(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $result[] = [
                'post_type'=>sanitize_key((string) ($row['post_type'] ?? '')),
                'key'=>sanitize_key((string) ($row['key'] ?? '')),
                'type'=>sanitize_key((string) ($row['type'] ?? '')),
                'single'=>! empty($row['single']),
                'show_in_rest'=>! empty($row['show_in_rest']),
            ];
        }
        return $result;
    }

    /** @param list<string> $names @return list<string> */
    private function sanitizeHookNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            $name = preg_replace('/[^A-Za-z0-9_\-.\/]/', '', (string) $name) ?? '';
            if ('' !== $name) {
                $result[] = $name;
            }
        }
        sort($result, SORT_STRING);
        return array_values(array_unique($result));
    }
}
