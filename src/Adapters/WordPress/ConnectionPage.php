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
            $action = sanitize_key((string) wp_unslash($_POST['site_manager_connection_action'] ?? ''));
            if ('replace_cloned_identity' === $action) {
                check_admin_referer('site_manager_replace_cloned_identity');
                $oldId = sanitize_text_field((string) wp_unslash($_POST['expected_connection_id'] ?? ''));
                $confirmed = '1' === (string) ($_POST['confirm_cloned_identity_reset'] ?? '');
                if (! $confirmed) {
                    $error = __('Confirm that this WordPress is a separate cloned installation before replacing its connection identity.', 'site-manager');
                } else {
                    $issued = $store->replaceClonedIdentity($oldId);
                    if (null === $issued) {
                        $error = __('Connection changed or could not be replaced. Refresh the page before retrying.', 'site-manager');
                    } else {
                        $oneTimeToken = (string) $issued['token'];
                        $message = __('New WordPress connection identity created with read-only permissions. The previous token is invalid on this installation.', 'site-manager');
                        (new Plugin())->refresh('connection-changed');
                    }
                }
            } else {
                check_admin_referer('site_manager_connection');
                $rawScopes = isset($_POST['scopes']) && is_array($_POST['scopes'])
                ? array_map(static fn($value): string => sanitize_text_field((string) wp_unslash($value)), $_POST['scopes'])
                : [];
            $scopes = ScopePolicy::filter($rawScopes, $grantable);
            if (count($rawScopes) !== count($scopes)) {
                $error = __('One or more requested scopes are not governable on this installation and were not granted.', 'site-manager');
            }
            $principalUserId = (int) get_current_user_id();

            if (in_array($action, ['generate','rotate'], true)) {
                $issued = $store->issue($scopes, $principalUserId);
                $oneTimeToken = (string) ($issued['token'] ?? '');
                $message = 'rotate' === $action
                    ? __('Connection token rotated. The previous token is invalid immediately.', 'site-manager')
                    : __('Connection token generated.', 'site-manager');
            } elseif ('save_scopes' === $action) {
                $store->saveScopes($scopes, $principalUserId);
                $message = __('Connection scopes saved.', 'site-manager');
            } elseif ('revoke' === $action) {
                $store->revoke();
                $message = __('Connection revoked. Its token can no longer authenticate.', 'site-manager');
            }

                (new Plugin())->refresh('connection-changed');
            }
        }

        $connection = $store->publicState();
        $client = (new RemoteClientTracker())->publicState();
        $selectedScopes = (array) ($connection['scopes'] ?? []);
        if ('' === (string) ($connection['connection_id'] ?? '')) {
            $selectedScopes = array_values(array_intersect(['site.read','site.diagnostics'], $grantable));
        }
        $mcpConnected = ! empty($client['mcp_connected']);
        $connectionPrepared = ! empty($client['connection_enabled']);
        $connectionLabel = $mcpConnected
            ? __('MCP connected', 'site-manager')
            : ($connectionPrepared ? __('Connection prepared · MCP not currently connected', 'site-manager') : __('Not connected', 'site-manager'));
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
          <div class="notice <?php echo $mcpConnected ? 'notice-success' : ($connectionPrepared ? 'notice-warning' : 'notice-info'); ?> inline" style="padding:10px 14px">
            <p style="margin:0"><strong><?php echo esc_html($connectionLabel); ?></strong></p>
          </div>
          <table class="widefat striped" style="margin-top:12px"><tbody>
            <tr><th><?php echo esc_html__('REST base', 'site-manager'); ?></th><td><code><?php echo esc_html(rest_url('site-manager/v1/')); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Connection ID', 'site-manager'); ?></th><td><code><?php echo esc_html((string) ($connection['connection_id'] ?? '')); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Credentials enabled', 'site-manager'); ?></th><td><?php echo ! empty($connection['enabled']) ? esc_html__('YES', 'site-manager') : esc_html__('NO', 'site-manager'); ?></td></tr>
            <tr><th><?php echo esc_html__('MCP connected', 'site-manager'); ?></th><td><?php echo $mcpConnected ? esc_html__('YES', 'site-manager') : esc_html__('NO', 'site-manager'); ?></td></tr>
            <tr><th><?php echo esc_html__('MCP version', 'site-manager'); ?></th><td><code><?php echo esc_html((string) ($client['client_version'] ?? '')); ?></code></td></tr>
            <tr><th><?php echo esc_html__('MCP last seen', 'site-manager'); ?></th><td><?php echo esc_html((string) ($client['last_seen_at'] ?? '')); ?></td></tr>
            <tr><th><?php echo esc_html__('Generation', 'site-manager'); ?></th><td><?php echo esc_html((string) ($connection['generation'] ?? 0)); ?></td></tr>
            <tr><th><?php echo esc_html__('Granted scopes', 'site-manager'); ?></th><td><code><?php echo esc_html(implode(', ', (array) ($connection['scopes'] ?? []))); ?></code></td></tr>
            <tr><th><?php echo esc_html__('Last authorized scope use', 'site-manager'); ?></th><td><?php echo esc_html((string) ($connection['last_used_at'] ?? '')); ?></td></tr>
          </tbody></table>
          <p class="description"><?php echo esc_html__('A generated token means the connection is prepared. Site Manager reports MCP connected only after an authenticated site-manager-mcp client has actually contacted this installation recently.', 'site-manager'); ?></p>

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

          <?php if ('' !== (string) ($connection['connection_id'] ?? '') && ! empty($connection['enabled'])) : ?>
          <h2><?php echo esc_html__('New identity for a cloned WordPress', 'site-manager'); ?></h2>
          <p><?php echo esc_html__('Use this only on the new installation when a database migration copied the Site Manager connection ID from another WordPress. Ordinary token rotation does not change the connection ID.', 'site-manager'); ?></p>
          <p><strong><?php echo esc_html__('This replaces the ID and token on this WordPress only, disconnects its old bearer, and grants only site.read and site.diagnostics. It does not change content or another WordPress installation.', 'site-manager'); ?></strong></p>
          <form method="post">
            <?php wp_nonce_field('site_manager_replace_cloned_identity'); ?>
            <input type="hidden" name="expected_connection_id" value="<?php echo esc_attr((string) $connection['connection_id']); ?>">
            <label><input type="checkbox" name="confirm_cloned_identity_reset" value="1" required>
              <?php echo esc_html__('I confirm this is the cloned WordPress installation and I want a new, independent connection identity.', 'site-manager'); ?>
            </label>
            <p><button type="submit" class="button" name="site_manager_connection_action" value="replace_cloned_identity"><?php echo esc_html__('Create new connection identity for this site', 'site-manager'); ?></button></p>
          </form>
          <?php endif; ?>
        </div>
        <?php
    }
}
