<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Operations\OperationRunBudgets;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Fleet\Footprint\SourceClosure;
use App\Infrastructure\Fleet\Footprint\SourceDigest;
use App\Infrastructure\Logging\RequestLogContext;
use App\Infrastructure\Swamp\SwampHandshakes;
use App\Models\Node;
use Illuminate\Container\Container;

it('accounts for worker statics and scopes services that retain a Node', function (): void {
    $allowed = [
        RealtimeConnection::class => ['handlers'], // Two endpoint- and CA-bound HTTP transports; no caller state.
        RecordEventBroadcaster::class => ['immediate'], // Nested calls unwind the depth in finally.
        OperationRunBudgets::class => ['history'], // Only set during a scheduler tick; restored in finally.
        ActivityShutdownFinalizer::class => ['armed', 'shutdownRegistered'], // Drained per request, one process callback.
        SourceClosure::class => ['cache', 'roots'], // Derived only from the release's immutable source.
        SourceDigest::class => ['cache'], // Hashes the same immutable release.
        RequestLogContext::class => ['depth', 'requestId'], // Cleared at request termination and on worker errors.
        SwampHandshakes::class => ['warned'], // Suppresses repeated reports for this process.
    ];
    $scoped = new ReflectionProperty(Container::class, 'scopedInstances')->getValue(app());
    $classes = require base_path('vendor/composer/autoload_classmap.php');
    expect($classes)->toHaveKey(ActivityShutdownFinalizer::class);
    foreach (array_keys($classes) as $class) {
        if (! str_starts_with($class, 'App\\') && ! str_starts_with($class, 'Orbit\\Extensions\\')) {
            continue;
        }
        $type = new ReflectionClass($class);
        foreach ($type->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            if ($property->isStatic()) {
                expect($allowed[$class] ?? [])->toContain($property->getName());
            }
            $propertyType = $property->getType();
            if ($propertyType instanceof ReflectionNamedType && $propertyType->getName() === Node::class) {
                foreach ([$class, ...$type->getInterfaceNames()] as $binding) {
                    if (app()->isShared($binding)) {
                        expect($scoped)->toContain($binding);
                    }
                }
            }
        }
    }
});
