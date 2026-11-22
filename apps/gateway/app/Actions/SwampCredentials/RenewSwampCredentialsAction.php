<?php

declare(strict_types=1);

namespace App\Actions\SwampCredentials;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SwampCredentials\SwampGatewayCredential;
use App\Domain\SwampCredentials\SwampGatewayToken;
use App\Domain\Tasks\SwampReaderAssistance;
use App\Domain\Tasks\SwampReaderCredential;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\SwampCredentials\GatewayProcessStarts;
use App\Infrastructure\SwampCredentials\SwampCredentialConfiguration;
use App\Infrastructure\SwampCredentials\SwampHostTokenInstaller;
use App\Infrastructure\SwampCredentials\SwampNodeRenewalNotInstalledException;
use App\Infrastructure\SwampCredentials\SwampNodeTokens;
use App\Infrastructure\SwampCredentials\SwampTokenReadCheck;
use App\Infrastructure\Tasks\SwampReaderEnvironment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Renews the Gateway's Swamp read and run tokens within ten days of expiry, from live facts only. The generation in use is the name
 * the shared env holds; a predecessor is another active token of its family and principal that expires earlier. Each run mints or,
 * after a lost reply, reveals again today's generation, verifies a read, backs up and atomically rewrites the env, and caches the
 * configuration while it still holds an older name. A predecessor is revoked once the serving configuration holds the installed name
 * and every long-running Gateway process started after the env changed. A rerun after a crash at any step finds the same facts.
 */
