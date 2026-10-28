<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use Illuminate\Contracts\Container\Container;
use App\Support\ValidatedData;
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
        self::assertKeys($kind, 'kind', $definition, ['executor', 'completion', 'execution', 'runs', 'completion_contract', 'result_fields']);
        if (($definition['executor'] ?? null) !== 'swamp' || ! is_array($definition['completion_contract'] ?? null) || ! is_array($definition['runs'] ?? null)) {
            throw new LogicException("Operation kind [{$kind}] requires a supported Swamp contract.");
        }
        self::assertKeys($kind, 'completion_contract', ValidatedData::object($definition['completion_contract']), ['step', 'transport', 'name', 'result']);
        foreach ($definition['runs'] as $run) {
            if (! is_array($run)) {
                throw new LogicException("Operation kind [{$kind}] requires named run contracts.");
            }
            self::assertKeys($kind, 'runs', ValidatedData::object($run), ['transport', 'name', 'input_fields', 'input_values', 'bind', 'secrets', 'resumable', 'uncertain_error_code', 'node_field', 'input_schema']);
        }
    }

    /** @param array<string, mixed> $definition
     * @param list<string> $allowed
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
