<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class DiagnosticsPage
{
    private const SNAPSHOT_OPTION = 'site_manager_discovery';

    public static function boot(): void
    {
        add_action('admin_menu', [new self(), 'registerMenu'], 20);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'site-manager',
            __('Site Manager Diagnostics', 'site-manager'),
            __('Diagnostics', 'site-manager'),
            'manage_options',
            'site-manager-diagnostics',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Site Manager.', 'site-manager'));
        }
        $state = get_option(self::SNAPSHOT_OPTION, []);
        $snapshot = is_array($state['snapshot'] ?? null) ? $state['snapshot'] : [];
        $inventory = is_array($snapshot['inventory'] ?? null) ? $snapshot['inventory'] : [];
        $seo = is_array($inventory['seo_authority'] ?? null) ? $inventory['seo_authority'] : [];
        $evidence = is_array($seo['evidence'] ?? null) ? $seo['evidence'] : [];
        $inspection = is_array($evidence['theme_contract_inspection'] ?? null) ? $evidence['theme_contract_inspection'] : [];
        $trace = (new ThemeSeoDataSourceTracer())->inspect();

        $meta = array_slice((array) ($inspection['meta_keys'] ?? []), 0, 100);
        $options = array_slice((array) ($inspection['option_keys'] ?? []), 0, 100);
        $hooks = array_slice((array) ($inspection['hook_registrations'] ?? []), 0, 100);
        $tokens = array_slice((array) ($inspection['output_tokens'] ?? []), 0, 100);
        $apiCalls = array_slice((array) ($trace['api_calls'] ?? []), 0, 100);
        $postFields = array_slice((array) ($trace['post_fields'] ?? []), 0, 100);
        $filterPipeline = array_slice((array) ($trace['filter_pipeline'] ?? []), 0, 100);
        $classification = is_array($trace['classification'] ?? null) ? $trace['classification'] : [];
        ?>
        <div class="wrap" style="max-width:1200px">
          <h1><?php echo esc_html__('Site Manager Diagnostics', 'site-manager'); ?></h1>
          <p><?php echo esc_html__('Diagnostics are read-only evidence used to decide which adapters can be enabled safely.', 'site-manager'); ?></p>

          <h2><?php echo esc_html__('SEO theme contract inspection', 'site-manager'); ?></h2>
          <?php if (empty($inspection['available'])) : ?>
            <p><?php echo esc_html__('No bounded theme-source inspection is available for the current snapshot.', 'site-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped"><tbody>
              <tr><th><?php echo esc_html__('Inspection mode', 'site-manager'); ?></th><td><code><?php echo esc_html((string) ($inspection['mode'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('Theme PHP files scanned', 'site-manager'); ?></th><td><?php echo esc_html((string) ($inspection['files_scanned'] ?? 0)); ?></td></tr>
              <tr><th><?php echo esc_html__('Bytes scanned', 'site-manager'); ?></th><td><?php echo esc_html((string) ($inspection['bytes_scanned'] ?? 0)); ?></td></tr>
              <tr><th><?php echo esc_html__('SEO-related post meta keys', 'site-manager'); ?></th><td><?php echo esc_html((string) count($meta)); ?></td></tr>
              <tr><th><?php echo esc_html__('SEO-related option keys', 'site-manager'); ?></th><td><?php echo esc_html((string) count($options)); ?></td></tr>
              <tr><th><?php echo esc_html__('SEO hook registrations', 'site-manager'); ?></th><td><?php echo esc_html((string) count($hooks)); ?></td></tr>
              <tr><th><?php echo esc_html__('SEO output tokens', 'site-manager'); ?></th><td><?php echo esc_html((string) count($tokens)); ?></td></tr>
            </tbody></table>

            <h3><?php echo esc_html__('Post meta evidence', 'site-manager'); ?></h3>
            <?php $this->renderKeyEvidence($meta); ?>

            <h3><?php echo esc_html__('Option evidence', 'site-manager'); ?></h3>
            <?php $this->renderKeyEvidence($options); ?>

            <h3><?php echo esc_html__('Hook evidence', 'site-manager'); ?></h3>
            <?php if (! $hooks) : ?><p><?php echo esc_html__('No SEO-related hook registrations were found in the bounded theme scan.', 'site-manager'); ?></p><?php else : ?>
              <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Kind', 'site-manager'); ?></th><th><?php echo esc_html__('Hook', 'site-manager'); ?></th><th><?php echo esc_html__('Callback', 'site-manager'); ?></th><th><?php echo esc_html__('Theme file', 'site-manager'); ?></th></tr></thead><tbody>
                <?php foreach ($hooks as $row) : if (! is_array($row)) { continue; } ?>
                  <tr>
                    <td><code><?php echo esc_html((string) ($row['kind'] ?? '')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($row['hook'] ?? '')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($row['callback'] ?? '')); ?></code></td>
                    <td><code><?php echo esc_html((string) ($row['file'] ?? '')); ?></code></td>
                  </tr>
                <?php endforeach; ?>
              </tbody></table>
            <?php endif; ?>

            <h3><?php echo esc_html__('Output evidence', 'site-manager'); ?></h3>
            <?php $this->renderKeyEvidence($tokens); ?>
          <?php endif; ?>

          <h2><?php echo esc_html__('SEO data-source trace', 'site-manager'); ?></h2>
          <?php if (empty($trace['available'])) : ?>
            <p><?php echo esc_html__('No bounded SEO data-source trace is available for the current theme.', 'site-manager'); ?></p>
          <?php else : ?>
            <table class="widefat striped"><tbody>
              <tr><th><?php echo esc_html__('Trace mode', 'site-manager'); ?></th><td><code><?php echo esc_html((string) ($trace['mode'] ?? '')); ?></code></td></tr>
              <tr><th><?php echo esc_html__('SEO-focused PHP files scanned', 'site-manager'); ?></th><td><?php echo esc_html((string) ($trace['focus_files_scanned'] ?? 0)); ?></td></tr>
              <tr><th><?php echo esc_html__('SEO data-source API calls', 'site-manager'); ?></th><td><?php echo esc_html((string) count($apiCalls)); ?></td></tr>
              <tr><th><?php echo esc_html__('WordPress post-field references', 'site-manager'); ?></th><td><?php echo esc_html((string) count($postFields)); ?></td></tr>
              <tr><th><?php echo esc_html__('SEO filter pipeline entries', 'site-manager'); ?></th><td><?php echo esc_html((string) count($filterPipeline)); ?></td></tr>
            </tbody></table>

            <h3><?php echo esc_html__('Source classification', 'site-manager'); ?></h3>
            <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Source', 'site-manager'); ?></th><th><?php echo esc_html__('Detected', 'site-manager'); ?></th></tr></thead><tbody>
            <?php foreach ($classification as $source => $detected) : ?>
              <tr><td><code><?php echo esc_html((string) $source); ?></code></td><td><?php echo esc_html($detected ? __('YES', 'site-manager') : __('NO', 'site-manager')); ?></td></tr>
            <?php endforeach; ?>
            </tbody></table>

            <h3><?php echo esc_html__('Data-source API evidence', 'site-manager'); ?></h3>
            <?php $this->renderKeyEvidence($apiCalls); ?>

            <h3><?php echo esc_html__('Post-field evidence', 'site-manager'); ?></h3>
            <?php $this->renderKeyEvidence($postFields); ?>

            <h3><?php echo esc_html__('SEO filter-pipeline evidence', 'site-manager'); ?></h3>
            <?php $this->renderKeyEvidence($filterPipeline); ?>
          <?php endif; ?>

          <p class="description" style="margin-top:16px"><?php echo esc_html__('This inspection reads only bounded PHP source from the active theme/parent theme. It does not execute files, read content values, follow external symlinks, perform network requests or grant write access.', 'site-manager'); ?></p>
        </div>
        <?php
    }

    /** @param array<int,mixed> $rows */
    private function renderKeyEvidence(array $rows): void
    {
        if (! $rows) {
            echo '<p>' . esc_html__('No matching evidence found.', 'site-manager') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Key / token', 'site-manager') . '</th><th>' . esc_html__('Theme files', 'site-manager') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            if (! is_array($row)) { continue; }
            $files = array_values(array_filter(array_map('strval', (array) ($row['files'] ?? []))));
            echo '<tr><td><code>' . esc_html((string) ($row['key'] ?? '')) . '</code></td><td><code>' . esc_html(implode(', ', $files)) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }
}
