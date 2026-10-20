<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

/**
 * Bounded, read-only trace of data sources referenced by SEO-focused theme code.
 *
 * This tracer never executes theme source and never reads content/meta/option values.
 * It stores only API names, field/filter tokens and relative theme file paths.
 */
final class ThemeSeoDataSourceTracer
{
    private const MAX_FILES = 120;
    private const MAX_FILE_BYTES = 524288;
    private const MAX_MATCHES = 250;

    /** @var list<string> */
    private const SOURCE_FUNCTIONS = [
        'get_post', 'get_post_field', 'get_queried_object', 'get_the_ID',
        'get_the_title', 'single_post_title', 'post_type_archive_title',
        'get_the_excerpt', 'wp_trim_excerpt', 'wp_strip_all_tags', 'wp_trim_words', 'strip_shortcodes',
        'get_permalink', 'get_canonical_url', 'wp_get_canonical_url', 'home_url', 'site_url',
        'get_the_terms', 'get_term', 'get_terms', 'single_term_title', 'get_taxonomy',
        'get_bloginfo', 'get_option', 'get_site_option',
        'get_post_meta', 'get_metadata', 'metadata_exists',
        'get_field', 'get_fields', 'have_rows',
        'get_theme_mod', 'get_theme_mods',
        'get_author_posts_url', 'get_the_author_meta',
        'wp_get_attachment_image_url', 'get_the_post_thumbnail_url',
        'parse_blocks', 'render_block',
    ];

    /** @var list<string> */
    private const POST_FIELDS = [
        'post_title', 'post_excerpt', 'post_content', 'post_name', 'post_status',
        'post_type', 'post_parent', 'post_author', 'post_date', 'post_modified',
    ];

    /** @return array<string,mixed> */
    public function inspect(): array
    {
        if (! function_exists('wp_get_theme')) {
            return $this->emptyResult('wp-theme-api-unavailable');
        }

        $theme = wp_get_theme();
        $roots = [];
        foreach ([$theme->get_stylesheet_directory(), $theme->get_template_directory()] as $root) {
            $root = realpath((string) $root);
            if (false === $root || ! is_dir($root)) {
                continue;
            }
            $roots[$root] = true;
        }

        $files = [];
        foreach (array_keys($roots) as $root) {
            $this->collectPhpFiles($root, $root, $files);
            if (count($files) >= self::MAX_FILES) {
                break;
            }
        }
        $files = array_keys($files);
        sort($files, SORT_STRING);
        $files = array_slice($files, 0, self::MAX_FILES);

        $apiCalls = [];
        $postFields = [];
        $filterPipeline = [];
        $focusFiles = [];
        $scannedBytes = 0;

        foreach ($files as $file) {
            $size = @filesize($file);
            if (false === $size || $size <= 0 || $size > self::MAX_FILE_BYTES) {
                continue;
            }
            $source = @file_get_contents($file);
            if (! is_string($source)) {
                continue;
            }
            $relative = $this->relativeThemePath($file, array_keys($roots));
            if (! $this->isSeoFocusedSource($relative, $source)) {
                continue;
            }

            $focusFiles[$relative] = true;
            $scannedBytes += strlen($source);

            foreach (self::SOURCE_FUNCTIONS as $function) {
                if (1 === preg_match('/\\b' . preg_quote($function, '/') . '\\s*\\(/i', $source)) {
                    $apiCalls[$function][] = $relative;
                }
            }
            if (1 === preg_match('/\\$wpdb\\b/', $source)) {
                $apiCalls['$wpdb'][] = $relative;
            }

            foreach (self::POST_FIELDS as $field) {
                $quoted = '[\'\"]' . preg_quote($field, '/') . '[\'\"]';
                $property = '->\\s*' . preg_quote($field, '/') . '\\b';
                if (1 === preg_match('/(?:' . $quoted . '|' . $property . ')/i', $source)) {
                    $postFields[$field][] = $relative;
                }
            }

            foreach ($this->filterMatches($source) as $filter) {
                if (! $this->looksSeoRelated($filter) && ! $this->isSeoSurfaceFilter($filter)) {
                    continue;
                }
                $filterPipeline[$filter][] = $relative;
            }
        }

        $classification = [
            'native_post_content'=>$this->hasAny($apiCalls, ['get_post','get_post_field','get_the_title','get_the_excerpt']) || [] !== $postFields,
            'native_permalink'=>$this->hasAny($apiCalls, ['get_permalink','get_canonical_url','wp_get_canonical_url']),
            'native_taxonomy'=>$this->hasAny($apiCalls, ['get_the_terms','get_term','get_terms','single_term_title','get_taxonomy']),
            'site_identity'=>$this->hasAny($apiCalls, ['get_bloginfo','home_url','site_url']),
            'post_meta'=>$this->hasAny($apiCalls, ['get_post_meta','get_metadata','metadata_exists']),
            'options'=>$this->hasAny($apiCalls, ['get_option','get_site_option']),
            'acf'=>$this->hasAny($apiCalls, ['get_field','get_fields','have_rows']),
            'theme_mod'=>$this->hasAny($apiCalls, ['get_theme_mod','get_theme_mods']),
            'custom_database'=>isset($apiCalls['$wpdb']),
        ];

        return [
            'available'=>true,
            'mode'=>'bounded-static-seo-data-source-trace',
            'limits'=>[
                'max_files'=>self::MAX_FILES,
                'max_file_bytes'=>self::MAX_FILE_BYTES,
                'max_matches'=>self::MAX_MATCHES,
            ],
            'focus_files'=>array_slice(array_keys($focusFiles), 0, self::MAX_MATCHES),
            'focus_files_scanned'=>count($focusFiles),
            'bytes_scanned'=>$scannedBytes,
            'api_calls'=>$this->normalizeEvidenceMap($apiCalls),
            'post_fields'=>$this->normalizeEvidenceMap($postFields),
            'filter_pipeline'=>$this->normalizeEvidenceMap($filterPipeline),
            'classification'=>$classification,
            'write_adapter_ready'=>false,
        ];
    }

