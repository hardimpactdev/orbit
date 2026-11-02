<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rule is (port, protocol, source) on its Node; its old name becomes its label. An allowlist becomes its
 * equivalent rules: an allow per source and a deny from anywhere, the lines it already has on the Node.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('firewall_rules')->where('action', 'allowlist')->orderBy('id')->get() as $list) {
            foreach ((array) json_decode((string) $list->sources, true) as $source) {
                assert(is_string($source));
                DB::table('firewall_rules')->insert([
                    ...array_diff_key((array) $list, array_flip(['id'])),
                    'name' => $list->name.'-'.$source, 'action' => 'allow', 'source' => $source, 'sources' => '[]',
                ]);
            }
            DB::table('firewall_rules')->where('id', $list->id)->update(['action' => 'deny', 'source' => 'any', 'sources' => '[]']);
        }
        $duplicates = DB::table('firewall_rules')->select('node_id', 'port', 'protocol', 'source')
            ->groupBy('node_id', 'port', 'protocol', 'source')->havingRaw('count(*) > 1')->get();
        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Firewall rules share an identity; remove one of each before migrating: '
                .$duplicates->map(static fn (object $row): string => "node {$row->node_id} {$row->port}/{$row->protocol} from {$row->source}")->implode('; '));
        }
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->string('label')->nullable();
        });
        DB::table('firewall_rules')->update(['label' => DB::raw('name')]);
        Schema::table('firewall_rules', function (Blueprint $table): void {
            $table->dropUnique(['node_id', 'name']);
            $table->dropColumn(['name', 'sources']);
            $table->unique(['node_id', 'port', 'protocol', 'source']);
        });
    }
};
