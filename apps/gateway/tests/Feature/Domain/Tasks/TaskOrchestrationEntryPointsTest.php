<?php

declare(strict_types=1);

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\UpdateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Data\Tasks\UpdateTaskGroupData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\InstanceProvisioning;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskClaimAdmission;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskTurnMode;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\Support\TaskOrchestrationEntryPoints;

/** @return array<string, class-string> */
function task_orchestration_sources(string $directory): array
{
    $sources = [];
    foreach (File::allFiles(app_path($directory)) as $file) {
        if ($file->getExtension() === 'php') {
            $sources[$file->getPathname()] = 'App\\'.str_replace('/', '\\', $directory.'/'.substr($file->getRelativePathname(), 0, -4));
        }
    }

    return $sources;
}

/** @return list<string> */
function task_public_methods(ReflectionClass $reflection): array
{
    if ($reflection->isInterface() || $reflection->isAbstract() || $reflection->isEnum()) {
        return [];
    }
    $methods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (! $method->isConstructor() && $method->getDeclaringClass()->name === $reflection->name) {
            $methods[] = $method->name;
        }
    }

    return $methods;
}

/** @return array<string, list<string>> */
function task_public_group_methods(): array
{
    $found = [];
    foreach (['Domain/Tasks', 'Actions/Tasks'] as $directory) {
        foreach (task_orchestration_sources($directory) as $class) {
            $methods = task_public_methods(new ReflectionClass($class));
            if ($methods !== []) {
                $found[$class] = $methods;
            }
        }
    }

    return $found;
}

/** @return array<string, list<string>> */
function task_effect_sites(): array
{
    $parser = new ParserFactory()->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $sites = [];
    foreach (['Domain/Tasks', 'Actions/Tasks'] as $directory) {
        foreach (task_orchestration_sources($directory) as $file => $class) {
            $nodes = $parser->parse(file_get_contents($file));
            foreach ($finder->findInstanceOf($nodes, ClassMethod::class) as $method) {
                $effect = $finder->findFirst($method->stmts ?? [], static function (Node $node): bool {
                    return ($node instanceof MethodCall && $node->name instanceof Identifier
                            && in_array($node->name->name, ['send', 'respond', 'startCheck', 'provision', 'start'], true))
                        || ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->name === 'create'
                            && $node->var instanceof PropertyFetch && $node->var->name instanceof Identifier && $node->var->name->name === 'driver')
                        || (($node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef)
                            && $node->var instanceof PropertyFetch && $node->var->name instanceof Identifier && $node->var->name->name === 'reserved_at')
                        || ($node instanceof String_ && $node->value === 'reserved_at')
                        || ($node instanceof New_ && $node->class instanceof Name && $node->class->toString() === 'AgentThreadStart');
                });
                if ($effect !== null) {
                    $sites[$class][] = $method->name->name;
                }
            }
        }
    }

    return $sites;
}

