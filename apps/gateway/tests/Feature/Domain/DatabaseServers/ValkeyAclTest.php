<?php

declare(strict_types=1);

use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyAclUser;
use App\Models\DatabaseConnection;
use App\Models\DatabaseServer;
use App\Models\DatabaseUser;
use App\Models\Node;

function valkey_acl_test_rules(): string
{
    return 'on sanitize-payload ~queue:* resetchannels -@all +@list -@admin -@dangerous -function -script';
}

beforeEach(function (): void {
    $node = Node::query()->create([
        'name' => 'acl-node', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.80', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.80',
    ]);
    $this->server = DatabaseServer::query()->create([
        'slug' => 'acl-valkey', 'driver' => 'redis', 'node_id' => $node->id,
        'tag' => '8.1', 'port' => 6379, 'root_password' => 'admin-secret', 'status' => 'active',
    ]);
    $connection = DatabaseConnection::query()->create([
        'slug' => 'queue', 'driver' => 'redis', 'database_server_id' => $this->server->id,
        'username' => 'queue', 'password' => 'queue-secret',
    ]);
    DatabaseUser::query()->create(['database_connection_id' => $connection->id, 'username' => 'queue', 'privileges' => valkey_acl_test_rules()]);
    DatabaseConnection::query()->create([
        'slug' => 'adopted', 'driver' => 'redis', 'database_server_id' => $this->server->id,
        'username' => null, 'password' => 'admin-secret',
    ]);
});

describe('ValkeyAcl', function (): void {
    it('records default with the admin password and one user per connection with its own username', function (): void {
        expect(ValkeyAcl::users($this->server))->toEqual([
            'default' => ValkeyAclUser::withPassword('default', 'on sanitize-payload ~* &* +@all', 'admin-secret'),
            'queue' => ValkeyAclUser::withPassword('queue', valkey_acl_test_rules(), 'queue-secret'),
        ]);
    });

    it('writes the recorded users into the ACL file with only password hashes', function (): void {
        $file = ValkeyAcl::file($this->server);

        expect($file)->toBe(
            'user default on #'.hash('sha256', 'admin-secret')." ~* &* +@all\n"
            .'user queue #'.hash('sha256', 'queue-secret').' '.valkey_acl_test_rules()."\n",
        )
            ->and($file)->not->toContain('admin-secret')
            ->and($file)->not->toContain('queue-secret');

        $parsed = array_map(ValkeyAclUser::fromAclListLine(...), explode("\n", trim($file)));
        expect($parsed[1]?->sameAs(ValkeyAcl::users($this->server)['queue']))->toBeTrue();
    });

    it('lists each recorded user that is missing or differs, and each other live user', function (): void {
        $live = [
            'default' => ValkeyAclUser::withPassword('default', 'on sanitize-payload ~* &* +@all', 'admin-secret'),
            'queue' => ValkeyAclUser::withPassword('queue', valkey_acl_test_rules(), 'queue-secret'),
        ];

        expect(ValkeyAcl::changes($this->server, $live))->toBe(['set' => [], 'delete' => []]);

        $live['queue'] = ValkeyAclUser::withPassword('queue', valkey_acl_test_rules(), 'other');
        $live['manual'] = ValkeyAclUser::withPassword('manual', 'on ~* +@all', 'manual');
        unset($live['default']);

        expect(ValkeyAcl::changes($this->server, $live))->toEqual([
            'set' => array_values(ValkeyAcl::users($this->server)),
            'delete' => ['manual'],
        ]);
    });

    it('leaves the live default of an open server alone and writes it without a password', function (): void {
        $this->server->update(['default_open' => true]);
        $live = [
            'default' => new ValkeyAclUser('default', 'on nopass sanitize-payload ~* &* +@all', []),
            'queue' => ValkeyAclUser::withPassword('queue', valkey_acl_test_rules(), 'queue-secret'),
        ];

        expect(array_keys(ValkeyAcl::users($this->server)))->toBe(['queue'])
            ->and(ValkeyAcl::changes($this->server, $live))->toBe(['set' => [], 'delete' => []])
            ->and(ValkeyAcl::file($this->server))->toStartWith("user default on nopass ~* &* +@all\nuser queue #");
    });

    it('refuses a connection whose ACL user was never recorded instead of treating its live user as extra', function (): void {
        DatabaseUser::query()->delete();

        expect(fn () => ValkeyAcl::users($this->server->refresh()))->toThrow(LogicException::class, 'Connection [queue] has no recorded ACL user.');
    });
});
