<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class Plugin
{
    private const SNAPSHOT_OPTION = 'site_manager_discovery';
    private const DIRTY_OPTION = 'site_manager_discovery_dirty';

    public static function activate(): void
    {
        (new self())->refresh('activation');
    }

    public static function boot(): void
    {
        $plugin = new self();
        add_action('admin_menu', [$plugin, 'adminMenu']);
        add_action('admin_init', [$plugin, 'ensureCurrent'], 5);
        add_action('rest_api_init', [$plugin, 'registerRest']);
        add_action('admin_post_site_manager_refresh', [$plugin, 'handleRefresh']);
        add_action('activated_plugin', [$plugin, 'markDirty'], 10, 2);
        add_action('deactivated_plugin', [$plugin, 'markDirty'], 10, 2);
        add_action('switch_theme', [$plugin, 'markDirty'], 10, 2);
        add_action('upgrader_process_complete', [$plugin, 'markDirty'], 10, 2);

        foreach (['permalink_structure','show_on_front','page_on_front','page_for_posts','active_plugins'] as $option) {
            add_action('update_option_' . $option, [$plugin, 'markDirty'], 10, 3);
        }
        if (is_multisite()) {
            add_action('update_site_option_active_sitewide_plugins', [$plugin, 'markDirty'], 10, 3);
        }
    }

    public function markDirty(mixed ...$ignored): void
    {
        update_option(self::DIRTY_OPTION, '1', false);
    }

    /** @return array<string,mixed> */
    public function ensureCurrent(): array
    {
        $stored = get_option(self::SNAPSHOT_OPTION, []);
        if (! is_array($stored) || '1' === (string) get_option(self::DIRTY_OPTION, '1')) {
            return $this->refresh('structure-changed');
        }

        $current = (new WordPressAdapter())->discover();
        $knownFingerprint = (string) ($stored['snapshot']['fingerprint'] ?? '');
        if ($current->fingerprint !== $knownFingerprint) {
            return $this->persist($current, 'fingerprint-drift');
        }
        return $stored;
    }

    /** @return array<string,mixed> */
    public function refresh(string $reason = 'manual'): array
    {
        return $this->persist((new WordPressAdapter())->discover(), $reason);
    }

    /** @return array<string,mixed> */
    private function persist(\SiteManager\Core\Model\DiscoverySnapshot $snapshot, string $reason): array
    {
        $adapter = new WordPressAdapter();
        $graph = $adapter->capabilities($snapshot);
        $capabilities = $graph->all();
        $gaps = [];
        foreach ($capabilities as $capability) {
            if ($capability->detected && ! $capability->governable) {
                $gaps[] = [
                    'id'=>$capability->id,
                    'provider'=>$capability->provider,
                    'blockers'=>$capability->blockers,
                ];
            }
        }

        $previous = get_option(self::SNAPSHOT_OPTION, []);
        $state = [
            'schema_version'=>1,
            'generated_at'=>gmdate(DATE_W3C),
            'reason'=>sanitize_key($reason),
            'generation'=>(int) (is_array($previous) ? ($previous['generation'] ?? 0) : 0) + 1,
            'snapshot'=>$snapshot->jsonSerialize(),
            'capabilities'=>$graph->jsonSerialize(),
            'summary'=>$graph->summarize([]),
            'gaps'=>$gaps,
            'diagnostics'=>$adapter->diagnostics($snapshot),
        ];
        update_option(self::SNAPSHOT_OPTION, $state, false);
        delete_option(self::DIRTY_OPTION);
        return $state;
    }

    public function adminMenu(): void
    {
        add_management_page('Site Manager', 'Site Manager', 'manage_options', 'site-manager', [$this, 'renderAdmin']);
    }

    public function renderAdmin(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Site Manager.', 'site-manager'));
        }
        $state = $this->ensureCurrent();
        $snapshot = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : [];
        $inventory = is_array($snapshot['inventory'] ?? null) ? $snapshot['inventory'] : [];
        $theme = is_array($inventory['theme'] ?? null) ? $inventory['theme'] : [];
        $providers = is_array($inventory['providers'] ?? null) ? $inventory['providers'] : [];
        $summary = is_array($state['summary'] ?? null) ? $state['summary'] : [];
        $gaps = is_array($state['gaps'] ?? null) ? $state['gaps'] : [];
        ?>
        <div class="wrap" style="max-width:1100px">
          <h1><?php echo esc_html__('Site Manager', 'site-manager'); ?></h1>
          <p><?php echo esc_html__('This installation was discovered automatically. Detection describes the site; it does not grant write access.', 'site-manager'); ?></p>
          <p><strong><?php echo esc_html__('Last discovery:', 'site-manager'); ?></strong> <?php echo esc_html((string) ($state['generated_at'] ?? '')); ?> · <strong><?php echo esc_html__('Generation:', 'site-manager'); ?></strong> <?php echo esc_html((string) ($state['generation'] ?? 0)); ?></p>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="site_manager_refresh"><?php wp_nonce_field('site_manager_refresh'); ?>
            <?php submit_button(__('Re-detect site', 'site-manager'), 'secondary', 'submit', false); ?>
          </form>

          <h2><?php echo esc_html__('Discovered', 'site-manager'); ?></h2>
          <table class="widefat striped"><tbody>
            <tr><th>Platform</th><td><?php echo esc_html((string) ($snapshot['platform']['platform'] ?? '')); ?> <?php echo esc_html((string) ($snapshot['platform']['version'] ?? '')); ?></td></tr>
            <tr><th>PHP</th><td><?php echo esc_html((string) ($inventory['runtime']['php'] ?? '')); ?></td></tr>
            <tr><th>Theme</th><td><?php echo esc_html((string) ($theme['name'] ?? '')); ?> <?php echo esc_html((string) ($theme['version'] ?? '')); ?> <code><?php echo esc_html((string) ($theme['stylesheet'] ?? '')); ?></code></td></tr>
            <tr><th>Content types</th><td><?php echo esc_html((string) count((array) ($inventory['content_types'] ?? []))); ?></td></tr>
            <tr><th>Taxonomies</th><td><?php echo esc_html((string) count((array) ($inventory['taxonomies'] ?? []))); ?></td></tr>
            <tr><th>Active plugins</th><td><?php echo esc_html((string) ($inventory['plugins']['active_count'] ?? 0)); ?></td></tr>
            <tr><th>Fingerprint</th><td><code style="word-break:break-all"><?php echo esc_html((string) ($snapshot['fingerprint'] ?? '')); ?></code></td></tr>
          </tbody></table>

          <h2><?php echo esc_html__('Known providers detected', 'site-manager'); ?></h2>
          <?php if (! $providers) : ?><p><?php echo esc_html__('No known provider plugin detected.', 'site-manager'); ?></p><?php else : ?>
          <table class="widefat striped"><thead><tr><th>Provider</th><th>Version</th><th>State</th></tr></thead><tbody>
          <?php foreach ($providers as $provider) : ?><tr><td><?php echo esc_html((string) ($provider['label'] ?? $provider['id'] ?? '')); ?></td><td><?php echo esc_html(implode(', ', (array) ($provider['versions'] ?? []))); ?></td><td>DETECTED</td></tr><?php endforeach; ?>
          </tbody></table><?php endif; ?>

          <h2><?php echo esc_html__('Capability map', 'site-manager'); ?></h2>
          <p><?php echo esc_html(sprintf('Detected %d · Understood %d · Governable %d · Authorized %d · Blocked %d', (int) ($summary['detected'] ?? 0), (int) ($summary['understood'] ?? 0), (int) ($summary['governable'] ?? 0), (int) ($summary['authorized'] ?? 0), (int) ($summary['blocked'] ?? 0))); ?></p>

          <h2><?php echo esc_html__('Missing to govern', 'site-manager'); ?></h2>
          <?php if (! $gaps) : ?><p><?php echo esc_html__('No governance gaps detected.', 'site-manager'); ?></p><?php else : ?><ul><?php foreach ($gaps as $gap) : ?><li><code><?php echo esc_html((string) ($gap['id'] ?? '')); ?></code> — <?php echo esc_html(implode(' ', (array) ($gap['blockers'] ?? []))); ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <?php
    }

    public function handleRefresh(): void
    {
        if (! current_user_can('manage_options')) { wp_die('Forbidden'); }
        check_admin_referer('site_manager_refresh');
        $this->refresh('manual');
        wp_safe_redirect(admin_url('tools.php?page=site-manager'));
        exit;
    }

    public function registerRest(): void
    {
        foreach (['discovery','capabilities','diagnostics'] as $resource) {
            register_rest_route('site-manager/v1', '/' . $resource, [
                'methods'=>\WP_REST_Server::READABLE,
                'permission_callback'=>static fn(): bool => current_user_can('manage_options'),
                'callback'=>function () use ($resource): \WP_REST_Response {
                    $state = $this->ensureCurrent();
                    $data = 'discovery' === $resource ? ($state['snapshot'] ?? []) : ($state[$resource] ?? []);
                    return new \WP_REST_Response(['data'=>$data], 200);
                },
            ]);
        }
    }
}
