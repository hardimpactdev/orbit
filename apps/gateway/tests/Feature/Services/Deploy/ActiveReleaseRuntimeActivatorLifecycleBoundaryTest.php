<?php

declare(strict_types=1);

/**
 * ActiveReleaseRuntimeActivator restarts the FrankenPHP active-release Docker
 * container during deploy activation when the rendered container is unchanged.
 * That path is deploy runtime infrastructure, not a configured process
 * lifecycle command (process:restart / process:update --restart). Durable
 * process_events must not be fabricated here: the container name is not a
 * process definition unit, and toolbar process streams do not own deploy
 * activation state. Deploy failures surface as deploy.* GatewayApiException
 * codes (see deploy.runtime_restart_failed).
 */
it('excludes ActiveReleaseRuntimeActivator docker restart from process_events lifecycle instrumentation', function (): void {
    $source = file_get_contents(base_path('app/Services/Deploy/ActiveReleaseRuntimeActivator.php'));

    expect($source)
        ->toContain('ProcessDockerRuntimeManager')
        ->toContain('->restart(')
        ->and(str_contains($source, 'RecordProcessEvent'))
        ->toBeFalse('ActiveReleaseRuntimeActivator must not record process_events for deploy container restarts.')
        ->and(str_contains($source, 'ProcessEventType'))
        ->toBeFalse('ActiveReleaseRuntimeActivator must not emit process lifecycle event types.')
        ->and($source)
        ->toContain('deploy.runtime_restart_failed')
        ->toContain('not a configured process lifecycle path');
});
