<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/src/Adapters/WordPress/AcceptanceTransportGate.php');
if (! is_string($source) || '' === $source) {
    fwrite(STDERR, "FAIL: could not read AcceptanceTransportGate.php\n");
    exit(1);
}

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$assert(
    str_contains($source, "const activeStatuses = ['queued','running','cleaning'];"),
    'terminal refresh must define the active MCP states explicitly'
);
$assert(
    str_contains($source, "const terminalStatuses = ['completed','failed'];"),
    'terminal refresh must define completed/failed as the only terminal reload states'
);
$assert(
    str_contains($source, 'let sawActiveStatus = false;'),
    'terminal refresh must start disarmed so an initially terminal page does not reload'
);
$assert(
    str_contains($source, 'if (active) sawActiveStatus = true;'),
    'terminal refresh must arm only after observing an active run in the current browser session'
);
$assert(
    str_contains($source, 'sawActiveStatus && terminalStatuses.includes(status) && !terminalRefreshScheduled'),
    'terminal refresh must require an active-to-terminal transition and a one-shot guard'
);
$assert(
    str_contains($source, 'terminalRefreshScheduled = true;'),
    'terminal refresh must be one-shot before reloading'
);
$assert(
    str_contains($source, 'window.setTimeout(() => window.location.reload(), 100);'),
    'terminal refresh must reload the Tests page after the terminal transition'
);

$initialStatePos = strpos($source, "const initial = stateNode?.dataset?.state;");
$armedDefaultPos = strpos($source, 'let sawActiveStatus = false;');
$assert(
    false !== $initialStatePos && false !== $armedDefaultPos && $armedDefaultPos < $initialStatePos,
    'initial terminal state must render with the refresh guard still disarmed'
);

echo "PASS: WordPress MCP Acceptance Center terminal refresh contract\n";
