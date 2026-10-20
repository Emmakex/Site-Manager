<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use SiteManager\Core\Model\DiscoverySnapshot;
use SiteManager\Core\Model\PlatformIdentity;
use SiteManager\Core\Support\CanonicalJson;

final class DiscoveryService
{
    public function __construct(private readonly ?ProviderDetector $providers = null) {}

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

        return [
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
            'theme'=>[
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
            ],
            'content_types'=>$postTypes,
            'taxonomies'=>$taxonomies,
            'plugins'=>[
                'active_count'=>count($plugins),
                'items'=>$plugins,
            ],
            'providers'=>($this->providers ?? new ProviderDetector())->detect($plugins),
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
            'plugins'=>$inventory['plugins'] ?? [],
        ];
        return hash('sha256', CanonicalJson::encode($source));
    }
}
