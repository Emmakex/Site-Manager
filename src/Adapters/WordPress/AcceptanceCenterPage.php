<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

use RuntimeException;
use Throwable;

final class AcceptanceCenterPage
{
    private const NONCE_ACTION = 'site_manager_acceptance';

    public static function boot(): void
    {
        $page = new self();
        add_action('admin_menu', [$page, 'registerMenu'], 25);
        add_action('wp_ajax_site_manager_acceptance_start', [$page, 'ajaxStart']);
        add_action('wp_ajax_site_manager_acceptance_advance', [$page, 'ajaxAdvance']);
        add_action('wp_ajax_site_manager_acceptance_cancel', [$page, 'ajaxCancel']);
    }

    public function registerMenu(): void
    {
        add_submenu_page(
            'site-manager',
            __('Site Manager Acceptance Center', 'site-manager'),
            __('Tests', 'site-manager'),
            'manage_options',
            'site-manager-tests',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access Site Manager.', 'site-manager'));
        }
        $service = new AcceptanceCenterService();
        $state = $service->current();
        $history = $service->history();
        $nonce = wp_create_nonce(self::NONCE_ACTION);
        ?>
        <div class="wrap sm-acceptance" style="max-width:1100px">
          <h1><?php echo esc_html__('Site Manager Acceptance Center', 'site-manager'); ?></h1>
          <p><?php echo esc_html__('Validate Site Manager from WordPress without terminal commands. The default low-resource profile executes exactly one bounded phase per request and never runs tests in parallel.', 'site-manager'); ?></p>

          <div class="notice notice-info inline" style="padding:12px 16px">
            <strong><?php echo esc_html__('Low-resource mode', 'site-manager'); ?></strong>
            <p style="margin:6px 0 0"><?php echo esc_html(sprintf(
                __('Concurrency: 1 · pause between phases: %d seconds · resumable after page reload · temporary fixtures are cleaned automatically.', 'site-manager'),
                (int) (AcceptanceCenterService::pauseMs() / 1000)
            )); ?></p>
          </div>

          <div id="sm-acceptance-summary" style="margin-top:18px">
            <?php $this->renderState($state); ?>
          </div>

          <p style="display:flex;gap:8px;align-items:center">
            <button type="button" class="button button-primary" id="sm-acceptance-start">
              <?php echo esc_html(empty($state) || in_array((string) ($state['status'] ?? ''), ['completed','failed'], true)
                  ? __('Start safe test sequence', 'site-manager')
                  : __('Resume test sequence', 'site-manager')); ?>
            </button>
            <button type="button" class="button" id="sm-acceptance-cancel" <?php disabled(! in_array((string) ($state['status'] ?? ''), ['running','cleaning'], true)); ?>>
              <?php echo esc_html__('Stop and clean up', 'site-manager'); ?>
            </button>
            <span class="spinner" id="sm-acceptance-spinner" style="float:none"></span>
            <span id="sm-acceptance-message" aria-live="polite"></span>
          </p>

          <h2><?php echo esc_html__('What the safe sequence does', 'site-manager'); ?></h2>
          <table class="widefat striped">
            <thead><tr><th><?php echo esc_html__('Phase', 'site-manager'); ?></th><th><?php echo esc_html__('Isolation / safety', 'site-manager'); ?></th></tr></thead>
            <tbody>
              <tr><td><?php echo esc_html__('Precheck', 'site-manager'); ?></td><td><?php echo esc_html__('Read-only capability/provider check.', 'site-manager'); ?></td></tr>
              <tr><td><?php echo esc_html__('Content', 'site-manager'); ?></td><td><?php echo esc_html__('Creates a private draft fixture, then plan → apply → verify → rollback.', 'site-manager'); ?></td></tr>
              <tr><td><?php echo esc_html__('Navigation', 'site-manager'); ?></td><td><?php echo esc_html__('Creates an unassigned temporary menu/item, then plan → apply → verify → rollback.', 'site-manager'); ?></td></tr>
              <tr><td><?php echo esc_html__('SEO', 'site-manager'); ?></td><td><?php echo esc_html__('Uses the temporary draft and the provider resolved by Site Manager; no provider is chosen by the dashboard.', 'site-manager'); ?></td></tr>
              <tr><td><?php echo esc_html__('Cleanup', 'site-manager'); ?></td><td><?php echo esc_html__('Deletes the temporary draft and menu even after a failed phase.', 'site-manager'); ?></td></tr>
            </tbody>
          </table>

          <h2><?php echo esc_html__('Recent runs', 'site-manager'); ?></h2>
          <?php $this->renderHistory($history); ?>
        </div>

        <style>
          .sm-acceptance-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:14px 0}
          .sm-acceptance-card{background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:12px}
          .sm-acceptance-card strong{display:block;margin-bottom:4px}
          .sm-status-completed{color:#008a20}.sm-status-failed{color:#b32d2e}.sm-status-running,.sm-status-cleaning{color:#996800}
          .sm-step-passed{color:#008a20}.sm-step-failed{color:#b32d2e}.sm-step-pending{color:#646970}
          #sm-acceptance-evidence{max-height:320px;overflow:auto;background:#fff;border:1px solid #dcdcde;padding:10px;white-space:pre-wrap;word-break:break-word}
        </style>

        <script>
        (() => {
          const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
          const nonce = <?php echo wp_json_encode($nonce); ?>;
          const defaultPause = <?php echo (int) AcceptanceCenterService::pauseMs(); ?>;
          const startButton = document.getElementById('sm-acceptance-start');
          const cancelButton = document.getElementById('sm-acceptance-cancel');
          const spinner = document.getElementById('sm-acceptance-spinner');
          const message = document.getElementById('sm-acceptance-message');
          let inFlight = false;
          let stopped = false;

          const request = async (action) => {
            const body = new URLSearchParams({action, nonce});
            const response = await fetch(ajaxUrl, {
              method: 'POST',
              credentials: 'same-origin',
              headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
              body: body.toString(),
            });
            const payload = await response.json();
            if (!payload.success) {
              const code = payload?.data?.code || 'request_failed';
              throw new Error(code);
            }
            return payload.data;
          };

          const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

          const render = (state) => {
            const root = document.getElementById('sm-acceptance-summary');
            const status = state?.status || 'not-started';
            const steps = state?.steps || [];
            const results = state?.results || {};
            const cards = steps.map((step, index) => {
              const result = results[step];
              const active = step === state?.next_step;
              const css = result?.status === 'passed' ? 'sm-step-passed' : result?.status === 'failed' ? 'sm-step-failed' : 'sm-step-pending';
              const marker = result?.status === 'passed' ? '✓' : result?.status === 'failed' ? '✕' : active ? '…' : '○';
              return `<div class="sm-acceptance-card ${css}"><strong>${marker} ${escapeHtml(step)}</strong><span>${escapeHtml(result?.status || (active ? 'next' : 'pending'))}</span></div>`;
            }).join('');
            root.innerHTML = `
              <p><strong><?php echo esc_js(__('Run status:', 'site-manager')); ?></strong> <span class="sm-status-${escapeHtml(status)}">${escapeHtml(status)}</span>
              ${state?.run_id ? ` · <code>${escapeHtml(state.run_id)}</code>` : ''}</p>
              <div class="sm-acceptance-grid">${cards}</div>
              ${state?.last_error ? `<p><strong><?php echo esc_js(__('Last error:', 'site-manager')); ?></strong> <code>${escapeHtml(state.last_error)}</code></p>` : ''}
              <details><summary><?php echo esc_js(__('View bounded evidence', 'site-manager')); ?></summary><pre id="sm-acceptance-evidence">${escapeHtml(JSON.stringify(results, null, 2))}</pre></details>`;
            cancelButton.disabled = !['running','cleaning'].includes(status);
            startButton.textContent = ['running','cleaning'].includes(status)
              ? <?php echo wp_json_encode(__('Resume test sequence', 'site-manager')); ?>
              : <?php echo wp_json_encode(__('Start safe test sequence', 'site-manager')); ?>;
          };

          const advanceLoop = async (state) => {
            if (stopped || !['running','cleaning'].includes(state?.status)) return;
            if (inFlight) return;
            inFlight = true;
            spinner.classList.add('is-active');
            message.textContent = <?php echo wp_json_encode(__('Running one safe phase…', 'site-manager')); ?>;
            try {
              const next = await request('site_manager_acceptance_advance');
              render(next);
              if (['running','cleaning'].includes(next.status) && !stopped) {
                message.textContent = <?php echo wp_json_encode(__('Phase complete. Waiting before the next phase…', 'site-manager')); ?>;
                const pause = Number(next.pause_ms || defaultPause);
                window.setTimeout(() => advanceLoop(next), Math.max(1000, pause));
              } else {
                message.textContent = next.status === 'completed'
                  ? <?php echo wp_json_encode(__('Safe test sequence completed.', 'site-manager')); ?>
                  : <?php echo wp_json_encode(__('Sequence finished with a failure; cleanup has been attempted.', 'site-manager')); ?>;
              }
            } catch (error) {
              message.textContent = <?php echo wp_json_encode(__('The sequence paused safely. Reload or press Resume to continue.', 'site-manager')); ?>;
            } finally {
              inFlight = false;
              spinner.classList.remove('is-active');
            }
          };

          startButton.addEventListener('click', async () => {
            if (inFlight) return;
            stopped = false;
            inFlight = true;
            spinner.classList.add('is-active');
            try {
              const state = await request('site_manager_acceptance_start');
              render(state);
              window.setTimeout(() => advanceLoop(state), 250);
            } catch (error) {
              message.textContent = <?php echo wp_json_encode(__('Could not start the safe test sequence.', 'site-manager')); ?>;
            } finally {
              inFlight = false;
              spinner.classList.remove('is-active');
            }
          });

          cancelButton.addEventListener('click', async () => {
            if (inFlight) return;
            stopped = true;
            inFlight = true;
            spinner.classList.add('is-active');
            try {
              const state = await request('site_manager_acceptance_cancel');
              render(state);
              stopped = false;
              window.setTimeout(() => advanceLoop(state), 250);
            } catch (error) {
              message.textContent = <?php echo wp_json_encode(__('Could not schedule cleanup.', 'site-manager')); ?>;
            } finally {
              inFlight = false;
              spinner.classList.remove('is-active');
            }
          });
        })();
        </script>
        <?php
    }

    public function ajaxStart(): void
    {
        $this->guardAjax();
        try {
            $this->sendState((new AcceptanceCenterService())->start());
        } catch (Throwable) {
            wp_send_json_error(['code'=>'start_failed'], 500);
        }
    }

    public function ajaxAdvance(): void
    {
        $this->guardAjax();
        try {
            $this->sendState((new AcceptanceCenterService())->advance());
        } catch (RuntimeException $e) {
            $code = in_array($e->getMessage(), ['acceptance_run_busy','acceptance_run_missing'], true)
                ? $e->getMessage()
                : 'advance_failed';
            wp_send_json_error(['code'=>$code], 'acceptance_run_busy' === $code ? 409 : 400);
        } catch (Throwable) {
            wp_send_json_error(['code'=>'advance_failed'], 500);
        }
    }

    public function ajaxCancel(): void
    {
        $this->guardAjax();
        try {
            $this->sendState((new AcceptanceCenterService())->cancel());
        } catch (Throwable) {
            wp_send_json_error(['code'=>'cancel_failed'], 500);
        }
    }

    private function guardAjax(): void
    {
        if (! current_user_can('manage_options')) {
            wp_send_json_error(['code'=>'forbidden'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    /** @param array<string,mixed> $state */
    private function sendState(array $state): void
    {
        $state['steps'] = AcceptanceCenterService::steps();
        $state['pause_ms'] = AcceptanceCenterService::pauseMs();
        wp_send_json_success($state);
    }

    /** @param array<string,mixed> $state */
    private function renderState(array $state): void
    {
        $status = (string) ($state['status'] ?? 'not-started');
        $results = is_array($state['results'] ?? null) ? $state['results'] : [];
        echo '<p><strong>' . esc_html__('Run status:', 'site-manager') . '</strong> <code>' . esc_html($status) . '</code></p>';
        echo '<div class="sm-acceptance-grid">';
        foreach (AcceptanceCenterService::steps() as $step) {
            $result = is_array($results[$step] ?? null) ? $results[$step] : [];
            $stepStatus = (string) ($result['status'] ?? ('running' === $status && $step === ($state['next_step'] ?? '') ? 'next' : 'pending'));
            $marker = 'passed' === $stepStatus ? '✓' : ('failed' === $stepStatus ? '✕' : ('next' === $stepStatus ? '…' : '○'));
            $class = 'passed' === $stepStatus ? 'sm-step-passed' : ('failed' === $stepStatus ? 'sm-step-failed' : 'sm-step-pending');
            echo '<div class="sm-acceptance-card ' . esc_attr($class) . '"><strong>' . esc_html($marker . ' ' . $step) . '</strong><span>' . esc_html($stepStatus) . '</span></div>';
        }
        echo '</div>';
        if (! empty($state['last_error'])) {
            echo '<p><strong>' . esc_html__('Last error:', 'site-manager') . '</strong> <code>' . esc_html((string) $state['last_error']) . '</code></p>';
        }
    }

    /** @param list<array<string,mixed>> $history */
    private function renderHistory(array $history): void
    {
        if ([] === $history) {
            echo '<p>' . esc_html__('No Acceptance Center runs recorded yet.', 'site-manager') . '</p>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Run', 'site-manager') . '</th><th>' . esc_html__('Status', 'site-manager') . '</th><th>' . esc_html__('Profile', 'site-manager') . '</th><th>' . esc_html__('Started', 'site-manager') . '</th><th>' . esc_html__('Finished', 'site-manager') . '</th></tr></thead><tbody>';
        foreach ($history as $row) {
            echo '<tr><td><code>' . esc_html((string) ($row['run_id'] ?? '')) . '</code></td><td>' . esc_html((string) ($row['status'] ?? '')) . '</td><td>' . esc_html((string) ($row['profile'] ?? '')) . '</td><td>' . esc_html((string) ($row['started_at'] ?? '')) . '</td><td>' . esc_html((string) ($row['finished_at'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
}
