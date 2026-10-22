<?php

declare(strict_types=1);

use App\Services\SelfUpdate\ReleaseLocation;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Saloon\Http\Faking\MockResponse;

/*
 * Stand-ins for `orbit self-update` tests. The release bytes under tests/Fixtures/SelfUpdate are the ones whose
 * checksums the recorded Gateway fixtures name; see apps/gateway/tests/Fixtures/GitHub/CliRelease/SOURCE.md.
 */

const SELF_UPDATE_OLD_BINARY = "#!/bin/sh\necho 'Orbit 0.4600.0'\n";

const SELF_UPDATE_AGENT_BYTES = "orbit-agent 0.5.0 fixture binary\n";

/** The delivery commit of the recorded Gateway fixtures; agent releases are tagged `agent-v<commit>`. */
const SELF_UPDATE_AGENT_COMMIT = '0123456789abcdef0123456789abcdef01234567';

function self_update_release_bytes(string $name): string
{
    return (string) file_get_contents(base_path('tests/Fixtures/SelfUpdate/'.$name));
}

/**
 * The recorded desired state with the agent pin rewritten to checksums of local stand-in bytes, so a test can
 * download an agent that matches the pin.
 */
function self_update_state_with_agent(string $fixture = 'gateway/self-update/available'): MockResponse
{
    $body = json_decode(gateway_fixture($fixture)['body'], true, flags: JSON_THROW_ON_ERROR);

    foreach (array_keys($body['data']['agent']['assets']) as $index) {
        $body['data']['agent']['assets'][$index]['sha256'] = hash('sha256', SELF_UPDATE_AGENT_BYTES);
    }

    return MockResponse::make($body);
}

/** The release `SHA256SUMS` of the stand-in agent, as `bin` release jobs write it. */
function self_update_agent_sums(): string
{
    $digest = hash('sha256', SELF_UPDATE_AGENT_BYTES);

    return "{$digest}  orbit-agent-linux-aarch64\n{$digest}  orbit-agent-linux-x86_64\n";
}

/**
 * Fakes the processes self-update starts: `curl` writes the release asset its URL names, the CLI candidate reports
 * its version, and the agent candidate's `command agent.install` acts as the agent's installer: it copies the
 * candidate over the agent binary and answers with `$agentInstall`, or answers that line without a change when it
 * is a refusal. Every command is recorded in `$this->processes`, and each installer envelope in `$this->envelopes`.
 *
 * @param  array<string, string>  $downloads  Bytes to serve by `<tag>/<asset>` instead of the stand-ins.
 * @param  array<string, mixed>|string|null  $agentInstall  The installer's result line; a string is printed as is.
 */
function fake_self_update_processes(object $test, array $downloads = [], string $reported = 'Orbit 0.4681.0', ?Closure $onDownload = null, array|string|null $agentInstall = null): void
{
    $test->processes = [];
    $test->envelopes = [];
    $agentInstall ??= ['ok' => true, 'data' => ['replaced' => true, 'observer' => 'restarted']];

    Process::fake(static function (PendingProcess $process) use ($test, $downloads, $reported, $onDownload, $agentInstall) {
        $command = (array) $process->command;
        $test->processes[] = $command;

        if ($command[0] === 'curl') {
            $url = (string) end($command);
            $output = $command[array_search('--output', $command, true) + 1];
            $key = basename(dirname($url)).'/'.basename($url);

            if ($onDownload instanceof Closure && ($result = $onDownload($key, $output)) !== null) {
                return $result;
            }

            $bytes = $downloads[$key] ?? match ($key) {
                'agent-v'.SELF_UPDATE_AGENT_COMMIT.'/SHA256SUMS' => self_update_agent_sums(),
                'agent-v'.SELF_UPDATE_AGENT_COMMIT.'/orbit-agent-linux-x86_64' => SELF_UPDATE_AGENT_BYTES,
                default => str_starts_with($url, ReleaseLocation::Default.'/cli-v0.4681.0/') ? self_update_release_bytes(basename($url)) : null,
            };

            if ($bytes === null) {
                return Process::result(exitCode: 22, errorOutput: 'curl: (22) The requested URL returned error: 404');
            }

            file_put_contents($output, $bytes);

            return Process::result();
        }

        if (($command[1] ?? null) === '--version') {
            return Process::result(output: $reported."\n");
        }

        if (($command[1] ?? null) === 'command' && ($command[2] ?? null) === 'agent.install') {
            $envelope = json_decode((string) $process->input, true, flags: JSON_THROW_ON_ERROR);
            $test->envelopes[] = $envelope;
            if (is_array($agentInstall) && ($agentInstall['ok'] ?? false) === true) {
                copy($envelope['input']['candidate'], $test->machine.'/bin/orbit-agent');
                unlink($envelope['input']['candidate']);
            }

            return Process::result(output: is_string($agentInstall) ? $agentInstall : json_encode($agentInstall, JSON_THROW_ON_ERROR)."\n");
        }

        return Process::result(exitCode: 127);
    });
}

/** @return list<string> The commands the fake recorded, such as `curl cli-v0.4681.0/SHA256SUMS`. */
function self_update_commands(object $test): array
{
    return array_map(static fn (array $command): string => match (true) {
        $command[0] === 'curl' => 'curl '.basename(dirname((string) end($command))).'/'.basename((string) end($command)),
        ($command[1] ?? null) === '--version' => 'version',
        ($command[1] ?? null) === 'command' => 'candidate command '.$command[2],
        default => implode(' ', $command),
    }, $test->processes);
}

/**
 * @param  array<string, mixed>  $result
 * @return array<string, mixed>
 */
function self_update_step(array $result, string $step): array
{
    foreach ($result['steps'] as $candidate) {
        if ($candidate['step'] === $step) {
            return $candidate;
        }
    }

    throw new RuntimeException("The result has no {$step} step.");
}
