<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\GitHub\NativeGitHubTokenIssuer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** Fixed family declarations and intent/result projection. Swamp owns execution. */
final readonly class DirectOperation
{
    /** @var array<string, array{workflow: string, alternate_workflows?:list<string>, alternate_results?:array<string,array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string}>, child_versions?:array<string,int>, workflow_inputs?: bool, execution_copies?: list<array{vault:string,key_pattern:string}>, terminal_result?: array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string}, scheduled_result?: array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string},rows:string}, projection: class-string<DirectOperationProjection>, child_projection?: class-string<DirectOperationChildProjection>, primitive?: class-string<DirectOperationPrimitive>, writer_scope?: array<string, bool|int|string|null>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>, github_token_issuer?: class-string<NativeGitHubTokenIssuer>}> */
    private array $definitions;

    /** @var array<string, array<int, array{workflow: string, alternate_workflows?:list<string>, alternate_results?:array<string,array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string}>, child_versions?:array<string,int>, workflow_inputs?: bool, execution_copies?: list<array{vault:string,key_pattern:string}>, terminal_result?: array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string}, scheduled_result?: array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string},rows:string}, projection: class-string<DirectOperationProjection>, child_projection?: class-string<DirectOperationChildProjection>, primitive?: class-string<DirectOperationPrimitive>, writer_scope?: array<string, bool|int|string|null>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>, github_token_issuer?: class-string<NativeGitHubTokenIssuer>}>> */
    private array $versions;

    /** @param iterable<DirectOperationContribution> $contributions */
    public function __construct(#[Tag(DirectOperationContribution::class)] iterable $contributions = [])
    {
        $definitions = $versions = [];
        $policies = OperationVersions::policies();
        $families = array_map(static fn (string $file): array => require $file, glob(__DIR__.'/../*/direct-operations.php') ?: []);
        foreach ($contributions as $contribution) {
            $families[] = $contribution->definitions();
        }
        foreach ($families as $family) {
            foreach ($family as $kind => $base) {
                $policy = $policies[$kind] ?? [];
                $active = $policy['current'] ?? 1;
                foreach ([$active => $base] + ($policy['retained'] ?? []) as $version => $definition) {
                    if (isset($versions[$kind][$version]) || preg_match(OperationKinds::NamePattern, $kind) !== 1
                        || array_diff(array_keys($definition), ['workflow', 'alternate_workflows', 'alternate_results', 'workflow_inputs', 'execution_copies', 'terminal_result', 'scheduled_result', 'writer_config', 'writer_state', 'projection', 'child_projection', 'primitive', 'writer_scope', 'private_material', 'secret_outputs', 'github_token_issuer', 'result_hook', 'child_versions']) !== []
                        || (array_key_exists('workflow_inputs', $definition) && ! is_bool($definition['workflow_inputs']))
                        || ! is_string($definition['workflow'] ?? null) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $definition['workflow']) !== 1
                        || (isset($definition['alternate_workflows']) && (! is_array($definition['alternate_workflows']) || ! array_is_list($definition['alternate_workflows'])
                            || $definition['alternate_workflows'] === [] || array_any($definition['alternate_workflows'], static fn (mixed $name): bool => ! is_string($name) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $name) !== 1 || $name === $definition['workflow'])
                            || ! is_string($definition['projection'] ?? null) || ! is_a($definition['projection'], DirectOperationWorkflowChoice::class, true)))
                        || ! isset($definition['projection'])
                        || (isset($definition['writer_scope']) && (! is_array($definition['writer_scope']) || array_any(array_keys($definition['writer_scope']), static fn (mixed $key): bool => ! is_string($key) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $key) !== 1) || array_any($definition['writer_scope'], static fn (mixed $value): bool => ! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_string($value))))
                        || ! self::validSecretMap($definition['private_material'] ?? []) || ! self::validSecretMap($definition['secret_outputs'] ?? [])
                        || (isset($definition['github_token_issuer']) && (! is_string($definition['github_token_issuer']) || ! is_a($definition['github_token_issuer'], NativeGitHubTokenIssuer::class, true)))
                        || (isset($definition['result_hook']) && (! is_string($definition['result_hook']) || ! is_a($definition['result_hook'], DirectOperationResultHook::class, true)))
                        || ! is_string($definition['projection']) || ! is_a($definition['projection'], DirectOperationProjection::class, true)
                        || (isset($definition['child_projection']) && (! is_string($definition['child_projection']) || ! is_a($definition['child_projection'], DirectOperationChildProjection::class, true)))
                        || (isset($definition['primitive']) && (! is_string($definition['primitive']) || ! is_a($definition['primitive'], DirectOperationPrimitive::class, true)))) {
                        throw new LogicException('A direct Operation needs one fixed family declaration.');
                    }
                    if (array_key_exists('execution_copies', $definition)) {
                        if (! is_array($definition['execution_copies']) || ! array_is_list($definition['execution_copies'])) {
                            throw new LogicException('Execution copy intents must be a list.');
                        }
                        foreach ($definition['execution_copies'] as $copy) {
                            self::executionCopySignature($copy);
                        }
                    }
                    foreach ($definition['alternate_results'] ?? [] as $workflow => $selector) {
                        if (! in_array($workflow, $definition['alternate_workflows'] ?? [], true)) {
                            throw new LogicException('A terminal selector must belong to a declared alternate workflow.');
                        }
                        $definition['alternate_results'][$workflow] = self::terminalResult($selector);
                    }
                    if (isset($definition['child_versions']) && (! is_array($definition['child_versions'])
                        || array_any(array_keys($definition['child_versions']), static fn ($key): bool => ! is_string($key) || ! preg_match(OperationKinds::NamePattern, $key))
                        || array_any($definition['child_versions'], static fn ($n): bool => ! is_int($n) || $n < 1))) {
                        throw new LogicException('Invalid pinned Operation child versions.');
                    }
                    if (array_key_exists('terminal_result', $definition)) {
                        $definition['terminal_result'] = self::terminalResult($definition['terminal_result']);
                    }
                    if (array_key_exists('scheduled_result', $definition)) {
                        if (! isset($definition['terminal_result'])) {
                            throw new LogicException('Scheduled observations require the original terminal result declaration.');
                        }
                        $definition['scheduled_result'] = self::scheduledResult($definition['scheduled_result']);
                    }
                    $names = [$definition['workflow'], ...($definition['alternate_workflows'] ?? [])];
                    foreach ($versions[$kind] ?? [] as $previous) {
                        if (array_intersect($names, [$previous['workflow'], ...($previous['alternate_workflows'] ?? [])]) !== []) {
                            throw new LogicException('Each version of an Operation requires distinct native workflow identities.');
                        }
                    }
                    $versions[$kind][$version] = $definition;
                }
                $definitions[$kind] = $versions[$kind][$active];
            }
        }
        if (array_filter(array_diff(array_keys($policies), array_keys($definitions)), static fn (string $kind): bool => ! OperationKinds::gatewayJob($kind)) !== []) {
            throw new LogicException('Versioned Operations require a registered native declaration.');
        }
        foreach ($policies as $kind => $policy) {
            if (OperationKinds::gatewayJob($kind) && ($policy['current'] !== 1 || $policy['retained'] !== [])) {
                throw new LogicException('A Gateway-owned job requires a versioned handler before it can change version.');
            }
        }
        $this->versions = $versions;
        $this->definitions = $definitions;
        $this->scheduledResultDefinitions();
    }

    /** @return array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string},rows:string} */
    private static function scheduledResult(mixed $declaration): array
    {
        if (! is_array($declaration) || count($declaration) !== 4
            || ! is_string($declaration['workflow'] ?? null) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $declaration['workflow']) !== 1
            || ! is_string($declaration['workflow_id'] ?? null) || ! Str::isUuid($declaration['workflow_id'])
            || ! is_string($declaration['rows'] ?? null) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $declaration['rows']) !== 1) {
            throw new LogicException('Scheduled observations require a fixed workflow identity and row selector.');
        }

        return ['workflow' => $declaration['workflow'], 'workflow_id' => $declaration['workflow_id'],
            'terminal_result' => self::terminalResult($declaration['terminal_result'] ?? null), 'rows' => $declaration['rows']];
    }

    /** @return array<string,array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string},rows?:string,context_workflow?:string,publishes_inputs?:true,projection?:class-string<ScheduledResultProjection>}> */
    public function scheduledResultDefinitions(): array
    {
        $scheduled = [];
        foreach ($this->versions as $versions) {
            foreach ($versions as $definition) {
                if (! isset($definition['scheduled_result'])) {
                    continue;
                }
                $declaration = $definition['scheduled_result'];
                $id = $declaration['workflow_id'];
                if (isset($scheduled[$id]) && $scheduled[$id] !== $declaration) {
                    throw new LogicException('One scheduled workflow must have one fixed result declaration.');
                }
                $scheduled[$id] = $declaration;
            }
        }

        foreach (glob(__DIR__.'/../*/scheduled-results.php') ?: [] as $file) {
            foreach (require $file as $id => $declaration) {
                if (! Str::isUuid($id) || isset($scheduled[$id]) || array_diff(array_keys($declaration), ['workflow', 'context_workflow', 'terminal_result', 'projection', 'publishes_inputs']) !== []
                    || (array_key_exists('context_workflow', $declaration) && (! is_string($declaration['context_workflow'])
                        || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $declaration['context_workflow']) !== 1))
                    || (array_key_exists('publishes_inputs', $declaration) && $declaration['publishes_inputs'] !== true)
                    || ! is_string($declaration['workflow'] ?? null)
                    || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $declaration['workflow']) !== 1
                    || ! is_a($declaration['projection'] ?? '', ScheduledResultProjection::class, true)) {
                    throw new LogicException('A standalone schedule requires one fixed projection and workflow identity.');
                }
                $scheduled[$id] = [...$declaration, 'workflow_id' => $id,
                    'terminal_result' => self::terminalResult($declaration['terminal_result'] ?? null)];
            }
        }

        return $scheduled;
    }

    /** @return array<string,int> The native child charts this exact parent can still start or observe. */
    public function childVersions(string $kind, int $version): array
    {
        if ($version === 1 && isset(OperationKinds::CoordinatorChildren[$kind])) {
            return OperationKinds::CoordinatorChildren[$kind];
        }

        return $this->definition($kind, $version)['child_versions'] ?? [];
    }

    /** @return array<string,array<int,array<string,int>>> */
    public function dependencyGraph(): array
    {
        $graph = array_map(static fn (array $children): array => [1 => $children], OperationKinds::CoordinatorChildren);
        foreach ($this->versions as $kind => $declarations) {
            foreach ($declarations as $version => $declaration) {
                if (($declaration['child_versions'] ?? []) !== []) {
                    $graph[$kind][$version] = $declaration['child_versions'];
                }
            }
        }

        return $graph;
    }

    /** @return array<string,array{workflow:string,schedule_write?:bool,execution_copies?:list<array{vault:string,key_pattern:string}>}> */
    public function grantDefinitions(): array
    {
        $required = array_map(static fn (array $definition): array => ['workflow' => $definition['workflow'], 'execution_copies' => $definition['execution_copies'] ?? []], $this->definitions);
        foreach ($this->versions as $kind => $versions) {
            foreach ($versions as $version => $definition) {
                if ($definition !== $this->definitions[$kind]) {
                    foreach ([$definition['workflow'], ...($definition['alternate_workflows'] ?? [])] as $workflow) {
                        $required[$kind.'@'.$version.':'.$workflow] = ['workflow' => $workflow, 'execution_copies' => $definition['execution_copies'] ?? []];
                    }
                }
            }
        }
        foreach ($this->scheduledResultDefinitions() as $schedule) {
            $required['schedule:'.$schedule['workflow']] = ['workflow' => $schedule['workflow'], 'schedule_write' => ($schedule['publishes_inputs'] ?? false) === true];
            if (isset($schedule['context_workflow'])) {
                $required['context:'.$schedule['context_workflow']] = ['workflow' => $schedule['context_workflow']];
            }
        }

        return $required;
    }

    public static function executionCopySignature(mixed $copy): string
    {
        if (! is_array($copy) || count($copy) !== 2 || ! is_string($copy['vault'] ?? null)
            || preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $copy['vault']) !== 1
            || ! is_string($copy['key_pattern'] ?? null) || ! str_starts_with($copy['key_pattern'], '^')
            || ! str_ends_with($copy['key_pattern'], '$') || @preg_match('~'.$copy['key_pattern'].'~D', '') === false) {
            throw new LogicException('Execution copy intent needs an exact vault name and an anchored key pattern.');
        }

        return $copy['vault'].':'.$copy['key_pattern'];
    }

    private static function validSecretMap(mixed $map): bool
    {
        return is_array($map) && count($map) <= 64 && ! array_any(array_keys($map), static fn (mixed $key): bool => ! is_string($key) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/D', $key) !== 1)
            && ! array_any($map, static fn (mixed $value): bool => ! is_string($value) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D', $value) !== 1);
    }

    /** @return array{job:string,step:string,fallback?:string,alternatives?:list<string>,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string} */
    private static function terminalResult(mixed $selector): array
    {
        if (! is_array($selector) || array_diff(array_keys($selector), ['job', 'step', 'fallback', 'alternatives', 'captures', 'native', 'data']) !== []
            || (array_key_exists('data', $selector) && (! is_string($selector['data']) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $selector['data']) !== 1))
            || (array_key_exists('native', $selector) && $selector['native'] !== true)
            || ! is_string($selector['job'] ?? null) || ! is_string($selector['step'] ?? null)
            || array_any([$selector['job'], $selector['step']], static fn (string $name): bool => trim($name) === '' || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1)) {
            throw new LogicException('A direct Operation needs one fixed family declaration.');
        }
        // Native only: guarded steps that run in place of `step`, such as one per placement of the same action.
        $alternatives = $selector['alternatives'] ?? [];
        if (! is_array($alternatives) || ! array_is_list($alternatives) || count($alternatives) > 4 || ($alternatives !== [] && ($selector['native'] ?? null) !== true)
            || array_any($alternatives, static fn (mixed $name): bool => ! is_string($name) || trim($name) === '' || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1)) {
            throw new LogicException('Terminal alternatives need fixed native step names.');
        }
        // A reporting step of the same job that writes the result when `step` is skipped (a ready clone's `clone-register`).
        $fallback = $selector['fallback'] ?? null;
        if ($fallback !== null && (! is_string($fallback) || trim($fallback) === '' || strlen($fallback) > 256 || preg_match('/[\x00-\x1f\x7f]/', $fallback) === 1
            || ($selector['native'] ?? null) === true || $fallback === $selector['step'])) {
            throw new LogicException('A terminal fallback needs one fixed reporting step name.');
        }

        $captures = $selector['captures'] ?? [];
        if (! is_array($captures) || count($captures) > 16) {
            throw new LogicException('Terminal captures need fixed public output selectors.');
        }
        $normalizedCaptures = [];
        foreach ($captures as $name => $capture) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $name) !== 1 || ! is_array($capture)
                || array_diff(array_keys($capture), ['job', 'step', 'field', 'artifact']) !== []
                || (array_key_exists('artifact', $capture) && (! is_string($capture['artifact']) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $capture['artifact']) !== 1))
                || (isset($capture['field']) && (! is_string($capture['field']) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $capture['field']) !== 1))) {
                throw new LogicException('Terminal captures need fixed public output selectors.');
            }
            $normalizedCaptures[$name] = [...self::terminalResult(['job' => $capture['job'] ?? null, 'step' => $capture['step'] ?? null]), ...(isset($capture['field']) ? ['field' => $capture['field']] : []), ...(isset($capture['artifact']) ? ['artifact' => $capture['artifact']] : [])];
        }

        return ['job' => $selector['job'], 'step' => $selector['step'], ...($fallback !== null ? ['fallback' => $fallback] : []), ...(isset($selector['data']) ? ['data' => $selector['data']] : []), ...($alternatives !== [] ? ['alternatives' => $alternatives] : []), ...($normalizedCaptures !== [] ? ['captures' => $normalizedCaptures] : []),
            // `native: true`: no result-reporting step; the projector gets Swamp's run data keyed by step name.
            ...(array_key_exists('native', $selector) ? ['native' => true] : [])];
    }

    public static function owns(Operation $operation): bool
    {
        return app(self::class)->definition($operation) !== null;
    }

    /** @param array<string, mixed> $input */
    public function writer(string $kind, array $input = [], ?int $nodeId = null): string
    {
        if ($this->definition($kind) === null) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This kind has no native Swamp workflow.', 503);
        }

        return 'direct';
    }

    /** @return array<string, string> */
    public function stamp(string $kind, ?string $writer): array
    {
        return [];
    }

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /** @return array{workflow: string, alternate_workflows?:list<string>, alternate_results?:array<string,array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string}>, child_versions?:array<string,int>, workflow_inputs?: bool, execution_copies?: list<array{vault:string,key_pattern:string}>, terminal_result?: array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string}, scheduled_result?: array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string,artifact?:string}>,native?:true,data?:string},rows:string}, projection: class-string<DirectOperationProjection>, child_projection?: class-string<DirectOperationChildProjection>, primitive?: class-string<DirectOperationPrimitive>, writer_scope?: array<string, bool|int|string|null>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>, github_token_issuer?: class-string<NativeGitHubTokenIssuer>}|null */
    public function definition(string|Operation $kind, ?int $version = null): ?array
    {
        if ($kind instanceof Operation) {
            $version = OperationVersions::pinned($kind);
            $kind = $kind->kind;
        }

        return $version === null ? ($this->definitions[$kind] ?? null) : ($this->versions[$kind][$version] ?? null);
    }

    /**
     * The workflow that runs this Operation: the one admission recorded in `state.swamp_start`, else the declared one, or for a kind
     * with alternate workflows the one its projection chooses for the admitted intent. Admission records it once; every start, search
     * and read of the run takes its name from here, so an Operation admitted by an older Gateway keeps the workflow it recorded.
     */
    public function workflowFor(Operation $operation): ?string
    {
        $definition = $this->definition($operation);
        if ($definition === null) {
            return null;
        }
        $start = $operation->state['swamp_start'] ?? null;
        $recorded = is_array($start) ? ($start['workflow'] ?? null) : null;
        if (is_string($recorded) && $recorded !== '') {
            if (isset(OperationVersions::policies()[$operation->kind]) && ! in_array($recorded, [$definition['workflow'], ...($definition['alternate_workflows'] ?? [])], true)) {
                throw new ResourceOperationException('operation.version_mismatch', 'The recorded workflow differs from its pinned Operation declaration.', 409);
            }

            return $recorded;
        }
        if (! isset($definition['alternate_workflows'])) {
            return $definition['workflow'];
        }
        $projection = app($definition['projection']);
        $workflow = $projection instanceof DirectOperationWorkflowChoice ? $projection->workflow($operation) : null;
        if (! in_array($workflow, [$definition['workflow'], ...$definition['alternate_workflows']], true)) {
            throw new LogicException('A direct Operation chose an undeclared workflow.');
        }

        return $workflow;
    }

    public function assertRun(Operation $operation, string $runId): void
    {
        $known = $operation->state['swamp_run_id'] ?? null;
        if (! is_string($known) || $known === '') {
            throw new ResourceOperationException('operation.run_unbound', 'The Gateway has not recorded the native start response.', 409);
        }
        if ($known !== $runId) {
            throw new ResourceOperationException('operation.run_mismatch', 'This status belongs to another Swamp run.', 409);
        }
    }

    public function projection(Operation $operation): DirectOperationProjection
    {
        if (! self::owns($operation)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
        }

        return app(($this->definition($operation) ?? throw new LogicException('Unsupported pinned Operation version.'))['projection']);
    }

    /** Only native submission calls this boundary; stored input may contain secrets.
     * @return array<string,mixed>
     */
    public function workflowInputs(Operation $operation): array
    {
        $definition = $this->definition($operation);
        $input = [];
        if (($definition['workflow_inputs'] ?? false) === true) {
            $projection = $this->projection($operation);
            $input = $projection instanceof DirectOperationWorkflowInput ? $projection->workflowInput($operation) : $projection->input($operation);
            $references = self::executionReferences($input['execution_secrets'] ?? []);
            $input['execution_secrets'] = $references === [] ? (object) [] : $references;
        }

        return [...$input, 'operation_id' => $operation->id, 'kind' => $operation->kind, 'node_id' => $operation->node_id];
    }

    /** @return array<string,array{vault:string,key:string}> */
    private static function executionReferences(mixed $references): array
    {
        if (! is_array($references)) {
            throw new ResourceOperationException('operation.execution_secrets_invalid', 'Execution secrets must be a map of vault and key names, not '.get_debug_type($references).'.', 422,
                details: ['reason' => 'not_a_map', 'type' => get_debug_type($references)]);
        }
        $result = [];
        foreach ($references as $field => $reference) {
            if (! is_string($field) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $field) !== 1
                || ! is_array($reference) || count($reference) !== 2
                || ! is_string($reference['vault'] ?? null) || ! is_string($reference['key'] ?? null)
                || preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $reference['vault']) !== 1
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,255}\z/D', $reference['key']) !== 1) {
                throw new ResourceOperationException('operation.execution_secrets_invalid', 'Execution secret "'.(is_string($field) ? $field : '#').'" must hold only a vault and a key name.', 422,
                    details: ['reason' => 'not_a_reference', 'field' => $field]);
            }
            $result[$field] = ['vault' => $reference['vault'], 'key' => $reference['key']];
        }

        return $result;
    }

    public function primitive(Operation $operation): DirectOperationPrimitive
    {
        $this->projection($operation);
        $primitive = $this->definition($operation)['primitive'] ?? null;
        if ($primitive === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This intent declares no Gateway primitive.', 422);
        }

        return app($primitive);
    }

    public function usesNativeTerminal(Operation $operation): bool
    {
        if (! isset($this->definition($operation)['terminal_result'])) {
            return false;
        }
        $projection = $this->projection($operation);

        return ! $projection instanceof NativeOperationProjection || $projection->native($operation);
    }

    /**
     * Project a family-validated, redacted result. No completion proof or execution decision.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     */
    public function project(Operation $operation, array $data, array $result): Operation
    {
        return DB::transaction(function () use ($operation, $data, $result): Operation {
            $operation->setRawAttributes(Operation::query()->lockForUpdate()->findOrFail($operation->id)->getAttributes(), true);

            return $this->record($operation, $data, $result);
        });
    }

    /** @param array<string,mixed> $data
     * @param  array<string,mixed>  $result
     */
    private function record(Operation $operation, array $data, array $result): Operation
    {
        $operation->refresh();
        if (! self::owns($operation)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
        }
        $this->assertRun($operation, ValidatedData::string($data['run_id']));
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        $observedFailure = $operation->state['native_failure'] ?? null;
        $failure = $data['failure'] ?? null;
        if (is_array($observedFailure) && (! is_array($failure)
            || ($failure['failed_step'] ?? null) !== ($observedFailure['failed_step'] ?? null)
            || ($failure['stderr_recorded'] ?? false) !== true)) {
            $failure = $observedFailure;
        }
        $failedStep = is_array($failure) && is_string($failure['failed_step'] ?? null) ? $failure['failed_step'] : null;
        if ($operation->status->terminal()) {
            if ($operation->status !== $status || ($operation->result ?? []) !== $result || $operation->error_code !== $code) {
                throw new ResourceOperationException('operation.completion_conflict', 'The Operation has another terminal result.', 409);
            }

            if (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true)
                && (! array_key_exists('native_failure', $operation->state ?? []) || ($operation->state['native_failure'] ?? null) !== $failure)) {
                app(OperationStore::class)->change($operation, ['failed_step' => $failedStep,
                    'state' => [...($operation->state ?? []), 'native_failure' => $failure]]);
            }

            return $operation;
        }
        $startedAt = Carbon::parse(ValidatedData::string($data['started_at']));
        $finishedAt = $status->terminal() ? Carbon::parse(ValidatedData::string($data['finished_at'] ?? '')) : null;
        app(OperationStore::class)->change($operation, [
            'status' => $status, 'started_at' => $operation->started_at ?? $startedAt, 'finished_at' => $finishedAt,
            'lock_key' => $status->terminal() ? null : $operation->lock_key,
            'current_step' => null, 'failed_step' => (in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true) ? $failedStep : null), 'error_code' => $code, 'result' => $result,
            'state' => [...($operation->state ?? []), 'native_failure' => in_array($status, [OperationStatus::Failed, OperationStatus::Interrupted], true) ? $failure : null],
        ]);

        return $operation->refresh();
    }
}
