<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\ValkeyAclUser;

describe('ValkeyAclUser', function (): void {
    it('reads the same real ACL LIST fixtures as the ACL writer', function (): void {
        $fixture = json_decode(file_get_contents(dirname(__DIR__, 3).'/Fixtures/valkey/acl-list.json'), true, flags: JSON_THROW_ON_ERROR);
        $expected = [ValkeyAclUser::withPassword('default', ValkeyAclUser::ADMIN_RULES, $fixture['admin_password'])];
        foreach ($fixture['users'] as $user) {
            $rules = 'on '.implode(' ', array_map(static fn (string $pattern): string => '~'.$pattern, $user['patterns']))
                .' resetchannels -@all '.implode(' ', array_map(static fn (string $rule): string => '+'.$rule, $user['allow']))
                .' -@admin -@dangerous -function -script';
            $expected[] = ValkeyAclUser::withPassword($user['name'], $rules, $user['password']);
        }
        $actual = [];
        foreach ($fixture['closed'] as $line) {
            $user = ValkeyAclUser::fromAclListLine($line);
            $actual[$user->name] = $user;
        }
        foreach ($expected as $user) {
            expect($actual[$user->name]->sameAs($user))->toBeTrue();
        }
        expect(ValkeyAclUser::fromAclListLine($fixture['open_default'])->sameAs(new ValkeyAclUser('default', 'on nopass ~* &* +@all', [])))->toBeTrue();
    });

    it('compares only the payload-independent rules Orbit sets', function (string $flag, bool $same): void {
        $recorded = ValkeyAclUser::withPassword('default', ValkeyAclUser::ADMIN_RULES, 'secret');
        $reported = ValkeyAclUser::fromAclListLine('user default on '.$flag.' #'.hash('sha256', 'secret').' ~* &* +@all');

        expect($recorded->sameAs($reported))->toBe($same)
            ->and($reported->sameAs($recorded))->toBe($same);
    })->with(['supported payload metadata' => ['sanitize-payload', true], 'an unknown flag' => ['nosanitize-payload', false]]);

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

        expect(ValkeyAclUser::fromAclListLine('user default on nopass ~* &* +@all'))
            ->toEqual(new ValkeyAclUser('default', 'on nopass ~* &* +@all', []))
            ->and(ValkeyAclUser::fromAclListLine("user cache on #{$first} #{$second} ~cache:*  +@read")?->hasOnlyPassword('one'))->toBeFalse()
            ->and(ValkeyAclUser::fromAclListLine("user cache on #{$first} #{$second} ~cache:*  +@read")?->rules)->toBe('on ~cache:* +@read');
    });

    it('refuses a line that is not a user', function (string $line): void {
        expect(ValkeyAclUser::fromAclListLine($line))->toBeNull();
    })->with(['', 'user', 'default on ~* +@all', 'ERR unknown command']);

    it('compares users by name, password hashes, and rule tokens', function (): void {
        $user = ValkeyAclUser::withPassword('queue', 'on ~a:* ~b:* resetchannels -@all +@list +eval -@admin', 'secret');

        expect($user->sameAs(new ValkeyAclUser('queue', 'on resetchannels ~b:* ~a:* -@all +@list +eval -@admin', [hash('sha256', 'secret')])))->toBeTrue()
            ->and($user->sameAs(ValkeyAclUser::withPassword('other', $user->rules, 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', $user->rules, 'other')))->toBeFalse()
            ->and($user->sameAs(new ValkeyAclUser('queue', $user->rules, [hash('sha256', 'secret'), hash('sha256', 'other')])))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'on ~a:* ~b:* resetchannels -@all +eval +@list -@admin', 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'on ~a:* ~b:* resetchannels -@all +@list +eval', 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'on ~a:* ~b:* &c -@all +@list +eval -@admin', 'secret')))->toBeFalse()
            ->and($user->sameAs(ValkeyAclUser::withPassword('queue', 'off ~a:* ~b:* resetchannels -@all +@list +eval -@admin', 'secret')))->toBeFalse();
    });
});
