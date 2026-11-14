<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceHostIntent;
use App\Domain\Instances\IsolatedIdRanges;
use App\Infrastructure\Instances\NodeIdMapRead;

/** Beast's isolated maps as Incus held them on 2026-11-14 at 12:57 UTC: 77 Instance containers of 65,536 and 8 nested-fleet ones. */
function beastRanges(): IsolatedIdRanges
{
    $small = [1065536, 1131072, 1196608, 1262144, 1327680, 1393216, 1458752, 1524288, 1589824, 1655360, 1720896, 1786432, 1851968, 1917504,
        1983040, 2048576, 2114112, 2179648, 2245184, 2310720, 2376256, 2441792, 2507328, 2572864, 2638400, 2703936, 2769472, 2835008, 2900544,
        2966080, 3031616, 3097152, 3162688, 3228224, 3293760, 3359296, 3424832, 3490368, 3555904, 3621440, 3686976, 3752512, 3818048, 3883584,
        3949120, 4014656, 4080192, 4145728, 4211264, 4276800, 4342336, 4407872, 4473408, 4538944, 4604480, 4670016, 4735552, 4801088, 4866624,
        4932160, 4997696, 5063232, 5128768, 5194304, 5259840, 5325376, 5390912, 5456448, 5718592, 5784128, 5849664, 5915200, 6242880, 6373952,
        6636096, 6767168, 107898240];
    $nested = [6832704, 107963776, 208963776, 309963776, 511963776, 612963776, 713963776, 814963776];
    $configs = [
        ...array_map(static fn (int $base): array => ['security.idmap.isolated' => 'true', 'volatile.idmap.base' => (string) $base], $small),
        ...array_map(static fn (int $base): array => ['security.idmap.isolated' => 'true', 'security.idmap.size' => '101000000',
            'volatile.idmap.base' => (string) $base], $nested),
        // A shared-map container, a privileged one and one never started hold no range.
        ['volatile.idmap.base' => '0'],
        ['security.privileged' => 'true', 'security.idmap.isolated' => 'true', 'volatile.idmap.base' => '915963776'],
        ['security.idmap.isolated' => 'true'],
    ];

    return IsolatedIdRanges::fromHost([1000000, 1000000000], [1000000, 1000000000], $configs);
}

it('finds Beast\'s one free nested-fleet range, the hole a destroyed copy left, as Incus would place it', function (): void {
    $ranges = beastRanges();

    expect($ranges->place(InstanceHostIntent::TaskPlaceIds))->toBe(410963776)
        ->and($ranges->free(InstanceHostIntent::TaskPlaceIds))->toBe(1)
        // A new plain container still fits in a gap between the small maps.
        ->and($ranges->place(InstanceHostIntent::IdMapSize))->toBe(5521984);
});

it('counts the 2026-11-14 incident: nine nested maps leave no range for a snapshot', function (): void {
    $ranges = beastRanges()->with(InstanceHostIntent::TaskPlaceIds);

    expect($ranges)->not->toBeNull()
        ->and($ranges?->place(InstanceHostIntent::TaskPlaceIds))->toBeNull()
        ->and($ranges?->free(InstanceHostIntent::TaskPlaceIds))->toBe(0);
});

it('places from 65,536 past root\'s base, in the first gap that fits, and never past root\'s range', function (): void {
    $empty = new IsolatedIdRanges([1000000, 1000000], []);
    expect($empty->place(100))->toBe(1065536)
        ->and($empty->place(1000000 - 65536))->toBe(1065536)
        ->and($empty->place(1000000 - 65535))->toBeNull();

    $gap = new IsolatedIdRanges([1000000, 1000000], [[1065536, 100], [1065836, 100]]);
    expect($gap->place(200))->toBe(1065636)->and($gap->place(201))->toBe(1065936);
});

it('takes root\'s range as the part that subuid and subgid share, and a fixed base as held', function (): void {
    $ranges = IsolatedIdRanges::fromHost([1000000, 1000000], [1100000, 1000000],
        [['security.idmap.base' => '1165536', 'security.idmap.size' => '1000']]);

    expect($ranges->root)->toBe([1100000, 900000])
        ->and($ranges->held)->toBe([[1165536, 1000]])
        ->and($ranges->place(10))->toBe(1166536);
});

it('decodes the reader\'s answer, and refuses an answer it cannot trust', function (string $stdout, bool $readable): void {
    expect(NodeIdMapRead::decode($stdout) !== null)->toBe($readable);
})->with([
    'a host' => [json_encode(['uid' => [1000000, 1000000000], 'gid' => [1000000, 1000000000], 'containers' => [
        ['name' => 'instance-1', 'project' => 'orbit-instances', 'config' => ['security.idmap.isolated' => 'true', 'volatile.idmap.base' => '1065536']],
    ]], JSON_THROW_ON_ERROR), true],
    'Incus did not answer' => ['{"error": "incus"}', false],
    'no subordinate range for root' => ['{"uid": null, "gid": [1000000, 1000], "containers": []}', false],
    'a container without a name' => ['{"uid": [1, 2], "gid": [1, 2], "containers": [{"project": "p", "config": {}}]}', false],
    'not JSON' => ['Traceback', false],
]);

it('reads the containers of every project, stopped ones included, with the reader on the host', function (): void {
    $source = (string) file_get_contents(resource_path('instances/id-map-read.py'));

    expect($source)->toContain('/1.0/instances?all-projects=true&recursion=1')
        ->and($source)->toContain('/etc/subuid')->and($source)->toContain('/etc/subgid')
        ->and($source)->not->toContain('status');
});

it('keeps for Doctor the free full-size ranges and the normal slots left before one would split a full-size gap', function (): void {
    // One 65,536 slot, a held small map, then exactly one full-size gap.
    $ranges = new IsolatedIdRanges([1000000, 65536 * 3 + InstanceHostIntent::TaskPlaceIds], [[1131072, 65536]]);
    expect($ranges->summary())->toBe(['full' => 1, 'small' => 1])
        // Beast on 2026-11-14: one full-size range and more than ten slots below it.
        ->and(beastRanges()->summary())->toBe(['full' => 1, 'small' => 10])
        ->and(beastRanges()->with(InstanceHostIntent::TaskPlaceIds)?->summary())->toBe(['full' => 0, 'small' => 10]);
});
