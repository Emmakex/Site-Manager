<?php
declare(strict_types=1);

namespace SiteManager\Adapters\WordPress;

final class RemoteAcceptanceEvidenceNotice
{
    public static function boot(): void
    {
        add_action('admin_notices', [new self(), 'render'], 2);
    }

    public function render(): void
    {
        if (! $this->isTestsPage() || ! current_user_can('manage_options')) {
            return;
        }

        $state = (new RemoteAcceptanceDiagnostics())->state();
        if ('failed' !== (string) ($state['status'] ?? '')) {
            return;
        }

        $planEvidence = is_array($state['plan_evidence'] ?? null) ? $state['plan_evidence'] : [];
        if ([] === $planEvidence) {
            return;
        }

        $lastError = (string) ($state['last_error'] ?? '');
        $payload = [
            'run_id'=>(string) ($state['run_id'] ?? ''),
            'last_error'=>$lastError,
            'plans'=>$planEvidence,
        ];

        echo '<div class="notice notice-error inline" style="padding:12px 16px">';
        echo '<p><strong>' . esc_html__('MCP acceptance evidence', 'site-manager') . '</strong> — '
            . esc_html__('Bounded diagnostic evidence from the failed acceptance run. No bearer token or plaintext credential is included.', 'site-manager')
            . '</p>';
        echo '<details><summary>' . esc_html__('View failure evidence', 'site-manager') . '</summary>';
        echo '<pre style="max-height:360px;overflow:auto;background:#fff;padding:10px;border:1px solid #dcdcde">'
            . esc_html((string) wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
            . '</pre></details></div>';
    }

    private function isTestsPage(): bool
    {
        return 'admin.php' === (string) ($GLOBALS['pagenow'] ?? '')
            && 'site-manager-tests' === sanitize_key((string) ($_GET['page'] ?? ''));
    }
}
