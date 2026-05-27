<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\Support\E2EPreparedTopology;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusTopologyTemplate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

#[Signature('e2e:ensure-artifacts
    {kind=operator_gateway_app-dev_app-prod_agent : Topology kind to inspect or prepare}
    {--lanes= : Comma-separated lanes to inspect. Defaults to docker,incus}
    {--roles= : Comma-separated prepared artifact roles to prepare (operator,gateway,app-dev,app-prod,agent)}
    {--all-roles : Explicitly prepare every topology role}
    {--runtime : Include Docker runtime/support images}
    {--force : Run supported artifact preparation commands}
    {--rebuild : Rebuild selected Docker artifacts even when they already exist}
    {--json : Output as JSON}')]
#[Description('Inspect and explicitly prepare missing E2E artifacts')]
class E2EEnsureArtifactsCommand extends Command
{
    #[\Override]
    protected $hidden = true;

    public function handle(): int
    {
        $kindValue = (string) $this->argument('kind');
        $kind = E2ETopologyKind::tryFromInput($kindValue);

        if ($kind === null) {
            return $this->failValidation("Invalid topology kind [{$kindValue}]. Supported: ".E2EPreparedTopology::supportedKindsForHelp().'.');
        }

        if (! E2EPreparedTopology::supportsKind($kind)) {
            return $this->failValidation(E2EPreparedTopology::unsupportedKindMessage($kind));
        }

        try {
            $lanes = $this->selectedLanes();
            $roles = $this->selectedArtifactRoles();
        } catch (InvalidArgumentException $exception) {
            return $this->failValidation($exception->getMessage());
        }

        $allRoles = (bool) $this->option('all-roles');
        $runtime = (bool) $this->option('runtime');
        $rebuild = (bool) $this->option('rebuild');

        if (! $runtime && $roles === null && ! $allRoles) {
            return $this->failValidation('Set --roles or --all-roles for topology artifacts, or pass --runtime for Docker runtime/support images.');
        }

        $steps = $this->steps($kind, $lanes, $roles, $allRoles, $runtime, $rebuild);

        if ($steps === []) {
            return $this->failValidation('No artifact steps selected. Incus has no separate runtime-only artifact step; pass --roles or select --lanes=docker.');
        }

        $force = (bool) $this->option('force');

        if (! $force) {
            return $this->renderDryRun($kind, $steps);
        }

        $blocked = array_values(array_filter(
            $steps,
            fn (array $step): bool => $step['lane'] === 'incus',
        ));

        if ($blocked !== []) {
            return $this->failCommand('Targeted Incus role artifact preparation is guarded. Run this command without --force to inspect the exact Incus templates, or use composer e2e:prepare-topology for an explicit topology rebuild.');
        }

        $results = [];

        foreach ($steps as $step) {
            $result = Process::timeout(3600)->run($step['command']);
            $entry = [
                'lane' => $step['lane'],
                'name' => $step['name'],
                'command' => $step['command'],
                'successful' => $result->successful(),
                'output' => trim($result->output().$result->errorOutput()),
            ];
            $results[] = $entry;

            if (! $result->successful()) {
                return $this->renderForcedResult($kind, $results, failed: true);
            }

            if (! (bool) $this->option('json')) {
                $this->line("ensured: {$step['lane']} {$step['name']}");
            }
        }

        return $this->renderForcedResult($kind, $results, failed: false);
    }

    /**
     * @return list<string>
     */
    private function selectedLanes(): array
    {
        $value = $this->stringOption('lanes') ?? 'docker,incus';
        $lanes = array_values(array_unique(array_filter(
            array_map(
                static fn (string $lane): string => strtolower(trim($lane)),
                explode(',', $value),
            ),
            static fn (string $lane): bool => $lane !== '',
        )));

        if ($lanes === ['all']) {
            return ['docker', 'incus'];
        }

        if ($lanes === []) {
            throw new InvalidArgumentException('No E2E artifact lanes selected.');
        }

        $unsupported = array_values(array_diff($lanes, ['docker', 'incus']));

        if ($unsupported !== []) {
            throw new InvalidArgumentException('Unsupported E2E artifact lane(s): '.implode(', ', $unsupported).'. Supported lanes: docker, incus.');
        }

        return $lanes;
    }

    /**
     * @return list<string>|null
     */
    private function selectedArtifactRoles(): ?array
    {
        $roles = $this->stringOption('roles');

        if ($roles !== null && (bool) $this->option('all-roles')) {
            throw new InvalidArgumentException('Choose either --roles or --all-roles, not both.');
        }

        if ($roles === null) {
            return null;
        }

        return E2EPreparedTopology::parseArtifactRoles($roles);
    }

