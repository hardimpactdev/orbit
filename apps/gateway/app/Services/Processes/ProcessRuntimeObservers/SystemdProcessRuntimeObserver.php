<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Data\Doctor\ProbeSnapshot;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessExpectedRuntimeUnits;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Tools\ToolScriptDispatcher;

final readonly class SystemdProcessRuntimeObserver implements ProcessRuntimeObserver
{
    public function __construct(
        private RuntimeBackendProbe $runtimeBackendProbe,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ToolScriptDispatcher $scripts,
    ) {}

    public function observe(Process $process, Node $node): ProbeSnapshot
    {
        $probe = $this->runtimeBackendProbe->check($node);
        $specifications = $this->expectedRuntimeUnits->unlabeledSpecifications($process);

        $items = [
            $process->name => [
                'runtime_backend_available' => $probe->available,
                'runtime_backend_exit_code' => $probe->exitCode,
                'runtime_backend_output' => $probe->output,
                'runtime_units' => [],
                'runtime_unit_extras' => [],
            ],
        ];

        if (! $probe->available) {
            return new ProbeSnapshot($items);
        }

        $result = $this->scripts->run(
            $node,
            'orbit-process',
            'probe',
            $this->probeScript($specifications),
            throw: true,
        );

        foreach (explode("\n", rtrim($result->stdout, characters: "\n\r")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line, limit: 6);
            $name = $parts[0] ?? '';

            if ($name === '__extra') {
                if (count($parts) !== 2) {
                    continue;
                }

                $items[$process->name]['runtime_unit_extras'][] = $parts[1];

                continue;
            }

            if (count($parts) !== 5) {
                continue;
            }

            [$name, $exists, $matches, $restartMatches, $environmentMatches] = $parts;

            $items[$process->name]['runtime_units'][$name] = [
                'config_exists' => $exists === '1',
                'config_matches' => $matches === '1',
                'restart_policy_matches' => $restartMatches === '1',
                'environment_matches' => $environmentMatches === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @param  list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>}>  $units
     */
    private function probeScript(array $units): string
    {
        $expectedNames = array_map(
            static fn (array $unit): string => $unit['name'],
            $units,
        );
        $unitCalls = array_map(
            static fn (array $unit): string => 'probe_unit '
            .implode(' ', array_map(
                escapeshellarg(...),
                [
                    $unit['name'],
                    $unit['config_path'],
                    $unit['config_hash'],
                    $unit['restart_policy'],
                    ...$unit['environment_lines'],
                ],
            )),
            $units,
        );

        return implode(PHP_EOL, [
            'set -eu',
            '',
            'EXPECTED_NAMES=$(cat <<\'ORBIT_EXPECTED_UNITS\'',
            implode(PHP_EOL, $expectedNames),
            'ORBIT_EXPECTED_UNITS',
            ')',
            '',
            <<<'SH'
                hash_file() {
                    path=$1

                    if command -v sha256sum >/dev/null 2>&1; then
                        sha256sum "$path" 2>/dev/null | awk '{print $1}'
                        return
                    fi

                    shasum -a 256 "$path" 2>/dev/null | awk '{print $1}'
                }

                line_exists() {
                    path=$1
                    expected=$2

                    grep -Fqx -- "$expected" "$path" 2>/dev/null
                }

                probe_unit() {
                    unit_name=$1
                    unit_path=$2
                    expected_hash=$3
                    restart_policy=$4
                    shift 4

                    exists=0
                    matches=0
                    restart_matches=0
                    environment_matches=0

                    if [ -f "$unit_path" ]; then
                        exists=1
                        actual_hash=$(hash_file "$unit_path" || printf '')

                        if [ "$actual_hash" = "$expected_hash" ]; then
                            matches=1
                        fi

                        if line_exists "$unit_path" "Restart=$restart_policy"; then
                            restart_matches=1
                        fi

                        # Exact Environment= multiset match: every expected line must
                        # appear with the same multiplicity, and no extras are allowed.
                        # Order is normalized via sort so renderer reordering is not drift.
                        expected_environment=$(
                            for environment_line in "$@"; do
                                if [ "$environment_line" = "" ]; then
                                    continue
                                fi

                                printf '%s\n' "$environment_line"
                            done | LC_ALL=C sort
                        )
                        actual_environment=$(
                            grep -E '^Environment=' "$unit_path" 2>/dev/null | LC_ALL=C sort || true
                        )

                        if [ "$expected_environment" = "$actual_environment" ]; then
                            environment_matches=1
                        fi
                    fi

                    printf '%s\t%s\t%s\t%s\t%s\n' "$unit_name" "$exists" "$matches" "$restart_matches" "$environment_matches"
                }

                is_expected_name() {
                    expected_name=$1

                    printf '%s\n' "$EXPECTED_NAMES" | grep -Fqx -- "$expected_name"
                }

                probe_extras() {
                    for unit_path in /etc/systemd/system/orbit_*.service; do
                        [ -f "$unit_path" ] || continue

                        unit_file=${unit_path##*/}
                        unit_name=${unit_file%.service}

                        if ! is_expected_name "$unit_name"; then
                            printf '__extra\t%s\n' "$unit_name"
                        fi
                    done
                }
                SH,
            implode(PHP_EOL, $unitCalls),
            'probe_extras',
            '',
        ]);
    }
}
