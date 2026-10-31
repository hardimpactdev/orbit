<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationGatewayCommand;
use App\Domain\Operations\OperationGatewayCommands;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Routes\ClusterRouterParentExecution;
use App\Domain\Routes\RouteParentExecution;
use App\Models\Operation;
use PHPUnit\Framework\AssertionFailedError;

/** Owning-family stand-ins: the Route tests exercise stamped admission and proven child completion. */
final class RouteForeignChildKinds
{
    public const array Names = ['instance.prepare_php_runtime', 'instance.prepare_served_web_roots', 'instance.sync_environment',
        'instance.destroy', 'node.role_converge', 'node.role_remove'];

    public static function register(bool $simulateNodeChildren = false): void
    {
        $definitions = OperationKinds::definitions();
        $names = self::Names;
        if ($simulateNodeChildren) {
            foreach ($definitions as $kind => $definition) {
                if (is_array($definition) && isset($definition['admission']) && ! in_array($kind, [...RouteParentExecution::Kinds, ...ClusterRouterParentExecution::Kinds], true) && ! str_starts_with($kind, 'route.analytics_')) {
                    $names[] = $kind;
                }
            }
        }
        foreach ($names as $kind) {
            $definitions[$kind] = [
                'executor' => 'swamp', 'admission' => in_array($kind, self::Names, true) ? RouteForeignChildAdmission::class : ($definitions[$kind]['admission'] ?? RouteForeignChildAdmission::class), 'completion' => RouteForeignChildCompletion::class,
                'runs' => [['transport' => 'gateway', 'name' => 'route-fixture.prove-child', 'input_fields' => [], 'input_values' => ['proof' => 'ready']]],
                'completion_contract' => ['step' => 'fixture-proof', 'transport' => 'gateway', 'name' => 'route-fixture.prove-child', 'result' => ['ready' => true]],
                'result_fields' => ['ready', 'error_code'],
            ];
        }
        foreach ([...RouteParentExecution::Kinds, ...ClusterRouterParentExecution::Kinds] as $kind) {
            $children = $definitions[$kind]['children'];
            foreach (self::Names as $child) {
                if (! in_array($child, array_column($children, 'kind'), true)) {
                    $children[] = ['kind' => $child, 'node_from' => 'intent.node_ids'];
                }
            }
            $definitions[$kind]['children'] = $children;
        }
        app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
        $commands = [];
        foreach (glob(app_path('Domain/*/operation-commands.php')) as $file) {
            $commands = [...$commands, ...require $file];
        }
        app()->instance(OperationGatewayCommands::class, new OperationGatewayCommands([...$commands, 'route-fixture.prove-child' => RouteForeignChildProof::class]));
    }
}

final readonly class RouteForeignChildAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute($intent['kind'], 'fixture_child', crc32($intent['key']) + 1,
            $intent['node_id'], $intent['input'], $parent->triggered_by, $parent->request_id);
    }
}

final readonly class RouteForeignChildCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}

final class RouteForeignChildProof implements OperationGatewayCommand
{
    /** @var list<array{kind:string,node_id:int|null,input:array<string,mixed>}> */
    public array $calls = [];

    public ?string $failKind = null;

    public ?\Closure $observe = null;

    public ?AssertionFailedError $observationFailure = null;

    /** @var array<string,int> */
    public array $failures = [];

    public function schema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['proof'], 'properties' => ['proof' => ['enum' => ['ready']]]];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        $this->calls[] = ['kind' => $operation->kind, 'node_id' => $operation->node_id, 'input' => $operation->input];

        try {
            ($this->observe ?? static function (): void {})($operation);
        } catch (AssertionFailedError $failure) {
            $this->observationFailure ??= $failure;
        }
        $key = $operation->child_key ?? $operation->kind;
        if (($this->failures[$key] ?? 0) > 0) {
            $this->failures[$key]--;

            return ['error_code' => 'fixture.child_failed'];
        }

        return $operation->kind === $this->failKind ? ['error_code' => 'fixture.child_failed'] : ['ready' => true];
    }
}