it('inventories every public Tasks group entry point and proves the effect entry points refuse or skip external groups', function (): void {
    $normalize = static function (array $map): array {
        ksort($map);
        foreach ($map as &$methods) {
            sort($methods);
        }

        return $map;
    };
    $effectCoverage = [];
    foreach ([TaskOrchestrationEntryPoints::EffectProofs, TaskOrchestrationEntryPoints::EffectInfrastructure] as $map) {
        foreach ($map as $class => $methods) {
            $effectCoverage[$class] = [...($effectCoverage[$class] ?? []), ...array_keys($methods)];
        }
    }
    expect($normalize(task_effect_sites()))->toBe($normalize($effectCoverage));
    $classified = [];
    foreach ([TaskOrchestrationEntryPoints::Refuses, TaskOrchestrationEntryPoints::Skips] as $map) {
        foreach ($map as $class => $methods) {
            $classified[$class] = [...($classified[$class] ?? []), ...$methods];
        }
    }
    foreach (TaskOrchestrationEntryPoints::OtherBoundaries as $class => $methods) {
        $classified[$class] = [...($classified[$class] ?? []), ...array_keys($methods)];
        foreach ($methods as $reason) {
            expect($reason)->not->toBeEmpty();
        }
    }
    expect($normalize(task_public_group_methods()))->toBe($normalize($classified));

    // An ID-based wrapper can enter an existing private sender without declaring a Task parameter.
    $unlistedSender = new class
    {
        public function retryReviewer(int $taskId, string $message): ?int
        {
            return $this->sendReviewer(Task::query()->findOrFail($taskId), $message, new TaskTurnMode(consult: true));
        }

        private function sendReviewer(Task $task, string $message, TaskTurnMode $mode): ?int
        {
            throw new LogicException('Discovery must never execute the unlisted sender.');
        }
    };
    $withUnlistedSender = task_public_group_methods();
    $withUnlistedSender[TaskAgentSpawner::class] = [...$withUnlistedSender[TaskAgentSpawner::class], ...task_public_methods(new ReflectionClass($unlistedSender))];
    expect($withUnlistedSender[TaskAgentSpawner::class])->toContain('retryReviewer');
    expect($normalize($withUnlistedSender))->not->toBe($normalize($classified));

    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'External', 'slug' => 'external', 'repository_url' => 'git@example.test:external.git']);
    $group = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Waiting', 'brief' => 'Waiting.', 'orchestration' => TaskOrchestration::External,
        'status' => TaskGroupStatus::Reserved, 'reserved_at' => now()->subDay(),
    ]);
    $task = Task::query()->create(['parent_id' => $group->id, 'title' => 'One', 'brief' => 'One.']);
    $task->setRelation('parent', $group);
    $before = [$group->refresh()->getRawOriginal(), $task->refresh()->getRawOriginal()];
    $todo = Task::topLevel()->create([
        'project_id' => $project->id, 'title' => 'Ready', 'brief' => 'Ready.', 'orchestration' => TaskOrchestration::External,
        'status' => TaskGroupStatus::Todo,
    ]);
    test()->mock(InstanceProvisioning::class)->shouldNotReceive('provision');
    $scheduler = app(TaskScheduler::class);
    $exercised = [];
    foreach (TaskOrchestrationEntryPoints::Refuses as $class => $methods) {
        foreach ($methods as $name) {
            $method = new ReflectionMethod($class, $name);
            $arguments = [];
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $typeName = $type instanceof ReflectionNamedType ? $type->getName() : '';
                $arguments[] = match (true) {
                    $typeName === Task::class => in_array($parameter->name, ['group', 'parent'], true) ? $group : $task,
                    $typeName === TaskScheduler::class => $scheduler,
                    $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                    $typeName === 'array' => $name === 'execute' ? ['type' => 'resolution', 'body' => 'Continue.'] : [],
                    $typeName === 'string' => 'Continue.',
                    $typeName === 'int' => 1,
                    $typeName === 'bool' => false,
                    $typeName === Closure::class => static function (): void {},
                    is_a($typeName, Collection::class, true) => new $typeName,
                    enum_exists($typeName) => $typeName::cases()[0],
                    default => new ReflectionClass($typeName)->newInstanceWithoutConstructor(),
                };
            }
            foreach ($method->getParameters() as $index => $parameter) {
                if ($parameter->isPassedByReference()) {
                    $arguments[$index] = &$arguments[$index];
                }
            }
            try {
                $method->invokeArgs(app($class), $arguments);
                test()->fail($class.'::'.$name.' did not refuse an external group.');
            } catch (ResourceOperationException $exception) {
                expect($exception->errorCode, $class.'::'.$name)->toBe('tasks.orchestrated_externally');
                expect($exception->status)->toBe(409);
                $exercised[] = $class.'::'.$name;
            }
            expect([$group->fresh()->getRawOriginal(), $task->fresh()->getRawOriginal()], $class.'::'.$name)->toBe($before);
        }
    }
    foreach (TaskOrchestrationEntryPoints::Skips as $class => $methods) {
        foreach ($methods as $name) {
            $service = app($class);
            if ($class === CreateTaskGroupAction::class) {
                $created = $service->execute(new CreateTaskGroupData(
                    $project->id, 'Created', 'Created.', TaskGroupStatus::Todo,
                    [new TaskInputData('One', 'One.', [['id' => 'done', 'type' => 'review', 'description' => 'Done.']])],
                    orchestration: TaskOrchestration::External,
                ));
                expect($created->status)->toBe(TaskGroupStatus::Todo)
                    ->and($created->reserved_at)->toBeNull()->and($created->taskable_id)->toBeNull();
            } elseif ($class === UpdateTaskGroupAction::class) {
                $created->update(['status' => TaskGroupStatus::Backlog]);
                $updated = $service->execute($created, new UpdateTaskGroupData(null, null, TaskGroupStatus::Todo));
                expect($updated->status)->toBe(TaskGroupStatus::Todo)
                    ->and($updated->reserved_at)->toBeNull()->and($updated->taskable_id)->toBeNull();
            } else {
                $arguments = $class === TaskClaimAdmission::class && $name !== 'releaseStaleReservations' ? [$scheduler] : [];
                $result = $service->$name(...$arguments);
                expect($result, $class.'::'.$name)->toBe(match ($name) {
                    'tick' => [],
                    'claimNext' => null,
                    default => 0,
                });
            }
            $exercised[] = $class.'::'.$name;
            expect($group->fresh()->getRawOriginal(), $class.'::'.$name)->toBe($before[0]);
            expect($todo->fresh()->status)->toBe(TaskGroupStatus::Todo)->and($todo->fresh()->reserved_at)->toBeNull();
        }
    }
    foreach (TaskOrchestrationEntryPoints::EffectProofs as $class => $methods) {
        foreach ($methods as $method => $cases) {
            expect($cases, $class.'::'.$method)->not->toBeEmpty();
            if (new ReflectionMethod($class, $method)->isPublic()) {
                expect($exercised, $class.'::'.$method)->toContain($class.'::'.$method);
            }
            foreach ($cases as $case) {
                expect($exercised, $class.'::'.$method)->toContain($case);
            }
        }
    }
    foreach (TaskOrchestrationEntryPoints::EffectInfrastructure as $methods) {
        foreach ($methods as $reason) {
            expect($reason)->not->toBeEmpty();
        }
    }
    expect($group->fresh()->getRawOriginal())->toBe($before[0]);
    expect($todo->fresh()->status)->toBe(TaskGroupStatus::Todo)->and($todo->fresh()->reserved_at)->toBeNull();
});
