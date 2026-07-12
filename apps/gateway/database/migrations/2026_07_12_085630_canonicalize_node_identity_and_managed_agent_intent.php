<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * This one-shot canonicalization validates fleet-wide TLD ownership before changing node intent.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * @mago-expect lint:too-many-methods
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('nodes', 'managed')) {
            Schema::table('nodes', static function (Blueprint $table): void {
                $table->boolean('managed')->default(false);
            });
        }

        DB::transaction(function (): void {
            foreach ($this->canonicalActiveNodeTlds() as $nodeId => $tld) {
                DB::table('nodes')->where('id', $nodeId)->update(['tld' => $tld]);
            }

            $this->stripRoleAssignmentTlds();
            $this->backfillManagedOperatorIntent();
        });

        if (Schema::hasColumn('nodes', 'orbit_agent_capable')) {
            Schema::table('nodes', static function (Blueprint $table): void {
                $table->dropColumn('orbit_agent_capable');
            });
        }

        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS nodes_active_tld_unique ON nodes(tld) WHERE status = 'active'",
        );
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS nodes_active_tld_required_insert
            BEFORE INSERT ON nodes
            WHEN NEW.status = 'active' AND (
                NEW.tld IS NULL
                OR length(trim(NEW.tld)) < 1
                OR length(trim(NEW.tld)) > 63
                OR trim(NEW.tld) <> lower(trim(NEW.tld))
                OR trim(NEW.tld) GLOB '*[^a-z0-9-]*'
                OR substr(trim(NEW.tld), 1, 1) = '-'
                OR substr(trim(NEW.tld), -1, 1) = '-'
            )
            BEGIN
                SELECT RAISE(ABORT, 'active nodes require a valid TLD');
            END
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER IF NOT EXISTS nodes_active_tld_required_update
            BEFORE UPDATE OF status, tld ON nodes
            WHEN NEW.status = 'active' AND (
                NEW.tld IS NULL
                OR length(trim(NEW.tld)) < 1
                OR length(trim(NEW.tld)) > 63
                OR trim(NEW.tld) <> lower(trim(NEW.tld))
                OR trim(NEW.tld) GLOB '*[^a-z0-9-]*'
                OR substr(trim(NEW.tld), 1, 1) = '-'
                OR substr(trim(NEW.tld), -1, 1) = '-'
            )
            BEGIN
                SELECT RAISE(ABORT, 'active nodes require a valid TLD');
            END
            SQL);
    }

    /** @return array<int, string> */
    private function canonicalActiveNodeTlds(): array
    {
        $canonical = [];
        $conflicts = [];
        $owners = [];

        foreach (DB::table('nodes')->where('status', 'active')->orderBy('id')->get() as $node) {
            $nodeId = $this->rowInteger($node, 'id');
            $nodeName = $this->rowString($node, 'name');
            $nodeTld = trim($this->rowNullableString($node, 'tld') ?? '');
            $assignmentTlds = $this->assignmentTlds($nodeId);

            if ($nodeTld !== '' && ! $this->validTld($nodeTld)) {
                $conflicts[] = [
                    'node' => $nodeName,
                    'reason' => 'invalid_node_tld',
                    'value' => $nodeTld,
                ];

                continue;
            }

            if ($nodeTld !== '') {
                $differentAssignments = array_values(array_filter(
                    $assignmentTlds,
                    static fn (string $assignmentTld): bool => $assignmentTld !== $nodeTld,
                ));

                if ($differentAssignments !== []) {
                    $conflicts[] = [
                        'node' => $nodeName,
                        'reason' => 'node_assignment_tld_conflict',
                        'node_tld' => $nodeTld,
                        'assignment_tlds' => $differentAssignments,
                    ];

                    continue;
                }

                $candidate = $nodeTld;
            } else {
                $candidate = $assignmentTlds[0] ?? strtolower(trim($nodeName));

                if (count(array_unique($assignmentTlds)) > 1) {
                    $conflicts[] = [
                        'node' => $nodeName,
                        'reason' => 'ambiguous_assignment_tld',
                        'assignment_tlds' => array_values(array_unique($assignmentTlds)),
                    ];

                    continue;
                }

                if (! $this->validTld($candidate)) {
                    $conflicts[] = [
                        'node' => $nodeName,
                        'reason' => 'missing_tld_without_valid_default',
                        'value' => $candidate,
                    ];

                    continue;
                }
            }

            if (array_key_exists($candidate, $owners)) {
                $conflicts[] = [
                    'node' => $nodeName,
                    'reason' => 'duplicate_active_tld',
                    'value' => $candidate,
                    'conflicts_with' => $owners[$candidate],
                ];

                continue;
            }

            $owners[$candidate] = $nodeName;
            $canonical[$nodeId] = $candidate;
        }

        if ($conflicts !== []) {
            throw new \RuntimeException('Node TLD migration conflicts: '.json_encode($conflicts, JSON_THROW_ON_ERROR));
        }

        return $canonical;
    }

    /** @return list<string> */
    private function assignmentTlds(int $nodeId): array
    {
        $values = [];

        foreach (['app-dev', 'agent'] as $role) {
            $assignment = DB::table('node_role')
                ->where('node_id', $nodeId)
                ->where('role', $role)
                ->orderBy('id')
                ->first();

            if (! is_object($assignment)) {
                continue;
            }

            $settings = $this->decodeSettings(
                $this->rowValue($assignment, 'settings'),
                $this->rowInteger($assignment, 'id'),
            );
            $tld = is_string($settings['tld'] ?? null) ? trim($settings['tld']) : '';

            if ($tld !== '' && $this->validTld($tld)) {
                $values[] = $tld;
            }
        }

        return $values;
    }

    private function stripRoleAssignmentTlds(): void
    {
        DB::table('node_role')
            ->select(['id', 'settings'])
            ->orderBy('id')
            ->each(function (object $assignment): void {
                $assignmentId = $this->rowInteger($assignment, 'id');
                $settings = $this->decodeSettings(
                    $this->rowValue($assignment, 'settings'),
                    $assignmentId,
                );

                if (! array_key_exists('tld', $settings)) {
                    return;
                }

                unset($settings['tld']);

                DB::table('node_role')
                    ->where('id', $assignmentId)
                    ->update([
                        'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
                    ]);
            });
    }

    /** @return array<string, mixed> */
    private function decodeSettings(mixed $value, int $assignmentId): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_string($value)) {
            throw new \RuntimeException("Role assignment {$assignmentId} settings must be JSON.");
        }

        /** @var mixed $settings */
        $settings = json_decode($value, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($settings)) {
            throw new \RuntimeException("Role assignment {$assignmentId} settings must decode to an object.");
        }

        foreach (array_keys($settings) as $key) {
            if (! is_string($key)) {
                throw new \RuntimeException(
                    "Role assignment {$assignmentId} settings must use string keys.",
                );
            }
        }

        /** @var array<string, mixed> $settings */
        return $settings;
    }

    private function backfillManagedOperatorIntent(): void
    {
        if (! Schema::hasColumn('nodes', 'orbit_agent_capable')) {
            return;
        }

        DB::table('nodes')
            ->where('orbit_agent_capable', true)
            ->orderBy('id')
            ->each(function (object $node): void {
                $nodeId = $this->rowInteger($node, 'id');
                $hasActiveRole = DB::table('node_role')
                    ->where('node_id', $nodeId)
                    ->where('status', 'active')
                    ->exists();

                if ($hasActiveRole || ! $this->supportedPlatform($this->rowValue($node, 'platform'))) {
                    return;
                }

                if (! $this->validWireguardAddress($this->rowValue($node, 'wireguard_address'))) {
                    return;
                }

                DB::table('nodes')->where('id', $nodeId)->update(['managed' => true]);
            });
    }

    private function validTld(string $tld): bool
    {
        return strlen($tld) <= 63 && preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $tld) === 1;
    }

    private function supportedPlatform(mixed $platform): bool
    {
        if (! is_string($platform)) {
            return false;
        }

        $platform = strtolower(trim($platform));

        return (
            str_starts_with($platform, 'ubuntu')
            || str_starts_with($platform, 'macos')
            || str_starts_with($platform, 'darwin')
        );
    }

    private function validWireguardAddress(mixed $address): bool
    {
        if (! is_string($address) || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return ! in_array(
            $address,
            ['0.0.0.0', '::', '127.0.0.1', '::1', '255.255.255.255'],
            strict: true,
        );
    }

    private function rowInteger(object $row, string $field): int
    {
        $values = get_object_vars($row);

        if (is_int($values[$field] ?? null)) {
            return $values[$field];
        }

        if (is_string($values[$field] ?? null) && ctype_digit($values[$field])) {
            return (int) $values[$field];
        }

        throw new \RuntimeException("Canonical node row has an invalid {$field} integer.");
    }

    private function rowString(object $row, string $field): string
    {
        $values = get_object_vars($row);

        if (! is_string($values[$field] ?? null)) {
            throw new \RuntimeException("Canonical node row has an invalid {$field} string.");
        }

        return $values[$field];
    }

    private function rowNullableString(object $row, string $field): ?string
    {
        $values = get_object_vars($row);

        if (($values[$field] ?? null) === null) {
            return null;
        }

        return $this->rowString($row, $field);
    }

    private function rowValue(object $row, string $field): mixed
    {
        return get_object_vars($row)[$field] ?? null;
    }
};