    /**
     * @param  list<string>  $lanes
     * @param  list<string>|null  $roles
     * @return list<array{lane: string, name: string, command: string, force_guarded?: bool, templates?: list<array{role: string, name: string, snapshot: string}>}>
     */
    private function steps(E2ETopologyKind $kind, array $lanes, ?array $roles, bool $allRoles, bool $runtime, bool $rebuild): array
    {
        $steps = [];
        $rebuildFlag = $rebuild ? ' --rebuild' : '';

        if (in_array('docker', $lanes, true) && $runtime) {
            $steps[] = [
                'lane' => 'docker',
                'name' => 'runtime',
                'command' => sprintf(
                    'composer e2e:prepare-docker-hosts -- --force%s --runtime-only %s',
                    $rebuildFlag,
                    escapeshellarg($kind->value),
                ),
            ];
        }

        if (in_array('docker', $lanes, true) && ($roles !== null || $allRoles)) {
            $steps[] = [
                'lane' => 'docker',
                'name' => 'topology',
                'command' => sprintf(
                    'composer e2e:prepare-docker-hosts -- --force%s --topology-only%s%s %s',
                    $rebuildFlag,
                    $roles !== null ? ' --roles='.implode(',', $roles) : '',
                    $allRoles ? ' --all-roles' : '',
                    escapeshellarg($kind->value),
                ),
            ];
        }

        if (in_array('incus', $lanes, true) && ($roles !== null || $allRoles)) {
            $templates = $this->incusTemplates($kind, $roles);

            $steps[] = [
                'lane' => 'incus',
                'name' => 'topology',
                'command' => sprintf(
                    'composer e2e:prepare-topology -- --force%s%s %s',
                    $roles !== null ? ' --roles='.implode(',', $roles) : '',
                    $allRoles ? ' --all-roles' : '',
                    escapeshellarg($kind->value),
                ),
                'force_guarded' => true,
                'templates' => $templates,
            ];
        }

        return $steps;
    }

    /**
     * @param  list<string>|null  $artifactRoles
     * @return list<array{role: string, name: string, snapshot: string}>
     */
    private function incusTemplates(E2ETopologyKind $kind, ?array $artifactRoles): array
    {
        $sourceKind = E2EPreparedTopology::incusSourceKindFor($kind);
        $roles = array_values(array_filter(
            IncusTopologyTemplate::rolesFor($sourceKind),
            function (string $role) use ($artifactRoles): bool {
                if ($artifactRoles === null) {
                    return true;
                }

                return in_array(E2EPreparedTopology::artifactRoleForIncusRole($role), $artifactRoles, true);
            },
        ));

        return array_map(
            fn (string $role): array => [
                'role' => E2EPreparedTopology::artifactRoleForIncusRole($role),
                'name' => IncusTopologyTemplate::templateName($sourceKind, $role),
                'snapshot' => IncusTopologyTemplate::snapshotName($sourceKind),
            ],
            $roles,
        );
    }

    /**
     * @param  list<array{lane: string, name: string, command: string, force_guarded?: bool, templates?: list<array{role: string, name: string, snapshot: string}>}>  $steps
     */
    private function renderDryRun(E2ETopologyKind $kind, array $steps): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'success' => [
                    'data' => [
                        'dry_run' => true,
                        'kind' => $kind->value,
                        'steps' => $steps,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line('Dry run. Pass --force to run supported artifact preparation.');

        foreach ($steps as $step) {
            $suffix = ($step['force_guarded'] ?? false) ? ' (force guarded)' : '';
            $this->line("planned: {$step['lane']} {$step['name']}{$suffix}");
            $this->line("command: {$step['command']}");

            foreach ($step['templates'] ?? [] as $template) {
                $this->line("template: {$template['name']} (snapshot: {$template['snapshot']})");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{lane: string, name: string, command: string, successful: bool, output: string}>  $results
     */
    private function renderForcedResult(E2ETopologyKind $kind, array $results, bool $failed): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                $failed ? 'error' : 'success' => [
                    'data' => [
                        'dry_run' => false,
                        'kind' => $kind->value,
                        'results' => $results,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));
        }

        if ($failed && ! (bool) $this->option('json')) {
            $last = array_last($results);
            $this->error("failed: {$last['lane']} {$last['name']} {$last['output']}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function failValidation(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'error' => [
                    'code' => 'artifact_ensure_failed',
                    'message' => $message,
                ],
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
