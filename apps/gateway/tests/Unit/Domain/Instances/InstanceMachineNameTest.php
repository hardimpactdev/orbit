<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceMachineName;

/** A hostname the kernel and systemd accept: at most 64 characters, dot-separated labels of letters, digits and inner hyphens. */
function validHostname(string $hostname): bool
{
    return strlen($hostname) <= 64
        && preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*\z/D', $hostname) === 1;
}

it('presents the Instance name and the Project slug exactly as stored', function (): void {
    expect(InstanceMachineName::presented('task-5003', 'dlf-leden'))->toBe('task-5003.dlf-leden')
        ->and(InstanceMachineName::presented('default', 'dlf-leden'))->toBe('default.dlf-leden')
        ->and(InstanceMachineName::presented('default', 'My_App'))->toBe('default.My_App');
});

it('uses the presented name as hostname when the slug is a label and the name fits in 63 characters', function (): void {
    expect(InstanceMachineName::hostname(2865, 'task-5085', 78, 'shop'))->toBe('task-5085.shop')
        ->and(InstanceMachineName::hostname(1846, 'external-connections', 12, 'dlf-leden'))->toBe('external-connections.dlf-leden')
        ->and(InstanceMachineName::hostname(7, 'default', 12, 'dlf-leden'))->toBe('default.dlf-leden')
        ->and(InstanceMachineName::hostname(7, str_repeat('a', 31), 12, str_repeat('b', 31)))->toBe(str_repeat('a', 31).'.'.str_repeat('b', 31));
});

it('maps a slug with an underscore or uppercase letters to a 64-character hostname with the Instance id', function (string $slug, string $hostname): void {
    expect(InstanceMachineName::hostname(123, 'default', 9, $slug))->toBe($hostname)
        ->and(validHostname($hostname))->toBeTrue()
        ->and(strlen($hostname))->toBe(64);
})->with([
    'underscore' => ['my_app', 'default-'.str_repeat('0', 46).'123.my-app'],
    'uppercase' => ['My-App', 'default-'.str_repeat('0', 46).'123.my-app'],
    'edge hyphens and underscores' => ['_my-app-', 'default-'.str_repeat('0', 46).'123.my-app'],
    'only underscores' => ['__', 'default-'.str_repeat('0', 43).'123.project-9'],
]);

it('shortens a name longer than 63 characters to exactly 64, with valid labels', function (string $instance, string $slug): void {
    $hostname = InstanceMachineName::hostname(98765, $instance, 4, $slug);

    expect(validHostname($hostname))->toBeTrue()->and(strlen($hostname))->toBe(64)
        ->and($hostname)->toMatch('/-0*98765\\.'.preg_quote(rtrim(substr($slug, 0, 31), '-'), '/').'\\z/')
        ->and(str_starts_with($hostname, substr($instance, 0, min(10, strlen($instance)))))->toBeTrue();
})->with([
    'both 63' => [str_repeat('i', 63), str_repeat('p', 63)],
    'just over' => [str_repeat('i', 32), str_repeat('p', 31)],
    'short instance, long slug' => ['a', str_repeat('p', 63)],
    'slug cut at a hyphen' => [str_repeat('i', 40), str_repeat('p', 30).'-tail-of-the-slug'],
    'instance cut at a hyphen' => [str_repeat('i', 9).'-'.str_repeat('j', 53), str_repeat('p', 20)],
]);

it('keeps the hostname unique on a Node: two Projects, colliding labels, long names and real names that look shortened', function (): void {
    $hostnames = [
        InstanceMachineName::hostname(1, 'default', 1, 'my_app'),
        InstanceMachineName::hostname(2, 'default', 2, 'my-app'),
        InstanceMachineName::hostname(3, 'default', 3, 'My_App2'),
        InstanceMachineName::hostname(4, 'default', 4, 'my-app2'),
        // A real Instance named like the long form of Instance 1 in the Project whose slug Instance 1's label repeats.
        InstanceMachineName::hostname(5, 'default-1', 2, 'my-app'),
        InstanceMachineName::hostname(6, str_repeat('x', 40), 5, str_repeat('y', 40)),
        InstanceMachineName::hostname(7, str_repeat('x', 40), 5, str_repeat('y', 40).'z'),
        InstanceMachineName::hostname(8, str_repeat('x', 40), 6, str_repeat('y', 40)),
        InstanceMachineName::hostname(9, 'task-5003', 7, 'dlf-leden'),
        InstanceMachineName::hostname(10, 'task-5003', 8, 'dlf-leden2'),
    ];

    expect(array_unique($hostnames))->toHaveCount(count($hostnames));
    foreach ($hostnames as $hostname) {
        expect(validHostname($hostname))->toBeTrue();
    }
});

it('writes the file texts that the shared fixture names, which the Swamp writer asserts too', function (): void {
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/machine-name-files.json'), true, flags: JSON_THROW_ON_ERROR);

    foreach ($fixture['cases'] as $case) {
        $pretty = InstanceMachineName::presented($case['instance'], $case['slug']);
        expect($pretty)->toBe($case['pretty'])
            ->and(InstanceMachineName::hostname($case['instance_id'], $case['instance'], $case['project_id'], $case['slug']))->toBe($case['hostname'])
            ->and(InstanceMachineName::machineInfo($pretty))->toBe($case['files']['/etc/machine-info'])
            ->and($case['hostname']."\n")->toBe($case['files']['/etc/hostname']);
    }
});
