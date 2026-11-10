<?php

declare(strict_types=1);

use App\Domain\AppDev\PrivateDnsState;

describe('PrivateDnsState', function (): void {
    it('lists a name outside every TLD with its NODATA pair and drops a name its TLD already answers', function (): void {
        $state = PrivateDnsState::from(
            listen: ['10.44.0.1'],
            tlds: ['test' => '10.44.0.7', 'prod' => '10.44.0.4'],
            hosts: [
                'gateway.orbit' => '10.44.0.2',
                'hauzer.app' => '10.44.0.4',
                'app.dev.test' => '10.44.0.7',
                'operator.test' => '10.44.0.9',
            ],
        );

        expect($state->toIntent())->toBe([
            'listen' => ['10.44.0.1'],
            'tlds' => [['name' => 'prod', 'address' => '10.44.0.4'], ['name' => 'test', 'address' => '10.44.0.7']],
            'records' => [
                ['name' => 'gateway.orbit', 'address' => '10.44.0.2', 'pair' => true],
                ['name' => 'hauzer.app', 'address' => '10.44.0.4', 'pair' => true],
                ['name' => 'operator.test', 'address' => '10.44.0.9', 'pair' => false],
            ],
        ]);
    });

    it('lets the most specific TLD decide a record', function (): void {
        $state = PrivateDnsState::from(
            listen: ['10.44.0.1'],
            tlds: ['test' => '10.44.0.7', 'x.test' => '10.44.0.9'],
            hosts: ['app.x.test' => '10.44.0.9', 'app.test' => '10.44.0.9'],
        );

        expect($state->tldOf('app.x.test'))->toBe('x.test')
            ->and($state->tldOf('app.test'))->toBe('test')
            ->and($state->toIntent()['records'])->toBe([['name' => 'app.test', 'address' => '10.44.0.9', 'pair' => false]]);
    });

    it('sorts tlds and records so one state is one text', function (): void {
        $a = PrivateDnsState::from(['10.44.0.1'], ['b' => '10.0.0.2', 'a' => '10.0.0.1'], ['z.org' => '10.0.0.3', 'y.org' => '10.0.0.4']);
        $b = PrivateDnsState::from(['10.44.0.1'], ['a' => '10.0.0.1', 'b' => '10.0.0.2'], ['y.org' => '10.0.0.4', 'z.org' => '10.0.0.3']);

        expect($a->toIntent())->toBe($b->toIntent());
    });

    it('refuses a name or an address that dnsmasq would take as something else', function (string $name, string $address): void {
        PrivateDnsState::from(['10.44.0.1'], [], [$name => $address]);
    })->throws(InvalidArgumentException::class)->with([
        'upper case' => ['App.Test', '10.0.0.1'],
        'wildcard' => ['*.test', '10.0.0.1'],
        'slash' => ['a/b.test', '10.0.0.1'],
        'comma' => ['a,b.test', '10.0.0.1'],
        'space' => ['a b.test', '10.0.0.1'],
        'newline' => ["a.test\nserver=/x/", '10.0.0.1'],
        'empty label' => ['a..test', '10.0.0.1'],
        'ipv6 address' => ['a.test', '2001:db8::1'],
        'text address' => ['a.test', 'not-an-address'],
    ]);

    it('refuses a bad TLD and a bad listen address', function (): void {
        expect(fn () => PrivateDnsState::from(['10.44.0.1'], ['Test' => '10.0.0.1'], []))->toThrow(InvalidArgumentException::class)
            ->and(fn () => PrivateDnsState::from(['::1'], [], []))->toThrow(InvalidArgumentException::class);
    });
});
