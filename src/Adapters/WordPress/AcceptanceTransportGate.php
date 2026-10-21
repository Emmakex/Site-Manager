<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use RuntimeException;
use Throwable;

final class AcceptanceTransportGate
{
    private const NONCE_ACTION = 'site_manager_remote_acceptance';

    public static function boot(): void
    {
        $gate = new self();
        add_action('admin_notices', [$gate, 'renderNotice'], 1);
        add_action('admin_footer', [$gate, 'renderGateScript'], 99);
        add_action('wp_ajax_site_manager_remote_acceptance_start', [$gate, 'ajaxStart']);
        add_action('wp_ajax_site_manager_remote_acceptance_status', [$gate, 'ajaxStatus']);
        add_action('wp_ajax_site_manager_remote_acceptance_cancel', [$gate, 'ajaxCancel']);
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
            $state = (new RemoteAcceptanceService())->bridgeState();
            echo '<div class="notice notice-success inline" style="padding:12px 16px"><p><strong>'
                . esc_html__('Connected mode: MCP', 'site-manager')
                . '</strong> — '
                . esc_html(sprintf(__('MCP %s is active. Acceptance will run once through MCP; the duplicate local sequence is disabled.', 'site-manager'), $version))
                . '</p><div id="sm-mcp-acceptance-state" data-state="' . esc_attr(wp_json_encode($state)) . '"></div></div>';
            return;
        }

        if ($prepared) {
            echo '<div class="notice notice-warning inline"><p><strong>'
                . esc_html__('Connection prepared · MCP not connected', 'site-manager')
                . '</strong> — '
                . esc_html__('Credentials exist, but no MCP client has authenticated recently. Local low-resource self-test mode is available.', 'site-manager')
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
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <script>
        (() => {
          const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
          const nonce = <?php echo wp_json_encode($nonce); ?>;
          const start = document.getElementById('sm-acceptance-start');
          const cancel = document.getElementById('sm-acceptance-cancel');
          const message = document.getElementById('sm-acceptance-message');
          const localSummary = document.getElementById('sm-acceptance-summary');
          const stateNode = document.getElementById('sm-mcp-acceptance-state');
          const activeStatuses = ['queued','running','cleaning'];
          const terminalStatuses = ['completed','failed'];
          let pollTimer = null;
          let busy = false;
          let sawActiveStatus = false;
          let terminalRefreshScheduled = false;

          if (localSummary) localSummary.style.display = 'none';
          if (start) start.textContent = <?php echo wp_json_encode(__('Start MCP test sequence', 'site-manager')); ?>;
          if (message) message.textContent = <?php echo wp_json_encode(__('MCP connected. The local duplicate sequence is disabled.', 'site-manager')); ?>;

          const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
          const request = async (action) => {
            const body = new URLSearchParams({action, nonce});
            const response = await fetch(ajaxUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
              body: body.toString(),
            });
            const payload = await response.json();
            if (!payload.success) {
              const data = payload?.data || {};
              const error = new Error(data.code || 'request_failed');
              error.data = data;
              throw error;
            }
            return payload.data;
          };

          const render = (state) => {
            if (!stateNode) return;
            const status = state?.status || 'idle';
            const steps = Array.isArray(state?.steps) ? state.steps : [];
            const results = state?.results || {};
            const active = activeStatuses.includes(status);
            if (active) sawActiveStatus = true;
            const cards = steps.map((step) => {
              const result = results[step];
              const isCurrent = step === state?.next_step && active;
              const stepStatus = result?.status || (isCurrent ? 'next' : 'pending');
              const marker = stepStatus === 'passed' ? '✓' : stepStatus === 'failed' ? '✕' : isCurrent ? '…' : '○';
              return `<span style="display:inline-block;margin:4px 8px 4px 0;padding:5px 8px;background:#fff;border:1px solid #dcdcde;border-radius:3px">${escapeHtml(marker)} ${escapeHtml(step)} · ${escapeHtml(stepStatus)}</span>`;
            }).join('');
            stateNode.innerHTML = `<p style="margin:8px 0 4px"><strong><?php echo esc_js(__('MCP run status:', 'site-manager')); ?></strong> <code>${escapeHtml(status)}</code>${state?.run_id ? ` · <code>${escapeHtml(state.run_id)}</code>` : ''}</p><div>${cards}</div>${state?.last_error ? `<p><strong><?php echo esc_js(__('Last error:', 'site-manager')); ?></strong> <code>${escapeHtml(state.last_error)}</code></p>` : ''}`;
            if (cancel) cancel.disabled = !active;
            if (start) start.disabled = active;
            if (active) {
              schedulePoll();
            } else if (sawActiveStatus && terminalStatuses.includes(status) && !terminalRefreshScheduled) {
              terminalRefreshScheduled = true;
              if (pollTimer) window.clearTimeout(pollTimer);
              window.setTimeout(() => window.location.reload(), 100);
            }
          };

          const schedulePoll = () => {
            if (pollTimer) window.clearTimeout(pollTimer);
            pollTimer = window.setTimeout(async () => {
              try {
                const state = await request('site_manager_remote_acceptance_status');
                render(state);
              } catch (_) {
                if (message) message.textContent = <?php echo wp_json_encode(__('Could not refresh MCP acceptance status.', 'site-manager')); ?>;
              }
            }, 2000);
          };

          const initial = stateNode?.dataset?.state;
          if (initial) {
            try { render(JSON.parse(initial)); } catch (_) {}
          }

          start?.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (busy) return;
            busy = true;
            start.disabled = true;
            if (message) message.textContent = <?php echo wp_json_encode(__('Queueing one sequential MCP acceptance run…', 'site-manager')); ?>;
            try {
              const state = await request('site_manager_remote_acceptance_start');
              render(state);
              if (message) message.textContent = <?php echo wp_json_encode(__('MCP acceptance queued. One bounded phase will run at a time.', 'site-manager')); ?>;
            } catch (error) {
              const missing = Array.isArray(error?.data?.missing_scopes) ? error.data.missing_scopes.join(', ') : '';
              if (message) message.textContent = missing
                ? <?php echo wp_json_encode(__('MCP is connected but required scopes are missing: ', 'site-manager')); ?> + missing
                : <?php echo wp_json_encode(__('Could not start the MCP acceptance sequence.', 'site-manager')); ?>;
              start.disabled = false;
            } finally {
              busy = false;
            }
          }, true);

