<?php

declare(strict_types=1);

use App\Domain\Firewall\LiveFirewallSnapshot;

function snapshot_rule(string $source = '10.99.1.7', string $comment = 'orbit office'): array
{
    return [
        'comment' => $comment, 'action' => 'allow', 'direction' => 'in', 'source' => $source,
        'destination' => 'any', 'port' => '5432', 'protocol' => 'tcp',
        'in_interface' => null, 'out_interface' => null, 'family' => 'v4',
    ];
}

describe('LiveFirewallSnapshot', function (): void {
    it('marks a live rule with the recorded label but another source as drift, not missing', function (): void {
        $intended = [['name' => 'office database', 'shape' => snapshot_rule()]];

        $snapshot = LiveFirewallSnapshot::compare([
            'installed' => true, 'enabled' => true, 'rules' => [snapshot_rule('10.98.0.9')],
        ], $intended);

        expect($snapshot['live'])->toHaveCount(1);
        expect($snapshot['live'][0])->toMatchArray(['name' => 'office database', 'source' => '10.98.0.9', 'match' => 'drift']);
        expect($snapshot['missing'])->toBe([]);
    });

    it('matches an exact tuple before falling back to the label', function (): void {
        $intended = [
            ['name' => 'office database', 'shape' => snapshot_rule()],
            ['name' => 'other database', 'shape' => snapshot_rule('10.98.0.9', 'orbit other')],
        ];

        $snapshot = LiveFirewallSnapshot::compare([
            'installed' => true, 'enabled' => true, 'rules' => [snapshot_rule('10.98.0.9')],
        ], $intended);

        expect(array_column($snapshot['live'], 'match'))->toBe(['exact']);
        expect(array_column($snapshot['missing'], 'name'))->toBe(['office database']);
    });

    it('keeps an intended tuple exact without hiding another same-label live line', function (): void {
        $expected = [...snapshot_rule('10.232.2.11', 'orbit office web'), 'port' => '47870'];
        $other = [...$expected, 'port' => '47869'];
        $fact = ['installed' => true, 'enabled' => true, 'rules' => [$other, $expected]];
        $intended = [['name' => 'office web', 'shape' => $expected]];
        $snapshot = LiveFirewallSnapshot::compare($fact, $intended);

        expect(array_column($snapshot['live'], 'match'))->toBe(['drift', 'exact'])
            ->and($snapshot['missing'])->toBe([])
            ->and(LiveFirewallSnapshot::comparison($fact, $intended))->toBe('exact');
    });

    it('does not let an exact TCP line hide same-label UDP source drift', function (): void {
        $tcp = snapshot_rule();
        $udp = [...$tcp, 'protocol' => 'udp'];
        $fact = ['installed' => true, 'enabled' => true, 'rules' => [$tcp, $tcp, [...$udp, 'source' => '10.98.0.9']]];
        $intended = [['name' => 'office', 'shape' => $tcp], ['name' => 'office', 'shape' => $udp]];
        $snapshot = LiveFirewallSnapshot::compare($fact, $intended);

        expect(array_column($snapshot['live'], 'match'))->toBe(['exact', 'exact', 'drift'])
            ->and($snapshot['missing'])->toBe([])
            ->and(LiveFirewallSnapshot::comparison($fact, $intended))->toBe('drift');
    });

    it('requires every port of an intended range before exact wins over shared-label drift', function (): void {
        $start = snapshot_rule();
        $end = [...$start, 'port' => '5433'];
        $other = [...$start, 'source' => '10.98.0.9'];
        $intended = [['name' => 'office range', 'shape' => [...$start, 'port' => '5432:5433']]];
        $partial = ['installed' => true, 'enabled' => true, 'rules' => [$start, $other]];
        $complete = [...$partial, 'rules' => [$start, $end, $other]];

        expect(LiveFirewallSnapshot::comparison($partial, $intended))->toBe('drift')
            ->and(LiveFirewallSnapshot::comparison($complete, $intended))->toBe('exact')
            ->and(array_column(LiveFirewallSnapshot::compare($complete, $intended)['live'], 'match'))
            ->toBe(['exact', 'exact', 'drift']);
    });

    it('keeps an exact recorded rule exact and not missing', function (): void {
        $intended = [['name' => 'office database', 'shape' => snapshot_rule()]];

        $snapshot = LiveFirewallSnapshot::compare([
            'installed' => true, 'enabled' => true, 'rules' => [snapshot_rule()],
        ], $intended);

        expect($snapshot['live'][0])->toMatchArray(['name' => 'office database', 'match' => 'exact']);
        expect($snapshot['missing'])->toBe([]);
    });

    it('leaves an unmatched live rule unmanaged and the recorded rule missing', function (): void {
        $intended = [['name' => 'office database', 'shape' => snapshot_rule()]];

        $snapshot = LiveFirewallSnapshot::compare([
            'installed' => true, 'enabled' => true, 'rules' => [snapshot_rule('10.98.0.9', 'orbit other')],
        ], $intended);

        expect($snapshot['live'][0])->toMatchArray(['name' => 'orbit other', 'match' => 'unmanaged']);
        expect($snapshot['missing'])->toHaveCount(1);
        expect($snapshot['missing'][0])->toMatchArray(['name' => 'office database', 'match' => 'missing']);
    });

    it('keeps a labelled port range missing when only one exact port contribution is live', function (): void {
        $intended = [['name' => 'office database range', 'shape' => [...snapshot_rule(), 'port' => '5432:5433']]];

        $snapshot = LiveFirewallSnapshot::compare([
            'installed' => true, 'enabled' => true, 'rules' => [snapshot_rule()],
        ], $intended);

        expect($snapshot['live'][0]['match'])->toBe('exact');
        expect($snapshot['missing'][0])->toMatchArray(['name' => 'office database range', 'port' => '5432:5433', 'match' => 'missing']);
    });

    it('does not treat the bare orbit comment as a shared label for unrelated rules', function (): void {
        $intended = [['name' => 'unlabelled database', 'shape' => snapshot_rule(comment: 'orbit')]];

        $snapshot = LiveFirewallSnapshot::compare([
            'installed' => true, 'enabled' => true, 'rules' => [snapshot_rule('10.98.0.9', 'orbit')],
        ], $intended);

        expect($snapshot['live'][0]['match'])->toBe('unmanaged');
        expect($snapshot['missing'][0]['match'])->toBe('missing');
    });
});
