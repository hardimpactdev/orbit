<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\MountPath;
use App\E2E\Value\TopologyTarget;
use RuntimeException;
use Throwable;

/**
 * Settle the guests of one topology attempt after their first boot, then prepare the mounted worktree on its checkout
 * Nodes: prove the mount, point the worktree's Gateway `.env` at the mounted checkout, link the CLI onto the guest
 * `PATH`, and apply the Gateway migrations. The mount proof alone is what verify and sync re-run before touching a
 * topology.
 */
final readonly class TopologyGuestPreparer
{
    public function __construct(
        private IncusHost $host,
        private int $settleIntervalMicroseconds = 1_000_000,
    ) {}

    /**
     * Waits until every guest has finished its first boot: a copy of the template keeps the base image's cloud-init,
     * which can reboot it once. Before that, it sets each guest's public DNS.
     */
    public function settle(TopologyTarget $target): void
    {
        $guests = array_values($target->instances());
        $probe = 'case "$(cloud-init status 2>/dev/null)" in *done*|*disabled*) ;; *) exit 1 ;; esac; cat /proc/sys/kernel/random/boot_id';
        $publicDns = file_get_contents(dirname(__DIR__, 2).'/resources/guest/public-dns.sh');
        if ($publicDns === false || $publicDns === '') {
            throw new RuntimeException('The topology guest public DNS program is missing.');
        }
        $configured = false;
        $previous = null;
        $deadline = microtime(true) + 900;
        do {
            $commands = [];
            foreach ($guests as $guest) {
                $commands[$guest] = ['instance' => $guest, 'command' => new GuestCommand(['sh', '-c', $probe], 15)];
            }
            try {
                if (! $configured) {
                    $dns = [];
                    foreach ($guests as $guest) {
                        $dns[$guest] = ['instance' => $guest, 'command' => new GuestCommand(['bash', '-ceu', $publicDns], 30)];
                    }
                    $configured = array_all($this->host->execAll($dns), static fn (GuestCommandResult $result): bool => $result->successful());
                    if (! $configured) {
                        throw new RuntimeException('Guest public DNS is not ready.');
                    }
                }
                $boots = array_map(static fn (GuestCommandResult $result): ?string => $result->successful() && trim($result->stdout) !== '' ? trim($result->stdout) : null, $this->host->execAll($commands));
            } catch (RuntimeException) {
                $boots = [];
            }
            if ($boots !== [] && ! in_array(null, $boots, true) && $boots === $previous) {
                return;
            }
            $previous = $boots;
            usleep($this->settleIntervalMicroseconds);
        } while (microtime(true) < $deadline);

        throw new RuntimeException('The topology guests did not finish their first boot.');
    }

    /** Prove the worktree is mounted on every checkout role; nothing is written. */
    public function assertSourceMounted(TopologyTarget $target): void
    {
        $commands = [];
        foreach ($target->recipe->checkoutNodeKeys() as $role) {
            $commands["mountpoint.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => new GuestCommand(['mountpoint', '-q', '--', MountPath::GUEST_SOURCE], 30),
            ];
        }
        $this->assertGuestBatch($this->host->execAll($commands), 'The worktree is not mounted on');
    }

    /**
     * Makes the mounted worktree's Gateway `.env` name the mounted checkout. Host `bin/bootstrap` writes the
     * file from `.env.example`, whose `ORBIT_GATEWAY_CHECKOUT` names a checkout the guest does not have; the
     * script rewrites only that line, so `orbit:bootstrap` and `orbit:gateway-web` validate the mounted
     * checkout. Every other line, the owner, and the mode stay as they were. A missing file fails.
     */
    public const string GATEWAY_ENVIRONMENT_SCRIPT = <<<'SH'
        environment=$1
        checkout=$2
        [ -f "$environment" ] || { echo "The worktree has no $environment; run bin/bootstrap on the host." >&2; exit 66; }
        grep -qx "ORBIT_GATEWAY_CHECKOUT=$checkout" "$environment" && exit 0
        candidate=$(mktemp "$environment.XXXXXX")
        grep -v '^ORBIT_GATEWAY_CHECKOUT=' "$environment" > "$candidate" || true
        printf 'ORBIT_GATEWAY_CHECKOUT=%s\n' "$checkout" >> "$candidate"
        chown --reference="$environment" "$candidate"
        chmod --reference="$environment" "$candidate"
        mv -f -- "$candidate" "$environment"
        SH;

    public function placeGatewayEnvironment(TopologyTarget $target): void
    {
        $environment = $this->host->exec($target->instance('gateway'), new GuestCommand([
            'sh',
            '-ec',
            self::GATEWAY_ENVIRONMENT_SCRIPT,
            'orbit-e2e',
            MountPath::GUEST_SOURCE.'/apps/gateway/.env',
            MountPath::GUEST_SOURCE.'/apps/gateway',
        ], 30));
        if (! $environment->successful()) {
            throw new RuntimeException(
                'The Gateway environment of the mounted worktree could not be prepared: '.trim($environment->stderr),
            );
        }
    }

    /** Expose `orbit` by name for the orbit user on every checkout role. */
    public function exposeOrbitCli(TopologyTarget $target): void
    {
        $commands = [];
        foreach ($target->recipe->checkoutNodeKeys() as $role) {
            $commands["orbit-cli.{$role}"] = [
                'instance' => $target->instance($role),
                'command' => GuestCommand::linkOrbitCli(),
            ];
        }
        $this->assertGuestBatch($this->host->execAll($commands), 'The orbit CLI could not be linked onto the PATH on');
    }

    /** Apply the mounted Gateway's pending migrations without provisioning any product state. */
    public function prepareGatewaySchema(TopologyTarget $target): void
    {
        try {
            $result = $this->host->exec(
                $target->instance('gateway'),
                GuestCommand::asOrbitUser([
                    'env',
                    '-C',
                    MountPath::GUEST_SOURCE.'/apps/gateway',
                    'ORBIT_GATEWAY_CHECKOUT='.MountPath::GUEST_SOURCE.'/apps/gateway',
                    'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite',
                    'php',
                    'artisan',
                    'migrate',
                    '--force',
                    '--no-interaction',
                ], 900),
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Gateway schema preparation could not complete: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $result->successful()) {
            throw new RuntimeException(
                "Gateway schema preparation failed with exit code {$result->exitCode}.",
            );
        }
    }

    /** @param array<string, GuestCommandResult> $results */
    private function assertGuestBatch(array $results, string $message): void
    {
        $failed = [];
        foreach ($results as $label => $result) {
            if (! $result->successful()) {
                $failed[] = $label;
            }
        }
        if ($failed !== []) {
            throw new RuntimeException($message.' '.implode(', ', $failed).'.');
        }
    }
}