    /** @param array<string,true> $files */
    private function collectPhpFiles(string $root, string $directory, array &$files): void
    {
        if (count($files) >= self::MAX_FILES) {
            return;
        }
        $entries = @scandir($directory);
        if (! is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if (in_array($entry, ['.','..','.git','vendor','node_modules'], true)) {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                continue;
            }
            $real = realpath($path);
            if (false === $real || (0 !== strpos($real, $root . DIRECTORY_SEPARATOR) && $real !== $root)) {
                continue;
            }
            if (is_dir($real)) {
                $this->collectPhpFiles($root, $real, $files);
            } elseif (is_file($real) && str_ends_with(strtolower($real), '.php')) {
                $files[$real] = true;
            }
            if (count($files) >= self::MAX_FILES) {
                return;
            }
        }
    }

    private function isSeoFocusedSource(string $relative, string $source): bool
    {
        if (1 === preg_match('/(seo|schema|hreflang|open.?graph|breadcrumb|metadata|presenter|resolver|runtime|authority)/i', $relative)) {
            return true;
        }
        foreach (['seo_geo_','wp_robots','document_title','rel="canonical"','application/ld+json','hreflang','property="og:'] as $token) {
            if (false !== stripos($source, $token)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private function filterMatches(string $source): array
    {
        $found = [];
        if (! preg_match_all('/\\b(?:apply_filters|do_action)\\s*\\(\\s*([\'\"])([^\'\"]{1,191})\\1/i', $source, $matches)) {
            return [];
        }
        foreach ((array) ($matches[2] ?? []) as $value) {
            $found[] = (string) $value;
            if (count($found) >= self::MAX_MATCHES) {
                break;
            }
        }
        return array_values(array_unique($found));
    }

    /** @param array<string,list<string>> $map @return list<array{key:string,files:list<string>}> */
    private function normalizeEvidenceMap(array $map): array
    {
        ksort($map, SORT_STRING);
        $rows = [];
        foreach ($map as $key => $files) {
            $files = array_values(array_unique(array_map('strval', $files)));
            sort($files, SORT_STRING);
            $rows[] = ['key'=>(string) $key,'files'=>array_slice($files, 0, 20)];
            if (count($rows) >= self::MAX_MATCHES) {
                break;
            }
        }
        return $rows;
    }

    /** @param array<string,list<string>> $map @param list<string> $keys */
    private function hasAny(array $map, array $keys): bool
    {
        foreach ($keys as $key) {
            if (isset($map[$key])) {
                return true;
            }
        }
        return false;
    }

    /** @param list<string> $roots */
    private function relativeThemePath(string $file, array $roots): string
    {
        foreach ($roots as $root) {
            if (0 === strpos($file, $root . DIRECTORY_SEPARATOR)) {
                return basename($root) . '/' . ltrim(substr($file, strlen($root)), DIRECTORY_SEPARATOR);
            }
        }
        return basename($file);
    }

    private function looksSeoRelated(string $value): bool
    {
        return 1 === preg_match('/(?:^|[_\\-])(seo|geo|canonical|robots?|schema|meta|description|open.?graph|og|twitter|hreflang|sitemap)(?:$|[_\\-])/i', $value)
            || 1 === preg_match('/(canonical|robots|schema|hreflang|sitemap|open.?graph|seo|twitter)/i', $value);
    }

    private function isSeoSurfaceFilter(string $name): bool
    {
        return in_array($name, [
            'pre_get_document_title','document_title_parts','wp_robots','get_canonical_url',
            'language_attributes','robots_txt','wp_sitemaps_enabled','wp_sitemaps_posts_query_args',
        ], true);
    }

    /** @return array<string,mixed> */
    private function emptyResult(string $reason): array
    {
        return [
            'available'=>false,
            'reason'=>$reason,
            'mode'=>'bounded-static-seo-data-source-trace',
            'focus_files'=>[],
            'focus_files_scanned'=>0,
            'bytes_scanned'=>0,
            'api_calls'=>[],
            'post_fields'=>[],
            'filter_pipeline'=>[],
            'classification'=>[
                'native_post_content'=>false,
                'native_permalink'=>false,
                'native_taxonomy'=>false,
                'site_identity'=>false,
                'post_meta'=>false,
                'options'=>false,
                'acf'=>false,
                'theme_mod'=>false,
                'custom_database'=>false,
            ],
            'write_adapter_ready'=>false,
        ];
    }
}
