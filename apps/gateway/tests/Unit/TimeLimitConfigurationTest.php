<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Large;
use Tests\Feature\Extensions\Documents\Infrastructure\DocumentTransportDeadlineTest;
use Tests\Feature\Infrastructure\Tasks\TaskCheckDeadlineTest;
use Tests\Feature\Support\LinuxHostCtimeTest;

it('enforces the two-second default and keeps stock large-test budgets for reviewed real deadlines', function (): void {
    $configuration = simplexml_load_file(dirname(__DIR__, 2).'/phpunit.xml');

    foreach ([
        'enforceTimeLimit' => 'true',
        'defaultTimeLimit' => '2',
        'failOnRisky' => 'true',
        'stopOnRisky' => 'true',
    ] as $attribute => $value) {
        expect((string) $configuration[$attribute])->toBe($value, $attribute);
    }

    expect(isset($configuration['timeoutForLargeTests']))->toBeFalse();
});

it('restricts the native large classes to the reviewed real-deadline cases', function (): void {
    foreach ([
        DocumentTransportDeadlineTest::class => [
            'test_enforces_the_connection_bound_during_a_stalled_tls_handshake',
            'test_enforces_the_total_request_deadline_when_a_response_body_stalls',
        ],
        TaskCheckDeadlineTest::class => [
            'test_redacts_split_credentials_on_timeout',
            'test_removes_setup_scripts',
            'test_kills_a_timed_out_base_process_group',
            'test_streams_setup_and_stops_descendants',
            'test_bounds_output_draining',
        ],
        LinuxHostCtimeTest::class => ['test_removes_legacy_copies_whose_change_time_is_stale'],
    ] as $class => $expectedMethods) {
        $reflection = new ReflectionClass($class);
        expect($reflection->getAttributes(Large::class))->toHaveCount(1);
        $methods = array_values(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            array_filter($reflection->getMethods(ReflectionMethod::IS_PUBLIC), static fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'test')),
        ));
        expect($methods)->toBe($expectedMethods);
    }
});
