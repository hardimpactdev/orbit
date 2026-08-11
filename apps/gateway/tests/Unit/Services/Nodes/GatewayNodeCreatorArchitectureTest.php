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

it('delegates bootstrap reservation as one focused boundary', function (): void {
    $services = dirname(__DIR__, 4).'/app/Services/Nodes';
    $creator = (string) file_get_contents($services.'/GatewayNodeCreator.php');
    $reservation = (string) file_get_contents($services.'/NodeBootstrapReservation.php');

    expect($creator)
        ->toContain('private readonly NodeBootstrapReservation $bootstrapReservation')
        ->toContain('$this->bootstrapReservation->prepare(');

    expect($creator)->not->toContain('private function prepareHostBootstrap(');
    expect($creator)->not->toContain('private function prepareHostBootstrapWithReservationLock(');
    expect($creator)->not->toContain('WIREGUARD_RESERVATION_LOCK');

    expect($reservation)
        ->toContain('WIREGUARD_RESERVATION_LOCK')
        ->toContain('private function prepareWithReservationLock(');
});

it('delegates agent grants and tools to one setup boundary', function (): void {
    $services = dirname(__DIR__, 4).'/app/Services/Nodes';
    $creator = (string) file_get_contents($services.'/GatewayNodeCreator.php');
    $agentSetup = (string) file_get_contents($services.'/NodeAgentProvisioning.php');

    expect($creator)
        ->toContain('private readonly NodeAgentProvisioning $agentProvisioning')
        ->toContain('$this->agentProvisioning->preflight(');

    foreach ([
        'private function setupAgentSelfGrant(',
        'private function setupGrantTo(',
        'private function setupGrantFrom(',
        'private function resolveGrantTargets(',
        'private function resolveGrantPermissions(',
        'private function setupAgentTools(',
    ] as $oldMethod) {
        expect($creator)->not->toContain($oldMethod);
    }

    expect($agentSetup)
        ->toContain('public function preflight(')
        ->toContain('public function apply(');
});

it('delegates bootstrap completion and ordered convergence as one boundary', function (): void {
    $services = dirname(__DIR__, 4).'/app/Services/Nodes';
    $creator = (string) file_get_contents($services.'/GatewayNodeCreator.php');
    $completion = (string) file_get_contents($services.'/NodeBootstrapCompletion.php');

    expect($creator)
        ->toContain('private readonly NodeBootstrapCompletion $bootstrapCompletion')
        ->toContain('$this->bootstrapCompletion->complete(')
        ->toContain('$this->bootstrapCompletion->convergePrepared(');

    foreach ([
        'private function completeBootstrapWhileLocked(',
        'private function syncActiveS3ServiceRoute(',
        'private function refreshCompletedBootstrap(',
        'private function completePreparedWorkloadNode(',
        'private function completedNodePayload(',
        'private function completedBootstrapResult(',
        'private function ensureInitialWorkloadRoles(',
        'private function setupManagedNode(',
        'private function finalizeNodeSecurityBaseline(',
    ] as $oldMethod) {
        expect($creator)->not->toContain($oldMethod);
    }

    expect($completion)
        ->toContain('public function complete(')
        ->toContain('public function convergePrepared(')
        ->toContain('NodeBootstrapCompletionLock $completionLock');
});

it('delegates gateway convergence and client enrollment', function (): void {
    $services = dirname(__DIR__, 4).'/app/Services/Nodes';
    $creator = (string) file_get_contents($services.'/GatewayNodeCreator.php');
    $gateway = (string) file_get_contents($services.'/LocalGatewayNodeConverger.php');
    $client = (string) file_get_contents($services.'/ClientNodeEnroller.php');

    expect($creator)
        ->toContain('private readonly LocalGatewayNodeConverger $gatewayConverger')
        ->toContain('private readonly ClientNodeEnroller $clientEnroller')
        ->toContain('$this->gatewayConverger->converge(')
        ->toContain('$this->clientEnroller->enroll(');

    foreach ([
        'private function convergeGatewayLocally(',
        'private function gatewayConvergencePayload(',
        'private function enrollClientNode(',
        'private function controlWireGuardConfig(',
        'private function nextWireguardAddress(',
        'private function usedWireguardAddresses(',
    ] as $oldMethod) {
        expect($creator)->not->toContain($oldMethod);
    }

    expect($gateway)->toContain('public function converge(');
    expect($client)->toContain('public function enroll(');
});
