<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeObservers;

use App\Data\Doctor\ProbeSnapshot;
use App\Models\Node;
use App\Models\Process;
use App\Services\Processes\ProcessExpectedRuntimeUnits;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use App\Services\Tools\ToolScriptDispatcher;

final readonly class LaunchdProcessRuntimeObserver implements ProcessRuntimeObserver
{
    public function __construct(
        private RuntimeBackendProbe $runtimeBackendProbe,
        private ProcessExpectedRuntimeUnits $expectedRuntimeUnits,
        private ToolScriptDispatcher $scripts,
    ) {}

    public function observe(Process $process, Node $node): ProbeSnapshot
    {
        $probe = $this->runtimeBackendProbe->check($node);
        $specifications = $this->expectedRuntimeUnits->launchdSpecifications($process);

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

            $parts = explode("\t", $line, limit: 4);

            if (count($parts) !== 4) {
                continue;
            }

            [$name, $exists, $matches, $loaded] = $parts;

            $items[$process->name]['runtime_units'][$name] = [
                'config_exists' => $exists === '1',
                'config_matches' => $matches === '1',
                'loaded' => $loaded === '1',
            ];
        }

        return new ProbeSnapshot($items);
    }

    /**
     * @param  list<array{name: string, config_path: string, config_hash: string, config_hash_label: string, restart_policy: string, environment_lines: list<string>, label: string}>  $units
     */
    private function probeScript(array $units): string
    {
        $lines = [
            'set -eu',
            'hash_file() {',
            "  if command -v shasum >/dev/null 2>&1; then shasum -a 256 \"$1\" | awk '{print $1}'; return; fi",
            "  if command -v sha256sum >/dev/null 2>&1; then sha256sum \"$1\" | awk '{print $1}'; return; fi",
            "  printf ''",
            '}',
            'probe_launchd_unit() {',
            '  name="$1"',
            '  path="$2"',
            '  expected_hash="$3"',
            '  label="$4"',
            '  exists=0',
            '  matches=0',
            '  loaded=0',
            '  if [ -f "$path" ]; then',
            '    exists=1',
            '    observed_hash="$(hash_file "$path")"',
            '    if [ "$observed_hash" = "$expected_hash" ]; then matches=1; fi',
            '  fi',
            '  if launchctl print "gui/$(id -u)/$label" >/dev/null 2>&1; then loaded=1; fi',
            '  printf \'%s\t%s\t%s\t%s\n\' "$name" "$exists" "$matches" "$loaded"',
            '}',
        ];

        foreach ($units as $unit) {
            $lines[] = sprintf(
                'probe_launchd_unit %s %s %s %s',
                escapeshellarg($unit['name']),
                escapeshellarg($unit['config_path']),
                escapeshellarg($unit['config_hash']),
                escapeshellarg($unit['label']),
            );
        }

        return implode("\n", $lines);
    }
}
