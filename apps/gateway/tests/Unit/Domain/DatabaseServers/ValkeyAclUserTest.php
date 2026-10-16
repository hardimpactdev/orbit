<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\ValkeyAclUser;

describe('ValkeyAclUser', function (): void {
    it('drops the name and the password hashes from an ACL LIST line and keeps the hashes apart', function (): void {
        $hash = hash('sha256', 'queue-secret');
        $user = ValkeyAclUser::fromAclListLine(
            "user gateway-queue on #{$hash} ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +eval -@admin -@dangerous",
        );

        expect($user)->toEqual(new ValkeyAclUser(
            'gateway-queue',
            'on ~orbit-gateway:* ~orbit-gateway-horizon:* resetchannels -@all +@connection +eval -@admin -@dangerous',
            [$hash],
        ))
            ->and($user?->hasOnlyPassword('queue-secret'))->toBeTrue()
            ->and($user?->hasOnlyPassword('other'))->toBeFalse();
    });

    it('reads a user without a password and a user with two', function (): void {
        $first = hash('sha256', 'one');
        $second = hash('sha256', 'two');

        expect(ValkeyAclUser::fromAclListLine('user default on nopass sanitize-payload ~* &* +@all'))
            ->toEqual(new ValkeyAclUser('default', 'on nopass sanitize-payload ~* &* +@all', []))
            ->and(ValkeyAclUser::fromAclListLine("user cache on #{$first} #{$second} ~cache:*  +@read")?->hasOnlyPassword('one'))->toBeFalse()
            ->and(ValkeyAclUser::fromAclListLine("user cache on #{$first} #{$second} ~cache:*  +@read")?->rules)->toBe('on ~cache:* +@read');
    });

    it('refuses a line that is not a user', function (string $line): void {
        expect(ValkeyAclUser::fromAclListLine($line))->toBeNull();
    })->with(['', 'user', 'default on ~* +@all', 'ERR unknown command']);

    it('compares users by name, password hashes, and rule tokens', function (): void {
        $user = ValkeyAclUser::withPassword('queue', 'on sanitize-payload ~a:* ~b:* resetchannels -@all +@list +eval -@admin', 'secret');

        expect($user->sameAs(new ValkeyAclUser('queue', 'sanitize-payload on resetchannels ~b:* ~a:* -@all +@list +eval -@admin', [hash('sha256', 'secret')])))->toBeTrue()
            ->and($user->sameAs(ValkeyAclUser::withPassword('other', $user->rules, 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', $user->rules, 'other')))->toBeFalse()
            ->and($user->sameAs(new ValkeyAclUser('queue', $user->rules, [hash('sha256', 'secret'), hash('sha256', 'other')])))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'on sanitize-payload ~a:* ~b:* resetchannels -@all +eval +@list -@admin', 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'on sanitize-payload ~a:* ~b:* resetchannels -@all +@list +eval', 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'on sanitize-payload ~a:* ~b:* &c -@all +@list +eval -@admin', 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'off sanitize-payload ~a:* ~b:* resetchannels -@all +@list +eval -@admin', 'secret')))->toBeFalse();
    });
});
