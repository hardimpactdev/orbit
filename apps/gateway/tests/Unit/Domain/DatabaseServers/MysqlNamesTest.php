<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\MysqlNames;

describe('MysqlNames', function (): void {
    it('replaces hyphens with underscores', function (): void {
        $names = new MysqlNames;

        expect($names->database('dlf-leden'))->toBe('dlf_leden')
            ->and($names->testDatabase('dlf_leden'))->toBe('dlf_leden_test')
            ->and($names->user('dlf-leden'))->toBe('dlf_leden')
            ->and($names->instanceUser('dlf', 'Feature.X'))->toBe('dlf_feature_x');
    });

    it('ends a name over the MySQL limit in an underscore and an 8-character hash', function (): void {
        $names = new MysqlNames;
        $long = str_repeat('ab', 20);
        $user = $names->user($long);
        $test = $names->testDatabase(str_repeat('d', 64));

        expect($user)->toBe(substr($long, 0, 23).'_'.substr(sha1($long), 0, 8))
            ->and(strlen($user))->toBe(32)
            ->and($names->user($long))->toBe($user)
            ->and($test)->toBe(str_repeat('d', 53).'_'.substr(sha1(str_repeat('d', 64).'_test'), 0, 8))
            ->and(strlen($test))->toBe(62)
            ->and($names->database(str_repeat('e', 64)))->toBe(str_repeat('e', 64));
    });

    it('keeps the escaped grant of a database and its test prefix within 64 characters', function (): void {
        $names = new MysqlNames;
        $database = $names->database('proof-owned-db-0c1195c4-fd80-4e5f-9631-7ee1e57fe9f6');
        $test = $names->testDatabase($database);
        $grant = static fn (string $name): int => strlen(str_replace('_', '\\_', $name));

        expect($database)->toBe('proof_owned_db_0c1195c4_fd80_4e5f_9631_7ee1e57fe9f6')
            ->and($grant($test) + 1)->toBeLessThanOrEqual(64)
            ->and($test)->toEndWith('_'.substr(sha1($database.'_test'), 0, 8))
            ->and($grant($names->database(str_repeat('a_', 32))))->toBeLessThanOrEqual(64);
    });
});
