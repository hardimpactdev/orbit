<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\GitHub\NativeGitHubTokenIssuer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/** Fixed family declarations and intent/result projection. Swamp owns execution. */
final readonly class DirectOperation
{
    /** @var array<string, array{workflow: string, workflow_inputs?: bool, execution_copies?: list<array{vault:string,key_pattern:string}>, terminal_result?: array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>}, scheduled_result?: array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>},rows:string}, projection: class-string<DirectOperationProjection>, primitive?: class-string<DirectOperationPrimitive>, writer_scope?: array<string, bool|int|string|null>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>, github_token?: array{repository:string, permissions:array<string,string>}, github_token_issuer?: class-string<NativeGitHubTokenIssuer>}> */
    private array $definitions;

    /** @param iterable<DirectOperationContribution> $contributions */
    public function __construct(#[Tag(DirectOperationContribution::class)] iterable $contributions = [])
    {
        $definitions = [];
        $families = array_map(static fn (string $file): array => require $file, glob(__DIR__.'/../*/direct-operations.php') ?: []);
        foreach ($contributions as $contribution) {
            $families[] = $contribution->definitions();
        }
        foreach ($families as $family) {
            foreach ($family as $kind => $definition) {
                if (isset($definitions[$kind]) || preg_match(OperationKinds::NamePattern, $kind) !== 1
                    || array_diff(array_keys($definition), ['workflow', 'workflow_inputs', 'execution_copies', 'terminal_result', 'scheduled_result', 'writer_config', 'writer_state', 'projection', 'primitive', 'writer_scope', 'private_material', 'secret_outputs', 'github_token', 'github_token_issuer', 'result_hook']) !== []
                    || (array_key_exists('workflow_inputs', $definition) && ! is_bool($definition['workflow_inputs']))
                    || ! is_string($definition['workflow'] ?? null) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $definition['workflow']) !== 1
                    || ! isset($definition['projection'])
                    || (isset($definition['writer_scope']) && (! is_array($definition['writer_scope']) || array_any(array_keys($definition['writer_scope']), static fn (mixed $key): bool => ! is_string($key) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $key) !== 1) || array_any($definition['writer_scope'], static fn (mixed $value): bool => ! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_string($value))))
                    || ! self::validSecretMap($definition['private_material'] ?? []) || ! self::validSecretMap($definition['secret_outputs'] ?? [])
                    || (isset($definition['github_token_issuer']) && (! is_string($definition['github_token_issuer']) || ! is_a($definition['github_token_issuer'], NativeGitHubTokenIssuer::class, true)))
                    || (isset($definition['result_hook']) && (! is_string($definition['result_hook']) || ! is_a($definition['result_hook'], DirectOperationResultHook::class, true)))
                    || ! is_string($definition['projection']) || ! is_a($definition['projection'], DirectOperationProjection::class, true)
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
                if (array_key_exists('terminal_result', $definition)) {
                    $definition['terminal_result'] = self::terminalResult($definition['terminal_result']);
                }
                if (array_key_exists('scheduled_result', $definition)) {
                    if (! isset($definition['terminal_result'])) {
                        throw new LogicException('Scheduled observations require the original terminal result declaration.');
                    }
                    $definition['scheduled_result'] = self::scheduledResult($definition['scheduled_result']);
                }
                if (isset($definition['github_token'])) {
                    GitHubTokenGrant::from($definition['github_token']);
                }
                $definitions[$kind] = $definition;
            }
        }
        $this->definitions = $definitions;
        $this->scheduledResultDefinitions();
    }

    /** @return array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>},rows:string} */
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

    /** @return array<string,array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>},rows?:string,context_workflow?:string,projection?:class-string<ScheduledResultProjection>}> */
    public function scheduledResultDefinitions(): array
    {
        $scheduled = [];
        foreach ($this->definitions as $definition) {
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

        foreach (glob(__DIR__.'/../*/scheduled-results.php') ?: [] as $file) {
            foreach (require $file as $id => $declaration) {
                if (! Str::isUuid($id) || isset($scheduled[$id]) || array_diff(array_keys($declaration), ['workflow', 'context_workflow', 'terminal_result', 'projection']) !== []
                    || (array_key_exists('context_workflow', $declaration) && (! is_string($declaration['context_workflow'])
                        || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $declaration['context_workflow']) !== 1))
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

    /** @return array<string,array{workflow:string,execution_copies?:list<array{vault:string,key_pattern:string}>}> */
    public function grantDefinitions(): array
    {
        $required = array_map(static fn (array $definition): array => ['workflow' => $definition['workflow'], 'execution_copies' => $definition['execution_copies'] ?? []], $this->definitions);
        foreach ($this->scheduledResultDefinitions() as $schedule) {
            $required['schedule:'.$schedule['workflow']] = ['workflow' => $schedule['workflow']];
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

    /** @return array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>} */
    private static function terminalResult(mixed $selector): array
    {
        if (! is_array($selector) || array_diff(array_keys($selector), ['job', 'step', 'captures']) !== []
            || ! is_string($selector['job'] ?? null) || ! is_string($selector['step'] ?? null)
            || array_any([$selector['job'], $selector['step']], static fn (string $name): bool => trim($name) === '' || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1)) {
            throw new LogicException('A direct Operation needs one fixed family declaration.');
        }

        $captures = $selector['captures'] ?? [];
        if (! is_array($captures) || count($captures) > 16) {
            throw new LogicException('Terminal captures need fixed public output selectors.');
        }
        $normalizedCaptures = [];
        foreach ($captures as $name => $capture) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $name) !== 1 || ! is_array($capture)
                || array_diff(array_keys($capture), ['job', 'step', 'field']) !== []
                || (isset($capture['field']) && (! is_string($capture['field']) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $capture['field']) !== 1))) {
                throw new LogicException('Terminal captures need fixed public output selectors.');
            }
            $normalizedCaptures[$name] = [...self::terminalResult(['job' => $capture['job'] ?? null, 'step' => $capture['step'] ?? null]), ...(isset($capture['field']) ? ['field' => $capture['field']] : [])];
        }

        return ['job' => $selector['job'], 'step' => $selector['step'], ...($normalizedCaptures !== [] ? ['captures' => $normalizedCaptures] : [])];
    }

    public static function owns(Operation $operation): bool
    {
        return app(self::class)->definition($operation->kind) !== null;
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

    /** @return array{workflow: string, workflow_inputs?: bool, execution_copies?: list<array{vault:string,key_pattern:string}>, terminal_result?: array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>}, scheduled_result?: array{workflow:string,workflow_id:string,terminal_result:array{job:string,step:string,captures?:array<string,array{job:string,step:string,field?:string}>},rows:string}, projection: class-string<DirectOperationProjection>, primitive?: class-string<DirectOperationPrimitive>, writer_scope?: array<string, bool|int|string|null>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>, github_token?: array{repository:string, permissions:array<string,string>}, github_token_issuer?: class-string<NativeGitHubTokenIssuer>}|null */
    public function definition(string $kind): ?array
    {
        return $this->definitions[$kind] ?? null;
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

        return app($this->definitions[$operation->kind]['projection']);
    }

    /** Only native submission calls this boundary; stored input may contain secrets.
     * @return array<string,mixed>
     */
    public function workflowInputs(Operation $operation): array
    {
        $definition = $this->definition($operation->kind);
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
            throw new ResourceOperationException('operation.execution_secrets_invalid', 'Execution secrets must contain only vault and key names.', 422);
        }
        $result = [];
        foreach ($references as $field => $reference) {
            if (! is_string($field) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $field) !== 1
                || ! is_array($reference) || count($reference) !== 2
                || ! is_string($reference['vault'] ?? null) || ! is_string($reference['key'] ?? null)
                || preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $reference['vault']) !== 1
                || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,255}\z/D', $reference['key']) !== 1) {
                throw new ResourceOperationException('operation.execution_secrets_invalid', 'Execution secrets must contain only vault and key names.', 422);
            }
            $result[$field] = ['vault' => $reference['vault'], 'key' => $reference['key']];
        }

        return $result;
    }

    public function primitive(Operation $operation): DirectOperationPrimitive
    {
        $this->projection($operation);
        $primitive = $this->definitions[$operation->kind]['primitive'] ?? null;
        if ($primitive === null) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This intent declares no Gateway primitive.', 422);
        }

        return app($primitive);
    }

    public function usesNativeTerminal(Operation $operation): bool
    {
        if (! isset($this->definitions[$operation->kind]['terminal_result'])) {
            return false;
        }
        $projection = $this->projection($operation);

        return ! $projection instanceof NativeOperationProjection || $projection->native($operation);
    }

    public function githubTokenGrant(Operation $operation): GitHubTokenGrant
    {
        $projection = $this->projection($operation);
        if ($projection instanceof DirectOperationGitHubGrant) {
            return $projection->githubTokenGrant($operation);
        }
        $grant = $this->definitions[$operation->kind]['github_token'] ?? null;
        if ($grant === null) {
            throw new ResourceOperationException('operation.github_token_forbidden', 'This intent declares no GitHub credential.', 403);
        }

        return GitHubTokenGrant::from($grant);
    }

    /**
     * Project a family-validated, redacted result. No completion proof or execution decision.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $result
     */
    public function project(Operation $operation, array $data, array $result): Operation
    {
        $operation->refresh();
        if (! self::owns($operation)) {
            throw new ResourceOperationException('operation.direct_invalid', 'A declared direct Swamp intent is required.', 422);
        }
        $this->assertRun($operation, ValidatedData::string($data['run_id']));
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ($operation->status->terminal()) {
            if ($operation->status !== $status || ($operation->result ?? []) !== $result || $operation->error_code !== $code) {
                throw new ResourceOperationException('operation.completion_conflict', 'The Operation has another terminal result.', 409);
            }

            return $operation;
        }
        $startedAt = Carbon::parse(ValidatedData::string($data['started_at']));
        $finishedAt = $status->terminal() ? Carbon::parse(ValidatedData::string($data['finished_at'] ?? '')) : null;
        app(OperationStore::class)->change($operation, [
            'status' => $status, 'started_at' => $operation->started_at ?? $startedAt, 'finished_at' => $finishedAt,
            'lock_key' => $status->terminal() ? null : $operation->lock_key,
            'current_step' => null, 'failed_step' => null, 'error_code' => $code, 'result' => $result,
        ]);

        return $operation->refresh();
    }
}
