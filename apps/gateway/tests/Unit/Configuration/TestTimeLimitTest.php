<?php

declare(strict_types=1);

it('enforces the measured unsized time limit and stops workers on risky cases', function (): void {
    $configuration = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 3).'/phpunit.xml'));

    expect((string) $configuration['enforceTimeLimit'])->toBe('true')
        ->and((string) $configuration['defaultTimeLimit'])->toBe('42')
        ->and((string) $configuration['failOnRisky'])->toBe('true')
        ->and((string) $configuration['stopOnRisky'])->toBe('true');
});
