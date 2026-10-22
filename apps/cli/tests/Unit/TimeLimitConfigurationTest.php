<?php

declare(strict_types=1);

it('enforces stock per-test limits and fails on risky tests or unavailable enforcement', function (): void {
    $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml.dist');
    $expected = [
        'enforceTimeLimit' => 'true',
        'failOnRisky' => 'true',
        'failOnPhpunitWarning' => 'true',
        'defaultTimeLimit' => '2',
        'timeoutForSmallTests' => '2',
        'timeoutForMediumTests' => '2',
        'timeoutForLargeTests' => '2',
    ];

    foreach ($expected as $attribute => $value) {
        expect((string) $configuration[$attribute])->toBe($value, $attribute);
    }
});
