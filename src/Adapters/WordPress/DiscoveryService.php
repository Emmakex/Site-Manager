<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\PlatformIdentity;
use SiteManager\Core\Support\CanonicalJson;

final class DiscoveryService
{
    public function __construct(
        private readonly ?ProviderDetector $providers = null,
        private readonly ?SeoOwnershipProbe $seo = null,
    ) {}

    public function detect(): PlatformIdentity
    {
        return new PlatformIdentity(
            platform: 'wordpress',
            version: (string) get_bloginfo('version'),
            adapter: 'wordpress-core',
            metadata: [
                'php'=>PHP_VERSION,
                'multisite'=>is_multisite(),
                'locale'=>(string) get_locale(),
                'environment'=>function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production',
            ],
        );
    }

    public function discover(): DiscoverySnapshot
    {
        $platform = $this->detect();
        $inventory = $this->inventory();
        return new DiscoverySnapshot(
            schemaVersion: '1',
            generatedAt: gmdate(DATE_W3C),
            fingerprint: $this->fingerprint($platform, $inventory),
            platform: $platform,
            inventory: $inventory,
            metadata: ['source'=>'local-runtime'],
        );
    }

    /** @return array<string,mixed> */
    public function inventory(): array
    {
        $theme = wp_get_theme();
        $plugins = $this->activePlugins();
        $postTypes = [];
        foreach (get_post_types(['public'=>true], 'objects') as $name => $object) {
            $postTypes[] = [
                'name'=>(string) $name,
                'label'=>(string) ($object->labels->name ?? $name),
                'builtin'=>! empty($object->_builtin),
                'hierarchical'=>! empty($object->hierarchical),
                'show_in_rest'=>! empty($object->show_in_rest),
                'rest_base'=>(string) ($object->rest_base ?: $name),
                'supports'=>array_values(array_keys((array) get_all_post_type_supports((string) $name))),
            ];
        }

        $taxonomies = [];
        foreach (get_taxonomies(['public'=>true], 'objects') as $name => $object) {
            $taxonomies[] = [
                'name'=>(string) $name,
                'label'=>(string) ($object->labels->name ?? $name),
                'hierarchical'=>! empty($object->hierarchical),
                'show_in_rest'=>! empty($object->show_in_rest),
                'object_types'=>array_values((array) ($object->object_type ?? [])),
            ];
        }

        $themeInventory = [
            'name'=>(string) $theme->get('Name'),
            'stylesheet'=>(string) $theme->get_stylesheet(),
            'template'=>(string) $theme->get_template(),
            'version'=>(string) $theme->get('Version'),
            'child_theme'=>(bool) $theme->parent(),
            'block_theme'=>function_exists('wp_is_block_theme') ? (bool) wp_is_block_theme() : false,
            'supports'=>[
                'menus'=>current_theme_supports('menus'),
                'custom_logo'=>current_theme_supports('custom-logo'),
                'title_tag'=>current_theme_supports('title-tag'),
                'editor_styles'=>current_theme_supports('editor-styles'),
                'block_templates'=>current_theme_supports('block-templates'),
            ],
        ];
        $detectedProviders = ($this->providers ?? new ProviderDetector())->detect($plugins);

        $inventory = [
            'runtime'=>[
                'wordpress'=>(string) get_bloginfo('version'),
                'php'=>PHP_VERSION,
                'multisite'=>is_multisite(),
                'locale'=>(string) get_locale(),
                'timezone'=>(string) wp_timezone_string(),
                'debug'=>defined('WP_DEBUG') && WP_DEBUG,
            ],
            'urls'=>[
                'home'=>home_url('/'),
                'site'=>site_url('/'),
                'rest'=>rest_url(),
                'https'=>0 === strpos(home_url('/'), 'https://'),
            ],
            'routing'=>[
                'permalink_structure'=>(string) get_option('permalink_structure', ''),
                'show_on_front'=>(string) get_option('show_on_front', 'posts'),
                'front_page_id'=>(int) get_option('page_on_front', 0),
                'posts_page_id'=>(int) get_option('page_for_posts', 0),
            ],
            'indexability'=>[
                'search_visibility'=>'1' === (string) get_option('blog_public', '1') ? 'index' : 'discourage',
            ],
            'theme'=>$themeInventory,
            'content_types'=>$postTypes,
            'taxonomies'=>$taxonomies,
            'navigation'=>$this->navigationInventory(),
            'plugins'=>[
                'active_count'=>count($plugins),
                'items'=>$plugins,
            ],
            'providers'=>$detectedProviders,
        ];
        $inventory['seo_authority'] = ($this->seo ?? new SeoOwnershipProbe())->inspect($themeInventory, $detectedProviders);
        return $inventory;
    }

