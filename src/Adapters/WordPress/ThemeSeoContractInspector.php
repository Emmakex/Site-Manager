<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

/**
 * Bounded, read-only static inspection of the active WordPress theme.
 *
 * The inspector never evaluates source files, never follows symlinks outside the
 * active theme roots, never persists file contents and never performs network IO.
 * It returns only structural evidence useful for deciding whether an SEO write
 * adapter can be implemented safely.
 */
final class ThemeSeoContractInspector
{
    private const MAX_FILES = 120;
    private const MAX_FILE_BYTES = 524288;
    private const MAX_MATCHES = 200;

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
        sort($files, SORT_STRING);
        $files = array_slice($files, 0, self::MAX_FILES);

        $metaKeys = [];
        $optionKeys = [];
        $hookRegistrations = [];
        $outputTokens = [];
        $scannedBytes = 0;
        $scannedFiles = 0;

        foreach ($files as $file) {
            $size = @filesize($file);
            if (false === $size || $size <= 0 || $size > self::MAX_FILE_BYTES) {
                continue;
            }
            $source = @file_get_contents($file);
            if (! is_string($source)) {
                continue;
            }
            ++$scannedFiles;
            $scannedBytes += strlen($source);
            $relative = $this->relativeThemePath($file, array_keys($roots));

            foreach ($this->quotedArgumentMatches($source, ['get_post_meta','update_post_meta','add_post_meta','delete_post_meta','register_post_meta'], 2) as $key) {
                if ($this->looksSeoRelated($key)) {
                    $metaKeys[$key][] = $relative;
                }
            }
            foreach ($this->quotedArgumentMatches($source, ['get_option','update_option','add_option','delete_option'], 1) as $key) {
                if ($this->looksSeoRelated($key)) {
                    $optionKeys[$key][] = $relative;
                }
            }
            foreach ($this->hookMatches($source) as $row) {
                if (! $this->looksSeoRelated((string) $row['hook']) && ! $this->isSeoSurfaceHook((string) $row['hook'])) {
                    continue;
                }
                $row['file'] = $relative;
                $hookRegistrations[] = $row;
                if (count($hookRegistrations) >= self::MAX_MATCHES) {
                    break;
                }
            }

            foreach (['rel="canonical"','name="robots"','property="og:','name="twitter:','application/ld+json','hreflang','wp_sitemaps','wp_robots','document_title'] as $token) {
                if (false !== stripos($source, $token)) {
                    $outputTokens[$token][] = $relative;
                }
            }
        }

        return [
            'available'=>true,
            'mode'=>'bounded-static-theme-source',
            'limits'=>[
                'max_files'=>self::MAX_FILES,
                'max_file_bytes'=>self::MAX_FILE_BYTES,
                'max_matches'=>self::MAX_MATCHES,
            ],
            'files_scanned'=>$scannedFiles,
            'bytes_scanned'=>$scannedBytes,
            'meta_keys'=>$this->normalizeEvidenceMap($metaKeys),
            'option_keys'=>$this->normalizeEvidenceMap($optionKeys),
            'hook_registrations'=>array_slice($this->uniqueRows($hookRegistrations), 0, self::MAX_MATCHES),
            'output_tokens'=>$this->normalizeEvidenceMap($outputTokens),
            'storage_evidence'=>[
                'post_meta'=>[] !== $metaKeys,
                'options'=>[] !== $optionKeys,
            ],
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
            if ('.' === $entry || '..' === $entry || '.git' === $entry || 'vendor' === $entry || 'node_modules' === $entry) {
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

    /** @param list<string> $functions @return list<string> */
    private function quotedArgumentMatches(string $source, array $functions, int $argumentPosition): array
    {
        $found = [];
        foreach ($functions as $function) {
            $prefix = 1 === $argumentPosition
                ? '\\b' . preg_quote($function, '/') . '\\s*\\(\\s*([\'\"])([^\'\"]{1,191})\\1'
                : '\\b' . preg_quote($function, '/') . '\\s*\\(\\s*[^,]{1,250},\\s*([\'\"])([^\'\"]{1,191})\\1';
            if (preg_match_all('/' . $prefix . '/i', $source, $matches)) {
                foreach ((array) ($matches[2] ?? []) as $value) {
                    $found[] = (string) $value;
                    if (count($found) >= self::MAX_MATCHES) {
                        break 2;
                    }
                }
            }
        }
        return array_values(array_unique($found));
    }

    /** @return list<array{kind:string,hook:string,callback:string}> */
    private function hookMatches(string $source): array
    {
        $rows = [];
        if (! preg_match_all('/\\b(add_action|add_filter)\\s*\\(\\s*([\'\"])([^\'\"]{1,191})\\2\\s*,\\s*([^,\\)\\n]{1,250})/i', $source, $matches, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($matches as $match) {
            $callback = trim((string) ($match[4] ?? ''));
            $callback = preg_replace('/\\s+/', ' ', $callback) ?? $callback;
            $rows[] = [
                'kind'=>strtolower((string) ($match[1] ?? '')),
                'hook'=>(string) ($match[3] ?? ''),
                'callback'=>substr($callback, 0, 200),
            ];
            if (count($rows) >= self::MAX_MATCHES) {
                break;
            }
        }
        return $rows;
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

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function uniqueRows(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            ksort($row);
            $encoded = function_exists('wp_json_encode') ? wp_json_encode($row) : json_encode($row);
            $key = hash('sha256', is_string($encoded) ? $encoded : serialize($row));
            $unique[$key] = $row;
        }
        return array_values($unique);
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

    private function isSeoSurfaceHook(string $hook): bool
    {
        return in_array($hook, [
            'wp_head','document_title_parts','pre_get_document_title','wp_robots',
            'get_canonical_url','wp_sitemaps_enabled','wp_sitemaps_posts_query_args',
            'language_attributes','template_redirect','wpseo_head','rank_math/head',
        ], true);
    }

    private function looksSeoRelated(string $value): bool
    {
        return 1 === preg_match('/(?:^|[_\\-])(seo|geo|canonical|robots?|schema|meta|description|open.?graph|og|twitter|hreflang|sitemap)(?:$|[_\\-])/i', $value)
            || 1 === preg_match('/(canonical|robots|schema|hreflang|sitemap|open.?graph|seo|twitter)/i', $value);
    }

    /** @return array<string,mixed> */
    private function emptyResult(string $reason): array
    {
        return [
            'available'=>false,
            'reason'=>$reason,
            'mode'=>'bounded-static-theme-source',
            'files_scanned'=>0,
            'bytes_scanned'=>0,
            'meta_keys'=>[],
            'option_keys'=>[],
            'hook_registrations'=>[],
            'output_tokens'=>[],
            'storage_evidence'=>['post_meta'=>false,'options'=>false],
            'write_adapter_ready'=>false,
        ];
    }
}
