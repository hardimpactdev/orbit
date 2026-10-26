<?php

declare(strict_types=1);

namespace Tests\Support;

use App\E2E\IncusHost;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * An in-memory Incus behind `Process::fake()`: the `incus` CLI calls IncusHost makes, the concurrent exec helper,
 * and the host firewall helper. It keeps instances and networks, so tests drive the real IncusHost and assert on the
 * state and the calls that remain. Like a task VM, it holds the stopped template that every guest is a copy of, with
 * its root disk on the topology seed pool.
 */
final class FakeIncus
{
    /** @var array<string, array{type:string,status:string,config:array<string,string>,devices:array<string,array<string,string>>}> */
    public array $instances = [];

    /** @var array<string, array{config:array<string,string>}> */
    public array $networks = [];

    /** @var list<list<string>> every `incus` argument vector after the project, in order */
    public array $calls = [];

    /** @var list<array{instance:string,argv:list<string>,stdin:?string}> every guest command, in order */
    public array $execs = [];

    /** @var list<array{operation:string,network:string}> */
    public array $firewall = [];

    /** @var (Closure(string, list<string>, ?string): (array{0:string,1:string,2:int}|null))|null */
    private ?Closure $responder = null;

    /** @var (Closure(list<string>): ?string)|null fails an `incus` call with this message when it returns one */
    private ?Closure $failure = null;

    public function __construct(public string $project = 'default')
    {
        $this->instances[IncusHost::TEMPLATE] = [
            'type' => 'virtual-machine',
            'status' => 'Stopped',
            'config' => ['volatile.base_image' => hash('sha256', 'node-template')],
            'devices' => ['root' => ['type' => 'disk', 'path' => '/', 'pool' => IncusHost::POOL]],
        ];
    }

    /** Fakes only `incus` and the host helpers; every other process, such as `git` or `php`, really runs. */
    public function install(): self
    {
        $handler = fn (PendingProcess $process): ProcessResult => $this->handle($process);
        Process::fake(["'incus' *" => $handler, "'python3' *" => $handler]);

        return $this;
    }

