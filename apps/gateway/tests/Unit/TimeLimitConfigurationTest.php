<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\Large;
use Symfony\Component\Yaml\Yaml;
use Tests\Feature\Extensions\Documents\Infrastructure\DocumentTransportDeadlineTest;
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

it('passes the five-second default through Pest in Gateway CI test jobs', function (string $job): void {
    $workflow = Yaml::parseFile(dirname(__DIR__, 4).'/.github/workflows/ci.yml');
    $steps = array_values(array_filter(
        $workflow['jobs'][$job]['steps'],
        static fn (array $step): bool => str_contains($step['run'] ?? '', '../../bin/pest-plain vendor/bin/pest '),
    ));
    expect($steps)->toHaveCount(1);
    $arguments = preg_split('/\s+/', $steps[0]['run']);
    expect($arguments)->toContain('--parallel')
        ->and(array_values(array_filter(
            $arguments,
            static fn (string $argument): bool => str_starts_with($argument, '--default-time-limit='),
        )))->toBe(['--default-time-limit=5']);
})->with(['Gateway shards' => 'gateway-shards', 'Gateway privileged' => 'privileged']);

it('restricts the native large classes to the three reviewed real-deadline cases', function (): void {
    foreach ([
        DocumentTransportDeadlineTest::class => [
            'test_enforces_the_connection_bound_during_a_stalled_tls_handshake',
            'test_enforces_the_total_request_deadline_when_a_response_body_stalls',
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
