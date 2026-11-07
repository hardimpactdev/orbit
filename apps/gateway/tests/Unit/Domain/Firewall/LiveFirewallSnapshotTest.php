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