    /**
     * Answers guest commands. The responder returns [stdout, stderr, exit code], or null for a quiet success.
     *
     * @param  Closure(string, list<string>, ?string): (array{0:string,1:string,2:int}|null)  $responder
     */
    public function respond(Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    /** @param Closure(list<string>): ?string $failure */
    public function failWhen(Closure $failure): self
    {
        $this->failure = $failure;

        return $this;
    }

    /** @return array<string, array{type:string,status:string,config:array<string,string>,devices:array<string,array<string,string>>}> every instance but the template */
    public function guests(): array
    {
        return array_diff_key($this->instances, [IncusHost::TEMPLATE => true]);
    }

    /** @return list<list<string>> the calls whose first arguments are these */
    public function callsOf(string ...$prefix): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => array_slice($call, 0, count($prefix)) === $prefix));
    }

    private function handle(PendingProcess $process): ProcessResult
    {
        $command = $process->command;
        if (! is_array($command)) {
            throw new RuntimeException('FakeIncus expects an argument vector.');
        }
        $command = array_values(array_map(strval(...), $command));
        if (($command[0] ?? null) === 'python3' && str_ends_with($command[1] ?? '', '/resources/host/exec-all.py')) {
            return $this->execAll((string) $process->input);
        }
        if (($command[0] ?? null) === 'python3' && str_ends_with($command[1] ?? '', '/resources/host/reconcile-firewall.py')) {
            $request = json_decode((string) $process->input, true, 8, JSON_THROW_ON_ERROR);
            $this->firewall[] = ['operation' => $request['operation'], 'network' => $request['network']];

            return Process::result("{\"changed\":true}\n");
        }
        if (($command[0] ?? null) !== 'incus' || ($command[1] ?? null) !== '--project' || ($command[2] ?? null) !== $this->project) {
            return Process::result();
        }
        $arguments = array_slice($command, 3);
        $this->calls[] = $arguments;
        $failure = $this->failure === null ? null : ($this->failure)($arguments);
        if ($failure !== null) {
            return Process::result('', $failure, 1);
        }

        return $this->incus($arguments, $process->input === null ? null : (string) $process->input);
    }

    /** @param list<string> $arguments */
    private function incus(array $arguments, ?string $stdin): ProcessResult
    {
        $name = static fn (string $target): string => str_contains($target, ':') ? substr($target, strpos($target, ':') + 1) : $target;

        switch ($arguments[0] ?? null) {
            case 'list':
                $selected = $name($arguments[1] ?? '');

                return $this->json($selected === '' ? array_keys($this->instances) : (isset($this->instances[$selected]) ? [$selected] : []));
            case 'copy':
                return $this->copy($arguments, $name);
            case 'start':
                $this->instances[$name($arguments[1])]['status'] = 'Running';

                return Process::result();
            case 'stop':
                $this->instances[$name($arguments[1])]['status'] = 'Stopped';

                return Process::result();
            case 'delete':
                unset($this->instances[$name($arguments[1])]);

                return Process::result();
            case 'config':
                if (($arguments[1] ?? null) === 'device' && ($arguments[2] ?? null) === 'add') {
                    $device = ['type' => $arguments[5]];
                    foreach (array_slice($arguments, 6) as $pair) {
                        [$key, $value] = explode('=', $pair, 2);
                        $device[$key] = $value;
                    }
                    $this->instances[$name($arguments[3])]['devices'][$arguments[4]] = $device;
                }

                return Process::result();
            case 'exec':
                $instance = $name($arguments[1]);

                return $this->guest($instance, array_slice($arguments, 3), $stdin);
            case 'network':
                return $this->network($arguments, $name);
            default:
                return Process::result();
        }
    }

    /**
     * A copy keeps the source's type, configuration, and devices; each `--config` overrides one key.
     *
     * @param  list<string>  $arguments
     * @param  Closure(string): string  $name
     */
    private function copy(array $arguments, Closure $name): ProcessResult
    {
        $source = $this->instances[$name($arguments[1])] ?? null;
        if ($source === null) {
            return Process::result('', 'Error: Instance not found', 1);
        }
        $config = $source['config'];
        for ($index = 3; $index < count($arguments); $index++) {
            if ($arguments[$index] === '--config') {
                [$key, $value] = explode('=', $arguments[++$index], 2);
                $config[$key] = $value;
            }
        }
        $this->instances[$name($arguments[2])] = [...$source, 'status' => 'Stopped', 'config' => $config];

        return Process::result();
    }

    /**
     * @param  list<string>  $arguments
     * @param  Closure(string): string  $name
     */
    private function network(array $arguments, Closure $name): ProcessResult
    {
        switch ($arguments[1] ?? null) {
            case 'list':
                $networks = [];
                foreach ($this->networks as $network => $state) {
                    $usedBy = [];
                    foreach ($this->instances as $instance => $guest) {
                        if (($guest['devices']['eth0']['network'] ?? null) === $network) {
                            $usedBy[] = "/1.0/instances/{$instance}";
                        }
                    }
                    $networks[] = ['name' => $network, 'type' => 'bridge', 'managed' => true, 'config' => $state['config'], 'used_by' => $usedBy];
                }

                return Process::result(json_encode($networks, JSON_THROW_ON_ERROR));
            case 'create':
                $config = [];
                foreach (array_slice($arguments, 3) as $pair) {
                    [$key, $value] = explode('=', $pair, 2);
                    $config[$key] = $value;
                }
                $this->networks[$name($arguments[2])] = ['config' => $config];

                return Process::result();
            case 'delete':
                unset($this->networks[$name($arguments[2])]);

                return Process::result();
            default:
                return Process::result();
        }
    }

    private function execAll(string $input): ProcessResult
    {
        $payload = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        $results = [];
        foreach ($payload['requests'] as $request) {
            $instance = substr($request['instance'], strpos($request['instance'], ':') + 1);
            $result = $this->guest($instance, $request['argv'], $request['stdin']);
            $results[] = ['label' => $request['label'], 'stdout' => $result->output(), 'stderr' => $result->errorOutput(), 'exit_code' => $result->exitCode()];
        }

        return Process::result(json_encode($results, JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $argv */
    private function guest(string $instance, array $argv, ?string $stdin): ProcessResult
    {
        $this->execs[] = ['instance' => $instance, 'argv' => $argv, 'stdin' => $stdin];
        if (! isset($this->instances[$instance]) || $this->instances[$instance]['status'] !== 'Running') {
            return Process::result('', 'Error: Instance is not running', 1);
        }
        $answer = $this->responder === null ? null : ($this->responder)($instance, $argv, $stdin);

        if ($answer === null && $argv === ['dpkg', '--print-architecture']) {
            return Process::result("amd64\n");
        }
        if ($answer === null && ($argv[2] ?? null) === 'recipe.install-javascript') {
            return Process::result('{"ok":true,"data":{"changed":false,"retired":[]}}');
        }

        return $answer === null ? Process::result() : Process::result($answer[0], $answer[1], $answer[2]);
    }

    /** @param list<string> $names */
    private function json(array $names): ProcessResult
    {
        $resources = [];
        foreach ($names as $instance) {
            $state = $this->instances[$instance];
            $resources[] = [
                'name' => $instance,
                'type' => $state['type'],
                'status' => $state['status'],
                'status_code' => $state['status'] === 'Running' ? 103 : 102,
                'config' => $state['config'],
                'devices' => $state['devices'],
                'expanded_devices' => $state['devices'],
            ];
        }

        return Process::result(json_encode($resources, JSON_THROW_ON_ERROR));
    }
}
