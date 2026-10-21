<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class AcceptanceTransportGate
{
    public static function boot(): void
    {
        $gate = new self();
        add_action('admin_notices', [$gate, 'renderNotice'], 1);
        add_action('admin_footer', [$gate, 'renderGateScript'], 99);
    }

    public function renderNotice(): void
    {
        if (! $this->isTestsPage() || ! current_user_can('manage_options')) {
            return;
        }
        $client = (new RemoteClientTracker())->publicState();
        $mcpConnected = ! empty($client['mcp_connected']);
        $prepared = ! empty($client['connection_enabled']);

        if ($mcpConnected) {
            $version = (string) ($client['client_version'] ?? '');
            echo '<div class="notice notice-success inline"><p><strong>'
                . esc_html__('Connected mode: MCP', 'site-manager')
                . '</strong> — '
                . esc_html(sprintf(__('MCP %s is active. Acceptance must run through MCP; the duplicate local sequence is disabled.', 'site-manager'), $version))
                . '</p></div>';
            return;
        }

        if ($prepared) {
            echo '<div class="notice notice-warning inline"><p><strong>'
                . esc_html__('Not connected to MCP', 'site-manager')
                . '</strong> — '
                . esc_html__('The connection credentials exist, but no MCP client has been seen recently. Local low-resource self-test mode is available.', 'site-manager')
                . '</p></div>';
            return;
        }

        echo '<div class="notice notice-info inline"><p><strong>'
            . esc_html__('Not connected', 'site-manager')
            . '</strong> — '
            . esc_html__('Local low-resource self-test mode is available. Connect MCP first when you want the end-to-end MCP acceptance path.', 'site-manager')
            . '</p></div>';
    }

    public function renderGateScript(): void
    {
        if (! $this->isTestsPage() || ! current_user_can('manage_options')) {
            return;
        }
        $client = (new RemoteClientTracker())->publicState();
        if (empty($client['mcp_connected'])) {
            return;
        }
        ?>
        <script>
        (() => {
          const start = document.getElementById('sm-acceptance-start');
          const cancel = document.getElementById('sm-acceptance-cancel');
          const message = document.getElementById('sm-acceptance-message');
          if (start) {
            start.disabled = true;
            start.textContent = <?php echo wp_json_encode(__('MCP test sequence', 'site-manager')); ?>;
            start.title = <?php echo wp_json_encode(__('Local duplicate testing is disabled while MCP is connected. The MCP acceptance runner will own this sequence.', 'site-manager')); ?>;
          }
          if (cancel) cancel.disabled = true;
          if (message) {
            message.textContent = <?php echo wp_json_encode(__('MCP connected: local duplicate test is disabled.', 'site-manager')); ?>;
          }
        })();
        </script>
        <?php
    }

    private function isTestsPage(): bool
    {
        return 'admin.php' === (string) ($GLOBALS['pagenow'] ?? '')
            && 'site-manager-tests' === sanitize_key((string) ($_GET['page'] ?? ''));
    }
}
