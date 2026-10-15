<?php

declare(strict_types=1);

use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

/**
 * @param  list<string>  $harnessInstances
 * @param  list<string>  $subnets
 */
function fakeCapacityHost(array $harnessInstances, array $subnets): void
{
    Process::fake(function (PendingProcess $process) use ($harnessInstances, $subnets) {
        $command = $process->command;
        assert(is_array($command));
        if (($command[3] ?? null) === 'network') {
            return Process::result(json_encode(array_map(
                static fn (string $subnet): array => [
                    'name' => 'oe-'.md5($subnet),
                    'config' => ['ipv4.address' => $subnet],
                ],
                $subnets,
            ), JSON_THROW_ON_ERROR));
        }
        $instances = array_map(
            static fn (string $name): array => [
                'name' => $name,
                'type' => 'virtual-machine',
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            ],
            $harnessInstances,
        );
        $instances[] = ['name' => 'unrelated-vm', 'type' => 'virtual-machine', 'config' => []];

        return Process::result(json_encode($instances, JSON_THROW_ON_ERROR));
    });
}

describe('HostCapacity', function () {
    beforeEach(function () {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    });

    it('counts harness VMs from the Incus inventory and hands out the lowest free network slot', function () {
        fakeCapacityHost(
            ['orbit-e2e-tst-1-aaaaaaaa-gateway', 'orbit-e2e-tst-1-aaaaaaaa-app-dev', 'orbit-e2e-tst-1-aaaaaaaa-operator'],
            ['10.232.1.1/24', '10.232.3.1/24', '192.168.1.1/24'],
        );

        expect(new HostCapacity(new IncusHost, 7)->reserveSlot(4))->toBe(2);
    });

    it('refuses when every network slot is taken', function () {
        fakeCapacityHost([], array_map(static fn (int $slot): string => '10.232.'.$slot.'.1/24', range(1, 200)));

        expect(fn () => new HostCapacity(new IncusHost, 24)->reserveSlot(1))
            ->toThrow(RuntimeException::class, 'network slots are exhausted');
    });

    it('counts the operator VM against the topology budget', function (): void {
        fakeCapacityHost([
            'orbit-e2e-tst-1-aaaaaaaa-gateway',
            'orbit-e2e-tst-1-aaaaaaaa-app-dev',
            'orbit-e2e-tst-1-aaaaaaaa-operator',
        ], []);

        expect(new HostCapacity(new IncusHost, 4)->reserveSlot(1))->toBe(1)
            ->and(fn () => new HostCapacity(new IncusHost, 4)->reserveSlot(2))
            ->toThrow(RuntimeException::class, 'capacity is exhausted: 3 harness VMs exist and the limit is 4');
    });

    it('ships a default budget of 24 VMs', function () {
        // Read the shipped literal rather than the resolved value: an operator who
        // exported ORBIT_E2E_INCUS_MAX_VMS for one run must still be able to run the suite.
        $source = (string) file_get_contents(dirname(__DIR__, 3).'/config/e2e.php');

        expect($source)->toMatch("/'max_vms'\s*=>\s*\(int\)\s*env\(\s*'ORBIT_E2E_INCUS_MAX_VMS'\s*,\s*24\s*\)/");
    });

    it('refuses a budget below one full topology and a request outside the budget', function () {
        fakeCapacityHost([], []);

        expect(fn () => new HostCapacity(new IncusHost, 3))
            ->toThrow(RuntimeException::class, 'cannot fit a full topology')
            ->and(fn () => new HostCapacity(new IncusHost, 4)->reserveSlot(0))
            ->toThrow(RuntimeException::class, 'outside host capacity')
            ->and(fn () => new HostCapacity(new IncusHost, 4)->reserveSlot(5))
            ->toThrow(RuntimeException::class, 'outside host capacity');
    });
});
