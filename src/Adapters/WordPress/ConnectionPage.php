<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class ConnectionPage
{
    public static function registerMenu(): void
    {
        add_submenu_page(
            'site-manager',
            __('Site Manager Connection', 'site-manager'),
            __('Connection', 'site-manager'),
            'manage_options',
            'site-manager-connection',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Site Manager.', 'site-manager'));
        }

        $adapter = new WordPressAdapter();
        $snapshot = $adapter->discover();
        $capabilities = $adapter->capabilities($snapshot)->jsonSerialize();
        $grantable = ScopePolicy::grantable($capabilities);
        $store = new RemoteConnectionStore();
        $oneTimeToken = '';
        $message = '';
        $error = '';

        if ('POST' === strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) {
            check_admin_referer('site_manager_connection');
            $action = sanitize_key((string) wp_unslash($_POST['site_manager_connection_action'] ?? ''));
            $rawScopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
                ? array_map(static fn($value): string => sanitize_text_field((string) wp_unslash($value)), $_POST['scopes'])
                : [];
            $scopes = ScopePolicy::filter($rawScopes, $grantable);
            if (count($rawScopes) !== count($scopes)) {
                $error = __('One or more requested scopes are not governable on this installation and were not granted.', 'site-manager');
            }

            if (in_array($action, ['generate','rotate'], true)) {
                $issued = $store->issue($scopes);
                $oneTimeToken = (string) ($issued['token'] ?? '');
                $message = 'rotate' === $action
                    ? __('Connection token rotated. The previous token is invalid immediately.', 'site-manager')
                    : __('Connection token generated.', 'site-manager');
            } elseif ('save_scopes' === $action) {
                $store->saveScopes($scopes);
                $message = __('Connection scopes saved.', 'site-manager');
            } elseif ('revoke' === $action) {
                $store->revoke();
                $message = __('Connection revoked. Its token can no longer authenticate.', 'site-manager');
            }

            (new Plugin())->refresh('connection-changed');
        }

        $connection = $store->publicState();
        $selectedScopes = (array) ($connection['scopes'] ?? []);
        if ('' === (string) ($connection['connection_id'] ?? '')) {
            $selectedScopes = array_values(array_intersect(['site.read','site.diagnostics'], $grantable));
        }
        ?>
        <div class="wrap" style="max-width:1100px">
          <h1><?php echo esc_html__('Site Manager Connection', 'site-manager'); ?></h1>
          <p><?php echo esc_html__('Create a revocable scoped connection for an external Site Manager client such as an MCP service. Detection never grants scopes automatically.', 'site-manager'); ?></p>

          <?php if ('' !== $message) : ?><div class="notice notice-success inline"><p><?php echo esc_html($message); ?></p></div><?php endif; ?>
          <?php if ('' !== $error) : ?><div class="notice notice-warning inline"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
          <?php if ('' !== $oneTimeToken) : ?>
            <div class="notice notice-warning inline">
              <p><strong><?php echo esc_html__('Copy this token now. It is shown only once and Site Manager does not store the plaintext token.', 'site-manager'); ?></strong></p>
              <p><code style="display:block;overflow-wrap:anywhere;padding:8px"><?php echo esc_html($oneTimeToken); ?></code></p>
              <p><?php echo esc_html__('Store it in the external client secret store. Do not paste it into ordinary chat messages.', 'site-manager'); ?></p>
            </div>
          <?php endif; ?>

          <h2><?php echo esc_html__('Connection status', 'site-manager'); ?></h2>
          <table class="widefat striped"><tbody>
            <tr><th><?php echo esc_html__('REST base', 'site-manager'); ?></th><td><code><?php echo esc_html(rest_url('site-manager/v1/')); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Connection ID', 'site-manager'); ?></th><td><code><?php echo esc_html((string) ($connection['connection_id'] ?? '')); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Enabled', 'site-manager'); ?></th><td><?php echo ! empty($connection['enabled']) ? esc_html__('YES', 'site-manager') : esc_html__('NO', 'site-manager'); ?></td></tr>
            <tr><th><?php echo esc_html__('Generation', 'site-manager'); ?></th><td><?php echo esc_html((string) ($connection['generation'] ?? 0)); ?></td></tr>
            <tr><th><?php echo esc_html__('Granted scopes', 'site-manager'); ?></th><td><code><?php echo esc_html(implode(', ', (array) ($connection['scopes'] ?? []))); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Last used', 'site-manager'); ?></th><td><?php echo esc_html((string) ($connection['last_used_at'] ?? '')); ?></td></tr>
          </tbody></table>

          <h2><?php echo esc_html__('Scopes', 'site-manager'); ?></h2>
          <p class="description"><?php echo esc_html__('Only scopes backed by currently governable capabilities are offered. Write scopes are never selected automatically.', 'site-manager'); ?></p>
          <form method="post">
            <?php wp_nonce_field('site_manager_connection'); ?>
            <table class="widefat striped"><thead><tr><th><?php echo esc_html__('Grant', 'site-manager'); ?></th><th><?php echo esc_html__('Scope', 'site-manager'); ?></th><th><?php echo esc_html__('Type', 'site-manager'); ?></th></tr></thead><tbody>
            <?php foreach ($grantable as $scope) : $isWrite = str_ends_with($scope, '.write'); ?>
              <tr>
                <td><input type="checkbox" name="scopes[]" value="<?php echo esc_attr($scope); ?>" <?php checked(in_array($scope, $selectedScopes, true)); ?>></td>
                <td><code><?php echo esc_html($scope); ?></code></td>
                <td><?php echo $isWrite ? esc_html__('Write', 'site-manager') : esc_html__('Read', 'site-manager'); ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody></table>
            <p>
            <?php if ('' === (string) ($connection['connection_id'] ?? '') || empty($connection['enabled'])) : ?>
              <button class="button button-primary" type="submit" name="site_manager_connection_action" value="generate"><?php echo esc_html__('Generate connection token', 'site-manager'); ?></button>
            <?php else : ?>
              <button class="button button-primary" type="submit" name="site_manager_connection_action" value="save_scopes"><?php echo esc_html__('Save scopes', 'site-manager'); ?></button>
              <button class="button" type="submit" name="site_manager_connection_action" value="rotate"><?php echo esc_html__('Rotate connection token', 'site-manager'); ?></button>
              <button class="button" type="submit" name="site_manager_connection_action" value="revoke" onclick="return confirm('<?php echo esc_js(__('Revoke this connection?', 'site-manager')); ?>')"><?php echo esc_html__('Revoke connection', 'site-manager'); ?></button>
            <?php endif; ?>
            </p>
          </form>
        </div>
        <?php
    }
}
