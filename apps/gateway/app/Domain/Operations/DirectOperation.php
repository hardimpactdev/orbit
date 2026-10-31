<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Support\Carbon;
use LogicException;

/** Fixed family declarations and intent/result projection. Swamp owns execution. */
final readonly class DirectOperation
{
    /** @var array<string, array{workflow: string, workflow_inputs?: bool, terminal_result?: array{job:string,step:string}, projection: class-string<DirectOperationProjection>, primitive?: class-string<DirectOperationPrimitive>, writer_scope?: array<string, bool|int|string|null>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>, github_token?: array{repository:string, permissions:array<string,string>}}> */
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
                    || array_diff(array_keys($definition), ['workflow', 'workflow_inputs', 'terminal_result', 'writer_config', 'writer_state', 'projection', 'primitive', 'writer_scope', 'private_material', 'secret_outputs', 'github_token', 'result_hook']) !== []
                    || (array_key_exists('workflow_inputs', $definition) && ! is_bool($definition['workflow_inputs']))
                    || ! is_string($definition['workflow'] ?? null) || preg_match('/\A[a-z][a-z0-9_.-]*\z/D', $definition['workflow']) !== 1
                    || ! isset($definition['projection'])
                    || (isset($definition['writer_scope']) && (! is_array($definition['writer_scope']) || array_any(array_keys($definition['writer_scope']), static fn (mixed $key): bool => ! is_string($key) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $key) !== 1) || array_any($definition['writer_scope'], static fn (mixed $value): bool => ! is_null($value) && ! is_bool($value) && ! is_int($value) && ! is_string($value))))
                    || ! self::validSecretMap($definition['private_material'] ?? []) || ! self::validSecretMap($definition['secret_outputs'] ?? [])
                    || (isset($definition['result_hook']) && (! is_string($definition['result_hook']) || ! is_a($definition['result_hook'], DirectOperationResultHook::class, true)))
                    || ! is_string($definition['projection']) || ! is_a($definition['projection'], DirectOperationProjection::class, true)
                    || (isset($definition['primitive']) && (! is_string($definition['primitive']) || ! is_a($definition['primitive'], DirectOperationPrimitive::class, true)))) {
                    throw new LogicException('A direct Operation needs one fixed family declaration.');
                }
                if (array_key_exists('terminal_result', $definition)) {
                    $definition['terminal_result'] = self::terminalResult($definition['terminal_result']);
                }
                if (isset($definition['github_token'])) {
                    GitHubTokenGrant::from($definition['github_token']);
                }
                $definitions[$kind] = $definition;
            }
        }
        $this->definitions = $definitions;
    }

    private static function validSecretMap(mixed $map): bool
    {
        return is_array($map) && count($map) <= 64 && ! array_any(array_keys($map), static fn (mixed $key): bool => ! is_string($key) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*\z/D', $key) !== 1)
            && ! array_any($map, static fn (mixed $value): bool => ! is_string($value) || preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D', $value) !== 1);
    }

    /** @return array{job:string,step:string} */
    private static function terminalResult(mixed $selector): array
    {
        if (! is_array($selector) || count($selector) !== 2
            || ! is_string($selector['job'] ?? null) || ! is_string($selector['step'] ?? null)
            || array_any([$selector['job'], $selector['step']], static fn (string $name): bool => trim($name) === '' || strlen($name) > 256 || preg_match('/[\x00-\x1f\x7f]/', $name) === 1)) {
            throw new LogicException('A direct Operation needs one fixed family declaration.');
        }

        return ['job' => $selector['job'], 'step' => $selector['step']];
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

    /** @return array{workflow: string, workflow_inputs?: bool, terminal_result?: array{job:string,step:string}, projection: class-string<DirectOperationProjection>, primitive?: class-string<DirectOperationPrimitive>, writer_scope?: array<string, bool|int|string|null>, private_material?: array<string,string>, secret_outputs?: array<string,string>, result_hook?: class-string<DirectOperationResultHook>, github_token?: array{repository:string, permissions:array<string,string>}}|null */
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

    /** The opt-in uses the existing public projection boundary; stored input may contain secrets.
     * @return array<string,mixed>
     */
    public function workflowInputs(Operation $operation): array
    {
        $definition = $this->definition($operation->kind);
        $input = [];
        if (($definition['workflow_inputs'] ?? false) === true) {
            $input = $this->projection($operation)->input($operation);
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

    public function githubTokenGrant(Operation $operation): GitHubTokenGrant
    {
        $this->projection($operation);
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
