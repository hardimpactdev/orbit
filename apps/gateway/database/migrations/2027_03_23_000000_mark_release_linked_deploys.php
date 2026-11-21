<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A deployment that a release's nested-fleet refresh admitted names it in its input's `origin` (docs/reference/deployments.md#development-defaults).
 * One admitted before the input named it is known only by the Operation ID that a release's `default_refresh` stored for the default:
 * this writes that `origin` onto each such Operation, so the owner holds for as long as the Operation does, whatever releases follow.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('gateway_releases')->orderBy('id')->select(['id', 'phases'])->each(function (object $release): void {
            $phases = is_string($release->phases) ? json_decode($release->phases, true) : null;
            $refresh = is_array($phases) ? ($phases['default_refresh'] ?? null) : null;
            $defaults = is_array($refresh) ? ($refresh['defaults'] ?? null) : null;
            foreach (is_array($defaults) ? $defaults : [] as $instanceId => $row) {
                $operationId = is_array($row) ? ($row['operation_id'] ?? null) : null;
                if (! is_int($operationId)) {
                    continue;
                }
                $operation = DB::table('operations')->where('id', $operationId)->where('kind', 'instance.deploy')
                    ->where('target_type', 'instance')->where('target_id', $instanceId)->first(['id', 'input']);
                $input = $operation === null ? null : json_decode((string) $operation->input, true);
                if (is_array($input) && ! isset($input['origin'])) {
                    DB::table('operations')->where('id', $operationId)->update(['input' => json_encode([...$input, 'origin' => 'release'])]);
                }
            }
        });
    }

    public function down(): void {}
};