final readonly class RenewSwampCredentialsAction
{
    /** Native valid states reported by Swamp for access and enrollment tokens. */
    private const array AccessValidStates = ['active'];

    private const array EnrollmentValidStates = ['enrolled', 'active'];

    /** The action a release reports when the Swamp Node's renewal unit is not installed yet. */
    public const string NodeRenewalSkipped = 'skipped the Swamp Node token renewal';

    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
        private SwampReaderEnvironment $environment,
        private SwampNodeTokens $node,
        private SwampTokenReadCheck $reads,
        private SwampReaderAssistance $assistance,
        private RaiseSwampTokenAssistanceAction $raise,
        private SwampCredentialConfiguration $configuration,
        private GatewayProcessStarts $processes,
        private SwampHostTokenInstaller $hostInstaller,
    ) {}

    /**
     * @param  bool  $release  A release run: renew the Swamp Node's tokens first, cache this candidate release, and require that no
     *                         live consumer holds a token that expires within three days. Never revokes during release preflight.
     * @return list<string> What this run did, by token name.
     */
    public function execute(bool $release = false): array
    {
        $access = null;
        try {
            $skipped = [];
            if ($release) {
                try {
                    $this->node->renewNode();
                } catch (SwampNodeRenewalNotInstalledException $exception) {
                    // The swamp role converge that installs the unit runs later in this release; the live-token gate still applies.
                    Log::warning(SwampNodeRenewalNotInstalledException::Code, ['message' => $exception->getMessage()]);
                    $skipped = [self::NodeRenewalSkipped];
                }
            }
            $access = $this->access($this->node->list());
            $actions = [];
            $failure = null;
            try {
                [$actions, $failure] = $this->install($access, $release);
                $actions = [...$skipped, ...$actions];
            } catch (Throwable $exception) {
                $failure = $exception;
            }
            if ($this->stale() !== [] && ! ($failure instanceof GatewayReleaseException && $failure->errorCode === 'gateway.release_in_progress')) {
                // Apply configuration for any credential already published, even when the other kind failed.
                try {
                    $release ? $this->configuration->candidate() : $this->configuration->serving();
                    $actions[] = 'cached the configuration';
                } catch (Throwable $configException) {
                    $failure ??= $configException;
                }
            }
            if ($failure !== null) {
                throw $failure;
            }
            $inventory = $this->node->list();
            $access = $this->access($inventory);
            if ($release) {
                $this->requireLiveTokens($inventory);

                return $actions;
            }
            $this->raiseNearExpiry($access);

            return [...$actions, ...$this->revokePredecessors($inventory)];
        } catch (Throwable $exception) {
            if (! $release && ! ($exception instanceof GatewayReleaseException && $exception->errorCode === 'gateway.release_in_progress')) {
                // Renewal, SSH inventory or configure failed: an installed token expiring within three days needs the operator.
                $this->raiseNearExpiry($access);
            }
            throw $exception;
        }
    }

    /**
     * Mints or resumes, verifies and installs each due token, under the release lock.
     *
     * @param  array<string, array{name:string,principal:string|null,expiresAt:string|null,state:string}>  $access
     * @return array{0: list<string>, 1: ?Throwable}
     */
    private function install(array $access, bool $release): array
    {
        return $this->lock->run(function () use ($access, $release): array {
            $actions = [];
            $failure = null;
            foreach (SwampGatewayToken::cases() as $kind) {
                try {
                    $values = $this->environment->values($this->environment->contents());
                    if (! $this->due($kind, $values, $access)) {
                        continue;
                    }
                    if ($this->layout->usesContainers()) {
                        if ($release) {
                            // A release already ran --mint-for-host and the host installer before --release.
                            throw new ResourceOperationException('gateway.swamp_credentials_container',
                                "{$kind->base()} is still due after the host installation; check the release phase output.", 409);
                        }
                        $credential = $this->mint($kind, $values);
                        $this->hostInstaller->install($kind, $credential);
                        $this->assistance->clear($values[$kind->key()] ?? '');
                        $actions[] = 'installed '.$credential->name;

                        continue;
                    }
                    $credential = $this->mint($kind, $values);
                    [$tokenKey, $nameKey, $expiryKey] = $kind->keys();
                    $this->environment->replace($this->environment->contents(), [$tokenKey => $credential->token(), $nameKey => $credential->name,
                        $expiryKey => $credential->expiresAt->format('Y-m-d\TH:i:s.u\Z')]);
                    $this->assistance->clear($values[$tokenKey] ?? '');
                    $actions[] = 'installed '.$credential->name;
                } catch (Throwable $exception) {
                    $failure ??= $exception;
                }
            }

            return [$actions, $failure];
        });
    }

    /**
     * In a release container, the candidate mints (or resumes) and verifies a due token for its host, which installs it into the
     * shared env and publishes it to the guest. The reveal goes to stdout, a private pipe to that installer; null when not due.
     */
    public function mintForHost(SwampGatewayToken $kind): ?string
    {
        $access = $this->access($this->node->list());
        $values = $this->environment->values($this->environment->contents());
        if (! $this->due($kind, $values, $access)) {
            return null;
        }
        $credential = $this->mint($kind, $values);

        return json_encode(['name' => $credential->name, 'token' => $credential->token(),
            'expiresAt' => $credential->expiresAt->format('Y-m-d\TH:i:s.u\Z')], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, string|null>  $values
     * @param  array<string, array{name:string,principal:string|null,expiresAt:string|null,state:string}>  $access
     */
    private function due(SwampGatewayToken $kind, array $values, array $access): bool
    {
        $row = $access[SwampGatewayToken::held($values[$kind->key()] ?? '')] ?? null;

        return $row === null || $row['state'] !== 'active' || $this->within($row, SwampGatewayToken::RenewDays);
    }

    /** @param array<string, string|null> $values */
    private function mint(SwampGatewayToken $kind, array $values): SwampGatewayCredential
    {
        $credential = SwampGatewayCredential::fromReveal($kind, $this->node->mint($kind));
        try {
            $this->reads->check($credential->token(), $values);
        } catch (Throwable) {
            throw new RuntimeException("The new {$credential->name} could not read Swamp. The shared env was not changed.");
        }

        return $credential;
    }

    /**
     * Revokes a predecessor of each Gateway token once nothing can hold it: the serving configuration holds the installed name and
     * every long-running Gateway process started after the env last changed. Neither a release id nor a reconcile counts.
     *
     * @param  array{access: list<array{name:string,principal:string|null,expiresAt:string|null,state:string}>}  $inventory
     * @return list<string>
     */
    public function revokePredecessors(array $inventory): array
    {
        $access = $this->access($inventory);
        $values = $this->environment->values($this->environment->contents());
        $revoked = [];
        foreach (SwampGatewayToken::cases() as $kind) {
            $held = SwampGatewayToken::held($values[$kind->key()] ?? '');
            $current = $access[$held] ?? null;
            if ($current === null || $current['expiresAt'] === null) {
                continue;
            }
            $predecessors = array_filter($access, static fn (array $row): bool => $row['name'] !== $held && $row['state'] === 'active'
                && SwampGatewayToken::family($row['name']) === $kind->base() && $row['principal'] === $kind->principal()
                && $row['expiresAt'] !== null && CarbonImmutable::parse($row['expiresAt'])->lessThan(CarbonImmutable::parse($current['expiresAt'])));
            if ($predecessors === [] || in_array($kind, $this->stale(), true) || ! $this->processes->allStartedAfter($this->changedAt())) {
                continue;
            }
            foreach ($predecessors as $row) {
                $this->node->revoke($row['name']);
                $revoked[] = 'revoked '.$row['name'];
            }
        }

        return $revoked;
    }

    /** @return list<SwampGatewayToken> The tokens whose name in this process's cached configuration differs from the env's. */
    private function stale(): array
    {
        $values = $this->environment->values($this->environment->contents());

        return array_values(array_filter(SwampGatewayToken::cases(), static fn (SwampGatewayToken $kind): bool => SwampGatewayToken::held(config()->string('swamp.'.$kind->value.'_token')) !== SwampGatewayToken::held($values[$kind->key()] ?? '')));
    }

    private function changedAt(): int
    {
        $path = $this->layout->environmentPath();
        clearstatcache(true, $path);

        return (int) filemtime($path);
    }

    /**
     * The three-day gate checks the generation each live consumer holds: the serve-driver file, each worker slot's server.token and
     * enrollment.token, and both Gateway env values. A held name that serve does not list, or a missing slot, fails closed.
     *
     * @param  array{access: list<array{name:string,principal:string|null,expiresAt:string|null,state:string}>, enrollment: list<array{name:string,expiresAt:string|null,state:string}>, held: array{driver:string, workers: list<array{slot:string,access:string,enrollment:string}>}}  $inventory
     */
    private function requireLiveTokens(array $inventory): void
    {
        $access = $this->access($inventory);
        $enrollment = array_column($inventory['enrollment'], null, 'name');
        $values = $this->environment->values($this->environment->contents());
        $held = ['serve-driver' => [$inventory['held']['driver'], $access, self::AccessValidStates]];
        foreach (SwampGatewayToken::cases() as $kind) {
            $held[$kind->base()] = [SwampGatewayToken::held($values[$kind->key()] ?? ''), $access, self::AccessValidStates];
        }
        $slots = array_column($inventory['held']['workers'], null, 'slot');
        foreach (['a', 'b', 'c'] as $slot) {
            $held["slot {$slot} server.token"] = [$slots[$slot]['access'] ?? '', $access, self::AccessValidStates];
            $held["slot {$slot} enrollment.token"] = [$slots[$slot]['enrollment'] ?? '', $enrollment, self::EnrollmentValidStates];
        }
        $expiring = [];
        foreach ($held as $consumer => [$name, $rows, $validStates]) {
            $validName = $name !== '' && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/', $name) === 1;
            $row = $validName ? ($rows[$name] ?? null) : null;
            if ($row === null) {
                // Never echo an unlisted or malformed value: it could be an undelimited secret from a corrupted credential file.
                $expiring[] = "{$consumer} (not listed or malformed)";
            } elseif (! in_array($row['state'], $validStates, true) || $this->within($row, SwampGatewayToken::ReleaseDays)) {
                $expiring[] = "{$consumer} ({$name})";
            }
        }
        if ($expiring !== []) {
            throw new ResourceOperationException('gateway.release_swamp_token_expiring',
                'Live Swamp tokens still expire within three days, or are not listed, after renewal: '.implode(', ', $expiring).'. Run orbit-swamp-token-renewal on the Swamp Node and check its journal.', 409);
        }
    }

    /** @param array<string, array{name:string,principal:string|null,expiresAt:string|null,state:string}>|null $access */
    private function raiseNearExpiry(?array $access): void
    {
        $values = $this->environment->values($this->environment->contents());
        foreach (SwampGatewayToken::cases() as $kind) {
            $token = $values[$kind->key()] ?? '';
            if ($token === '') {
                continue;
            }
            $recorded = $values[$kind->key().'_EXPIRES_AT'] ?? null;
            $recordedExpiry = is_string($recorded) ? SwampReaderCredential::expiry($recorded) : null;
            if ($access !== null) {
                $row = $access[SwampGatewayToken::held($token)] ?? null;
                if ($row !== null) {
                    if ($this->within($row, SwampGatewayToken::ReleaseDays)) {
                        $this->raise->execute($kind, $token, $row['expiresAt'] === null ? null : CarbonImmutable::parse($row['expiresAt']));
                    }

                    continue;
                }
                // A generation just installed in this run is absent from the pre-install snapshot; trust its recorded expiry when fresh.
                if ($recordedExpiry !== null && $recordedExpiry->greaterThan(now()->addDays(SwampGatewayToken::ReleaseDays))) {
                    continue;
                }
                $this->raise->execute($kind, $token, $recordedExpiry);

                continue;
            }
            if ($recordedExpiry !== null && $recordedExpiry->lessThanOrEqualTo(now()->addDays(SwampGatewayToken::ReleaseDays))) {
                $this->raise->execute($kind, $token, $recordedExpiry);
            }
        }
    }

    /** @param array{expiresAt:string|null} $row */
    private function within(array $row, int $days): bool
    {
        return $row['expiresAt'] !== null && CarbonImmutable::parse($row['expiresAt'])->lessThanOrEqualTo(now()->addDays($days));
    }

    /**
     * @param  array{access: list<array{name:string,principal?:string|null,expiresAt:string|null,state:string}>}  $inventory
     * @return array<string, array{name:string,principal:string|null,expiresAt:string|null,state:string}>
     */
    private function access(array $inventory): array
    {
        $rows = [];
        foreach ($inventory['access'] as $row) {
            $rows[$row['name']] = ['principal' => null, ...$row];
        }

        return $rows;
    }
}
