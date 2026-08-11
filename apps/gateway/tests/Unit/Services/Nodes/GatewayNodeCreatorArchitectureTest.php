<?php

declare(strict_types=1);

it('keeps gateway node creation non-interactive', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Nodes/GatewayNodeCreator.php');

    foreach ([
        'Laravel\\Prompts',
        'isInteractiveInput',
        'validatePromptNodeName',
        'validatePromptHost',
        'activeIngressNodes',
    ] as $interactivePath) {
        expect($source)->not->toContain($interactivePath);
    }
});

it('does not retain the unreachable direct workload provisioning path', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Nodes/GatewayNodeCreator.php');

    foreach (['$failedAssignment = null', 'if ($requiresHostProvisioning'] as $unreachablePath) {
        expect($source)->not->toContain($unreachablePath);
    }
});

it('returns gateway action results without a hidden output buffer', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Nodes/GatewayNodeCreator.php');

    foreach ([
        'private ?string $output',
        'GatewayActionResult::fromJsonOutput',
        'private function line(',
        'private function error(',
        'private function info(',
        'private function wantsJson(',
    ] as $hiddenOutputPath) {
        expect($source)->not->toContain($hiddenOutputPath);
    }
});

it('does not store bootstrap phase or caller state between requests', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Nodes/GatewayNodeCreator.php');

    foreach ([
        'BOOTSTRAP_PHASE_',
        'private string $bootstrapPhase',
        'private ?Node $bootstrapCaller',
        'private ?NodeBootstrap $bootstrap',
        'NodeCreationContext',
        'NodeCreationPhase',
    ] as $requestState) {
        expect($source)->not->toContain($requestState);
    }
});

it('does not store raw request arguments between requests', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/Services/Nodes/GatewayNodeCreator.php');

    foreach (['private array $arguments', '$this->arguments =', 'private function argument('] as $requestState) {
        expect($source)->not->toContain($requestState);
    }
});
