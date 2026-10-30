<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Support\ValidatedData;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The closed registry of operation kinds. A kind's name is `<family>.<verb>`; its target type is one word.
 *
 * @phpstan-import-type SwampDefinition from OperationExecutionContract
 */
final readonly class OperationKinds
{
    /** The form of every kind name, `<family>.<verb>` in lower case, such as `instance.deploy`. */
    public const string NamePattern = '/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D';

    /** The form of every target type, one lower-case word, such as `instance`. */
    public const string TargetTypePattern = '/\A[a-z][a-z0-9_]*\z/D';

    public const string InstanceSmoke = 'instance.smoke';

    public const string InstanceDeploy = 'instance.deploy';

    public const string InstancePolicyEvent = 'instance.policy_event';

    public const string InstanceRollback = 'instance.rollback';

    public const string TasksPublish = 'tasks.publish';

    public const string TasksAnalyze = 'tasks.analyze';

    public const string TasksScript = 'tasks.script';

    public const string TasksCheck = 'tasks.check';

    public const string TasksProvision = 'tasks.provision';

    public const string TasksTurn = 'tasks.turn';

    public const string TasksWorker = 'tasks.worker';

    public const string TasksPark = 'tasks.park';

    /** The target type of every kind that changes an Instance, so they hold the same target. */
    public const string InstanceTarget = 'instance';

    /** All operations on a task group hold the same target. */
    public const string TaskGroupTarget = 'task_group';

    /** @var array<string, class-string<OperationKind>|SwampDefinition> */
    private array $kinds;

    /** @param array<string, class-string<OperationKind>|SwampDefinition>|null $kinds */
    public function __construct(private Container $container, ?array $kinds = null)
    {
        $this->kinds = $kinds ?? self::definitions();
        foreach ($this->kinds as $kind => $definition) {
            if (is_array($definition)) {
                self::validateDefinition($kind, $definition);
            }
        }
    }

    /** @return array<string, class-string<OperationKind>|SwampDefinition> */
    public static function definitions(): array
    {
        $kinds = [];
        foreach (glob(__DIR__.'/../*/operation-kinds.php') ?: [] as $file) {
            foreach (require $file as $name => $class) {
                if (isset($kinds[$name])) {
                    throw new LogicException("Duplicate operation kind [{$name}].");
                }
                if (is_array($class)) {
                    self::validateDefinition($name, ValidatedData::object($class));
                }
                $kinds[$name] = $class;
            }
        }

        return $kinds;
    }

    /** @param array<string, mixed> $definition */
    private static function validateDefinition(string $kind, array $definition): void
    {
        self::assertKeys($kind, 'kind', $definition, ['executor', 'completion', 'execution', 'runs', 'completion_contract', 'result_fields', 'children', 'admission', 'compensations', 'cancellation_boundary', 'cancel_guard']);
        if (($definition['executor'] ?? null) !== 'swamp') {
            throw new LogicException("Operation kind [{$kind}] requires a supported Swamp contract.");
        }
        if (! is_array($definition['result_fields'] ?? null) || ! array_is_list($definition['result_fields'])) {
            throw new LogicException("Operation kind [{$kind}] needs declared result fields.");
        }
        OperationResultFields::validate($definition['result_fields']);
        if (! array_key_exists('runs', $definition)) {
            self::assertKeys($kind, 'direct-only kind', $definition, ['executor', 'result_fields', 'admission']);
            self::validateChildren($kind, $definition);

            return;
        }
        if (! is_array($definition['completion_contract'] ?? null) || ! is_array($definition['runs'])) {
            throw new LogicException("Operation kind [{$kind}] requires a supported Swamp contract.");
        }
        if (array_key_exists('cancel_guard', $definition) && (! is_string($definition['cancel_guard']) || ! class_exists($definition['cancel_guard'])
            || ! is_a($definition['cancel_guard'], OperationCancelGuard::class, true))) {
            throw new LogicException("Operation kind [{$kind}] requires an OperationCancelGuard class.");
        }
        self::validateChildren($kind, $definition);
        if (array_key_exists('compensations', $definition) || array_key_exists('cancellation_boundary', $definition)) {
            $compensations = $definition['compensations'] ?? null;
            $boundary = $definition['cancellation_boundary'] ?? null;
            $childKinds = array_column(is_array($definition['children'] ?? null) ? $definition['children'] : [], 'kind');
            if (! is_array($compensations) || ! array_is_list($compensations) || $compensations === []
                || ! is_string($boundary) || preg_match('/\A[a-z][a-z0-9_.:-]{0,127}\z/D', $boundary) !== 1) {
                throw new LogicException("Operation kind [{$kind}] requires declared compensations and a child cancellation boundary.");
            }
            foreach ($compensations as $compensation) {
                if (! is_string($compensation) || ! in_array($compensation, $childKinds, true)) {
                    throw new LogicException("Operation kind [{$kind}] compensations must be declared child kinds.");
                }
            }
        }

        OperationCompletionContracts::validate(ValidatedData::object($definition['completion_contract']), array_column(is_array($definition['children'] ?? null) ? $definition['children'] : [], 'kind'));
        if (OperationSecretOutputs::declarations($definition['result_fields']) !== []) {
            foreach ($definition['runs'] as $run) {
                $class = is_array($run) && is_string($run['transport'] ?? null) ? (OperationTransports::definitions()[$run['transport']] ?? null) : null;
                if ($class === null || ! is_a($class, OperationSecretOutputTransport::class, true)) {
                    throw new LogicException('Secret outputs require a transport that keeps generated values transient.');
                }
            }
        }
        $settledKinds = OperationCompletionContracts::settledKinds(ValidatedData::object($definition['completion_contract']));
        if ($settledKinds !== []) {
            OperationResultFields::validateSkipped($definition['result_fields'], count($settledKinds));
        }
        foreach ($definition['runs'] as $run) {
            if (! is_array($run)) {
                throw new LogicException("Operation kind [{$kind}] requires named run contracts.");
            }
            self::assertKeys($kind, 'runs', ValidatedData::object($run), ['transport', 'name', 'input_fields', 'input_values', 'bind', 'secrets', 'resumable', 'uncertain_error_code', 'node_field', 'input_schema']);
            if (($run['transport'] ?? null) === 'archive_relay'
                && (! is_string($definition['execution'] ?? null) || ! is_a($definition['execution'], OperationFamilyArchiveRelay::class, true))) {
                throw new LogicException("Operation kind [{$kind}] requires OperationFamilyArchiveRelay for archive relay runs.");
            }
        }
    }

    /** @param array<string,mixed> $definition */
    private static function validateChildren(string $kind, array $definition): void
    {
        if (isset($definition['admission']) && (! is_string($definition['admission']) || ! is_a($definition['admission'], OperationChildAdmission::class, true))) {
            throw new LogicException("Operation kind [{$kind}] needs an owning child admission adapter.");
        }
        $children = $definition['children'] ?? [];
        if (! is_array($children) || ! array_is_list($children)) {
            throw new LogicException("Operation kind [{$kind}] needs a list of child declarations.");
        }
        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new LogicException("Operation kind [{$kind}] needs exact child declarations.");
            }
            self::assertKeys($kind, 'children', ValidatedData::object($child), ['kind', 'node_from', 'bind']);
            if (! is_string($child['kind'] ?? null) || preg_match(self::NamePattern, $child['kind']) !== 1
                || ! is_string($child['node_from'] ?? null)
                || (str_starts_with($child['node_from'], 'state.') && preg_match('/\Astate\.(?:child_intents|integration_intent|route_plan|role_intent)(?:\.|$)/D', $child['node_from']) !== 1)
                || preg_match('/\A(?:operation\.node_id|(?:input|state|intent)\.[a-zA-Z0-9_.-]+)\z/D', $child['node_from']) !== 1) {
                throw new LogicException("Operation kind [{$kind}] needs a bound child kind and Node relation.");
            }
            if (isset($child['bind'])) {
                if (! is_array($child['bind'])) {
                    throw new LogicException("Operation kind [{$kind}] needs child input bindings.");
                }
                foreach ($child['bind'] as $parameter => $path) {
                    if (! is_string($parameter) || ! is_string($path) || preg_match('/\A(?:input|intent)\.[a-zA-Z0-9_.-]+\z/D', $path) !== 1) {
                        throw new LogicException("Operation kind [{$kind}] needs exact child input paths.");
                    }
                }
            }
        }

    }

    /** @param array<string, mixed> $definition
     * @param  list<string>  $allowed
     */
    private static function assertKeys(string $kind, string $part, array $definition, array $allowed): void
    {
        $unsupported = array_diff(array_keys($definition), $allowed);
        if ($unsupported !== []) {
            throw new LogicException("Operation kind [{$kind}] has unsupported {$part} keys: ".implode(', ', $unsupported).'.');
        }
    }

    public static function executor(string $kind): string
    {
        $definition = self::definitions()[$kind] ?? throw new LogicException("Unknown operation kind [{$kind}].");

        return is_string($definition) ? 'php' : $definition['executor'];
    }

    /** @return class-string<OperationKind>|SwampDefinition|null */
    public function definition(string $kind): string|array|null
    {
        return $this->kinds[$kind] ?? null;
    }

    public function directOnly(string $kind): bool
    {
        $definition = $this->definition($kind);

        return is_array($definition) && ! array_key_exists('runs', $definition);
    }

    public function for(string $kind): OperationKind
    {
        $class = $this->kinds[$kind] ?? throw new LogicException("Unknown operation kind [{$kind}].");

        if (! is_string($class)) {
            throw new LogicException("Operation kind [{$kind}] is not executed by PHP.");
        }

        return $this->container->make($class);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }
}
