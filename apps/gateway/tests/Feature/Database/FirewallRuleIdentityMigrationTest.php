<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::table('firewall_rules', function (Blueprint $table): void {
        $table->dropUnique(['node_id', 'port', 'protocol', 'source']);
        $table->dropColumn('label');
        $table->string('name')->default('');
        $table->json('sources')->default('[]');
    });
    Schema::table('firewall_rules', fn (Blueprint $table) => $table->unique(['node_id', 'name']));
    $this->node = Node::query()->create(['name' => 'edge', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
    $this->rule = fn (string $name, string $protocol): array => ['node_id' => $this->node->id, 'name' => $name, 'action' => 'allow',
        'source' => 'any', 'sources' => '[]', 'protocol' => $protocol, 'port' => '443', 'status' => 'active'];
    $this->migration = require database_path('migrations/2027_01_09_120022_identify_firewall_rules_by_tuple.php');
});

it('copies each name into the label and makes the tuple unique per Node', function (): void {
    DB::table('firewall_rules')->insert([($this->rule)('web', 'tcp'), ($this->rule)('web-udp', 'udp')]);

    $this->migration->up();

    expect(Schema::hasColumn('firewall_rules', 'name'))->toBeFalse()
        ->and(DB::table('firewall_rules')->orderBy('protocol')->pluck('label')->all())->toBe(['web', 'web-udp']);
    expect(fn () => DB::table('firewall_rules')->insert([...array_diff_key(($this->rule)('', 'tcp'), ['name' => 0]), 'label' => 'again']))
        ->toThrow(QueryException::class);
});

it('refuses rules that share a tuple and names each duplicate without changing the schema', function (): void {
    DB::table('firewall_rules')->insert([($this->rule)('web', 'tcp'), ($this->rule)('web-again', 'tcp')]);

    expect(fn () => $this->migration->up())
        ->toThrow(RuntimeException::class, "node {$this->node->id} 443/tcp from any");
    expect(Schema::hasColumn('firewall_rules', 'name'))->toBeTrue()
        ->and(Schema::hasColumn('firewall_rules', 'label'))->toBeFalse();
});

it('turns an allowlist into an allow per source and a deny from anywhere', function (): void {
    DB::table('firewall_rules')->insert([...($this->rule)('rex', 'tcp'), 'action' => 'allowlist', 'port' => '7297',
        'sources' => json_encode(['10.44.0.6', '10.44.0.9'])]);

    $this->migration->up();

    expect(DB::table('firewall_rules')->orderBy('source')->get(['action', 'source', 'port', 'label'])->map(fn (object $row): array => (array) $row)->all())
        ->toBe([
            ['action' => 'allow', 'source' => '10.44.0.6', 'port' => '7297', 'label' => 'rex-10.44.0.6'],
            ['action' => 'allow', 'source' => '10.44.0.9', 'port' => '7297', 'label' => 'rex-10.44.0.9'],
            ['action' => 'deny', 'source' => 'any', 'port' => '7297', 'label' => 'rex'],
        ])
        ->and(Schema::hasColumn('firewall_rules', 'sources'))->toBeFalse();
});