    /** @return array<string,mixed> */
    private function navigationInventory(): array
    {
        $classicMenus = [];
        $classicTargetCount = 0;
        if (function_exists('wp_get_nav_menus')) {
            foreach (array_slice((array) wp_get_nav_menus(['hide_empty'=>false]), 0, 100) as $menu) {
                $items = function_exists('wp_get_nav_menu_items') ? wp_get_nav_menu_items((int) $menu->term_id) : [];
                $itemIds = [];
                if (is_array($items)) {
                    foreach (array_slice($items, 0, 200) as $item) {
                        $itemIds[] = (int) ($item->ID ?? 0);
                    }
                    $itemIds = array_values(array_filter($itemIds));
                }
                $classicTargetCount += count($itemIds);
                $classicMenus[] = [
                    'term_id'=>(int) $menu->term_id,
                    'name'=>(string) $menu->name,
                    'slug'=>(string) $menu->slug,
                    'item_ids'=>$itemIds,
                    'item_count'=>(int) ($menu->count ?? count($itemIds)),
                    'targets_truncated'=>(int) ($menu->count ?? 0) > count($itemIds),
                ];
            }
        }

        $blockNavigation = [];
        if (post_type_exists('wp_navigation')) {
            $ids = get_posts([
                'post_type'=>'wp_navigation',
                'post_status'=>['publish','draft','private'],
                'posts_per_page'=>200,
                'fields'=>'ids',
                'orderby'=>'ID',
                'order'=>'ASC',
                'no_found_rows'=>true,
                'suppress_filters'=>true,
            ]);
            foreach ((array) $ids as $id) {
                $post = get_post((int) $id);
                if (! $post) { continue; }
                $blockNavigation[] = [
                    'post_id'=>(int) $post->ID,
                    'title'=>(string) $post->post_title,
                    'status'=>(string) $post->post_status,
                ];
            }
        }

        $providers = [];
        $classicDetected = current_theme_supports('menus') || [] !== $classicMenus;
        if ($classicDetected) {
            $providers[] = [
                'id'=>NativeNavigationRuntime::CLASSIC,
                'label'=>'WordPress classic menus',
                'detected'=>true,
                'actionable'=>$classicTargetCount > 0,
                'target_count'=>$classicTargetCount,
            ];
        }
        $blockTheme = function_exists('wp_is_block_theme') && wp_is_block_theme();
        if ($blockTheme || [] !== $blockNavigation) {
            $providers[] = [
                'id'=>NativeNavigationRuntime::BLOCK,
                'label'=>'WordPress block navigation',
                'detected'=>true,
                'actionable'=>[] !== $blockNavigation,
                'target_count'=>count($blockNavigation),
            ];
        }

        return [
            'providers'=>$providers,
            'registered_locations'=>function_exists('get_registered_nav_menus') ? get_registered_nav_menus() : [],
            'assigned_locations'=>function_exists('get_nav_menu_locations') ? get_nav_menu_locations() : [],
            'classic_menus'=>$classicMenus,
            'block_navigation'=>$blockNavigation,
        ];
    }

    /** @return array<string,array{name:string,version:string}> */
    private function activePlugins(): array
    {
        if (! function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $installed = function_exists('get_plugins') ? get_plugins() : [];
        $active = array_fill_keys(array_map('strval', (array) get_option('active_plugins', [])), true);
        if (is_multisite()) {
            foreach (array_keys((array) get_site_option('active_sitewide_plugins', [])) as $file) {
                $active[(string) $file] = true;
            }
        }

        $result = [];
        foreach (array_keys($active) as $file) {
            $data = is_array($installed[$file] ?? null) ? $installed[$file] : [];
            $result[$file] = ['name'=>(string) ($data['Name'] ?? $file),'version'=>(string) ($data['Version'] ?? '')];
        }
        ksort($result);
        return $result;
    }

    /** @param array<string,mixed> $inventory */
    private function fingerprint(PlatformIdentity $platform, array $inventory): string
    {
        $source = [
            'platform'=>$platform,
            'routing'=>$inventory['routing'] ?? [],
            'theme'=>$inventory['theme'] ?? [],
            'content_types'=>$inventory['content_types'] ?? [],
            'taxonomies'=>$inventory['taxonomies'] ?? [],
            'navigation'=>$inventory['navigation'] ?? [],
            'plugins'=>$inventory['plugins'] ?? [],
            'seo_authority'=>$this->seoFingerprintSource((array) ($inventory['seo_authority'] ?? [])),
        ];
        return hash('sha256', CanonicalJson::encode($source));
    }

    /** @param array<string,mixed> $seo @return array<string,mixed> */
    private function seoFingerprintSource(array $seo): array
    {
        return [
            'state'=>(string) ($seo['state'] ?? ''),
            'authority_resolved'=>! empty($seo['authority_resolved']),
            'authority'=>is_array($seo['authority'] ?? null) ? $seo['authority'] : null,
            'candidates'=>is_array($seo['candidates'] ?? null) ? $seo['candidates'] : [],
            'conflict'=>! empty($seo['conflict']),
            'write_adapter_ready'=>! empty($seo['write_adapter_ready']),
        ];
    }
}
