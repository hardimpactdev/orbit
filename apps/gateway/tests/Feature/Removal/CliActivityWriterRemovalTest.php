<?php

declare(strict_types=1);

it('does not expose the retired CLI activity writer', function (): void {
    $gatewayRoot = dirname(__DIR__, 3);

    expect(file_exists($gatewayRoot.'/app/Concerns/LogsCommandActivity.php'))
        ->toBeFalse()
        ->and(file_exists($gatewayRoot.'/app/Services/ActivityLogTargets.php'))
        ->toBeFalse();
});
