<?php

declare(strict_types=1);

it('leaves timing to the CI job while failing risky tests and PHPUnit warnings', function (): void {
    $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');
    $expected = [
        'enforceTimeLimit' => 'false',
        'failOnRisky' => 'true',
        'failOnPhpunitWarning' => 'true',
    ];

    foreach ($expected as $attribute => $value) {
        expect((string) $configuration[$attribute])->toBe($value, $attribute);
    }
});
