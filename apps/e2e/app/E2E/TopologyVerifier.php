<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use InvalidArgumentException;
use JsonException;

/**
 * Runs the readiness probes of the Nodes a topology has, retrying failed probes until the readiness window
 * ends. Each probe runs `verify-topology.sh` on one Node and must print passing evidence for the source.
 */
final readonly class TopologyVerifier
{
    private const int PROBE_SECONDS = 120;

    public function __construct(
        private IncusHost $host,
        private int $readinessTimeoutSeconds = 600,
        private int $readinessPollIntervalMicroseconds = 2_000_000,
    ) {}

    /**
     * The probes of a topology, keyed by name, with the Node each runs on and its extra arguments.
     *
     * @return array<string, array{node:string,arguments:list<string>}>
     */
    public static function probes(TopologyTarget $target, SourceState $source): array
    {
        $recipe = $target->recipe;
        $probes = [
            'nodes.registered' => [
                'node' => 'gateway',
                'arguments' => [base64_encode(json_encode($recipe->assignments(), JSON_THROW_ON_ERROR))],
            ],
            'service.gateway' => ['node' => 'gateway', 'arguments' => []],
            'service.vpn' => ['node' => 'gateway', 'arguments' => []],
            'wireguard.reachability' => [
                'node' => 'gateway',
                'arguments' => array_values(array_filter($recipe->nodeKeys(), static fn (string $node): bool => $node !== 'gateway')),
            ],
        ];
        foreach ($recipe->workloadNodes() as $node) {
            $probes['caddy.'.$node->key] = ['node' => $node->key, 'arguments' => []];
            if (in_array('app-prod', $node->roles, true)) {
                $probes['php-fpm.'.$node->key] = ['node' => $node->key, 'arguments' => []];
            }
        }
        $probes['operator.gateway'] = ['node' => 'operator', 'arguments' => []];
        if ($source->mounted) {
            foreach ($recipe->checkoutNodeKeys() as $node) {
                $probes['source.'.$node] = ['node' => $node, 'arguments' => [(string) $source->mountTokenHash]];
            }
        }

        return $probes;
    }

    public function verify(TopologyTarget $target, SourceState $source): VerificationReport
    {
        $this->host->assertTopologyNetworkIdentity($target, requireRunning: true);
        $probes = self::probes($target, $source);

        $results = [];
        foreach ($probes as $name => $probe) {
            $results[$name] = $this->failedProbe(
                $name,
                $target->instance($probe['node']),
                'passing evidence for source '.$source->guestSha,
                'no evidence received',
            );
        }
        $pending = $probes;
        $lastNonTimeoutFailures = [];
        $deadline = microtime(true) + $this->readinessTimeoutSeconds;

        do {
            $remainingSeconds = max(1, (int) ceil($deadline - microtime(true)));
            $commands = [];
            foreach ($pending as $name => $probe) {
                $instance = $target->instance($probe['node']);
                $commands[$name] = [
                    'instance' => $instance,
                    'command' => new GuestCommand(
                        ['/usr/local/bin/verify-topology.sh', $name, $source->guestSha, $instance, ...$probe['arguments']],
                        min(self::PROBE_SECONDS, $remainingSeconds),
                    ),
                ];
            }

            $probeResults = $this->host->execAll($commands);
            foreach ($pending as $name => $probe) {
                $result = $probeResults[$name] ?? null;
                $instance = $target->instance($probe['node']);
                if (! $result instanceof GuestCommandResult) {
                    $results[$name] = $this->failedProbe($name, $instance, 'passing evidence for source '.$source->guestSha, 'missing batch result');

                    continue;
                }
                $evidence = $this->evidence($name, $result, $source->guestSha, $instance);
                if ($evidence !== null) {
                    $results[$name] = $evidence;
                    unset($pending[$name]);

                    continue;
                }
                $observed = (new GuestFailureDetail)->append(
                    $result->successful() ? 'malformed evidence' : 'guest exit '.$result->exitCode,
                    $result->stderr,
                );
                if ($result->exitCode !== 124) {
                    $lastNonTimeoutFailures[$name] = $observed;
                } elseif (isset($lastNonTimeoutFailures[$name])) {
                    $observed .= '; last non-timeout failure: '.$lastNonTimeoutFailures[$name];
                }
                $results[$name] = $this->failedProbe($name, $instance, 'passing evidence for source '.$source->guestSha, $observed);
            }

            if ($pending === [] || microtime(true) >= $deadline) {
                break;
            }

            if ($this->readinessPollIntervalMicroseconds > 0) {
                usleep($this->readinessPollIntervalMicroseconds);
            }
        } while (microtime(true) < $deadline);

        $passed = ! array_filter($results, static fn (array $probe): bool => ! $probe['passed']);

        return new VerificationReport($passed, $results);
    }

    /** @return array{passed:bool,checked_at:string,expected:string,observed:string,evidence_ref:string}|null */
    private function evidence(string $name, GuestCommandResult $result, string $identity, string $instance): ?array
    {
        try {
            if (! $result->successful()) {
                return null;
            }

            $evidence = json_decode(trim($result->stdout), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_array($evidence)
            || array_keys($evidence) !== ['probe', 'passed', 'identity', 'checked_at', 'expected', 'observed', 'evidence_ref']
            || $evidence['probe'] !== $name
            || $evidence['passed'] !== true
            || ! is_string($evidence['checked_at'])
            || ! is_string($evidence['expected'])
            || ! is_string($evidence['observed'])
            || $evidence['identity'] !== $identity
            || $evidence['evidence_ref'] !== "incus://{$instance}/{$name}"
        ) {
            return null;
        }

        $probe = [
            'passed' => true,
            'checked_at' => $evidence['checked_at'],
            'expected' => $evidence['expected'],
            'observed' => $evidence['observed'],
            'evidence_ref' => $evidence['evidence_ref'],
        ];

        try {
            new VerificationReport(true, [$name => $probe]);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $probe;
    }

    /** @return array{passed:false,checked_at:string,expected:string,observed:string,evidence_ref:string} */
    private function failedProbe(string $name, string $instance, string $expected, string $observed): array
    {
        return [
            'passed' => false,
            'checked_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
            'expected' => $expected,
            'observed' => $observed,
            'evidence_ref' => "incus://{$instance}/{$name}",
        ];
    }
}