          cancel?.addEventListener('click', async (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
            if (busy) return;
            busy = true;
            try {
              const state = await request('site_manager_remote_acceptance_cancel');
              render(state);
              if (message) message.textContent = <?php echo wp_json_encode(__('Cancellation requested. MCP will execute cleanup as the next phase.', 'site-manager')); ?>;
            } catch (_) {
              if (message) message.textContent = <?php echo wp_json_encode(__('Could not request MCP cleanup.', 'site-manager')); ?>;
            } finally {
              busy = false;
            }
          }, true);
        })();
        </script>
        <?php
    }

    public function ajaxStart(): void
    {
        $this->guardAjax();
        try {
            wp_send_json_success((new RemoteAcceptanceService())->start());
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $missing = [];
            if (str_starts_with($message, 'mcp_acceptance_missing_scopes:')) {
                $missing = array_values(array_filter(explode(',', substr($message, strlen('mcp_acceptance_missing_scopes:')))));
                $message = 'mcp_acceptance_missing_scopes';
            }
            wp_send_json_error(['code'=>$message,'missing_scopes'=>$missing], 409);
        } catch (Throwable) {
            wp_send_json_error(['code'=>'mcp_acceptance_start_failed'], 500);
        }
    }

    public function ajaxStatus(): void
    {
        $this->guardAjax();
        wp_send_json_success((new RemoteAcceptanceService())->bridgeState());
    }

    public function ajaxCancel(): void
    {
        $this->guardAjax();
        wp_send_json_success((new RemoteAcceptanceService())->cancel());
    }

    private function guardAjax(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['code'=>'forbidden'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private function isTestsPage(): bool
    {
        return 'admin.php' === (string) ($GLOBALS['pagenow'] ?? '')
            && 'site-manager-tests' === sanitize_key((string) ($_GET['page'] ?? ''));
    }
}
