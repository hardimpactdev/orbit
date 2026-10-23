<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\AgentCommands\AgentCommandRefused;

/** Agents for role publication tests that record each Node step as an event. */
final class RoleAgents
{
    /** @param  list<string|array{event: string}>  $events  With `$tagged`, each event is `['event' => ...]`. */
    public static function recording(array &$events, bool $failCertificate = false, bool $failHealth = false, bool $tagged = false): FakeAgentCommandRunner
    {
        $step = static function (string $event, ?string $failure = null) use (&$events, $tagged): \Closure {
            return static function (array $input) use (&$events, $event, $failure, $tagged): array {
                $events[] = $tagged ? ['event' => $event] : $event;
                if ($failure !== null) {
                    [$command, $code] = explode(' ', $failure);
                    throw new AgentCommandRefused($command, $code, 'Injected failure.', $code === 'caddy.certificate_failed' ? ['reason' => 'injected'] : []);
                }

                return match ($event) {
                    'ssh:caddy-source' => ['changed' => false, 'version' => '2.11.7'],
                    'ssh:certificate' => ['changed' => true],
                    default => [],
                };
            };
        };

        return new FakeAgentCommandRunner()
            ->answer('caddy.install-package', $step('ssh:caddy-source'))
            ->answer('http.await-healthy', $step('ssh:health', $failHealth ? 'http.await-healthy http.unhealthy' : null))
            ->answer('caddy.install-certificate', $step('ssh:certificate', $failCertificate ? 'caddy.install-certificate caddy.certificate_failed' : null))
            ->answer('caddy.remove-certificate', $step('ssh:certificate-remove', $failCertificate ? 'caddy.remove-certificate caddy.certificate_failed' : null));
    }
}
