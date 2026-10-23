<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\RouteApplicationUrlInspector;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Instance;
use Throwable;

/** Supplies the expected URL for each served application directory in one agent call. */
final readonly class NativeRouteApplicationUrlInspector implements RouteApplicationUrlInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private CommandDeadline $deadline,
    ) {}

    public function inspect(Instance $instance, array $expected): array
    {
        if ($expected === []) {
            return [];
        }

        $instance->loadMissing('node');
        $directories = array_map(strval(...), array_keys($expected));
        $applications = [];
        foreach ($directories as $directory) {
            $applications[] = ['directory' => $directory, 'url' => $expected[$directory]];
        }

        try {
            $result = $this->agents->run($instance->node, 'doctor.inspect-application-urls', [
                'checkout' => rtrim($instance->checkout_path, '/'),
                'applications' => $applications,
            ], $this->deadline->cap(30.0));
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $observed = $result['matches'] ?? null;
        if (! is_array($observed) || ! array_is_list($observed) || count($observed) !== count($directories)) {
            throw new DoctorInspectionException;
        }
        $matches = [];
        foreach ($directories as $index => $directory) {
            if (! is_bool($observed[$index])) {
                throw new DoctorInspectionException;
            }
            $matches[$directory] = $observed[$index];
        }

        return $matches;
    }
}
