<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseSwampLoad;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\GatewayRelease;
use App\Models\Node;
use App\Support\ValidatedData;
use stdClass;
use Throwable;

/** Pinned native publication, inside the release caller's existing lock. No restart or admission. */
final readonly class NativeGatewayReleaseSwampLoad implements GatewayReleaseSwampLoad
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ProcessRunner $processes,
        private GatewayReleaseGrantProof $grants,
    ) {}

    public function load(string $sha, ?GatewayRelease $record): array
    {
        if (! GatewayReleaseCommit::isSha($sha) || $record === null || ! $record->exists) {
            throw $this->refused();
        }
        $this->assertRecord($record, $sha);
        $id = GatewayReleaseCommit::id($sha);
        $path = $this->layout->releasePath($id).'/swamp/config/gateway-workflow-grants.json';
        $raw = @file_get_contents($path);
        if (! is_string($raw) || $this->layout->preparedCommit($id) !== $sha) {
            throw $this->refused();
        }
        try {
            $policy = ValidatedData::object(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
            $treeResult = $this->processes->run(new ProcessInvocation(
                ['git', '--git-dir='.$this->layout->repositoryPath(), 'rev-parse', $sha.':swamp'], timeout: 10, maxOutputBytes: 1024,
            ));
            $tree = trim($treeResult->stdout);
            if (! $treeResult->succeeded() || $treeResult->truncated || ! GatewayReleaseCommit::isSha($tree)) {
                throw $this->refused();
            }
            $node = Node::query()->find(209);
            if ($node === null || $node->status->value !== 'active' || ! is_string($node->wireguard_ip) || $node->wireguard_ip === ''
                || $node->user === '' || ! is_string($node->ssh_host_key) || $node->ssh_host_key === '') {
                throw $this->refused();
            }
            $connection = new SshConnection($node->wireguard_ip, $node->user, 22, $this->keys->privateKeyPath(), $this->knownHosts->path(), commandTimeout: 620);
            $identity = ['schema' => 'orbit.swamp.release-load.v1', 'release_sha' => $sha, 'release_id' => $id,
                'gateway_release_record' => $record->id, 'target_node_id' => 209, 'attempt_id' => bin2hex(random_bytes(16))];
            $record->forceFill(['phases' => [...$record->phases, 'swamp' => ['outcome' => 'preparing', 'attempt_id' => $identity['attempt_id'], 'sha' => $sha]]])->saveOrFail();
            $prepared = $this->call($connection, [...$identity, 'action' => 'prepare'], verifyDigest: true);
            $this->assertReceipt($prepared, $identity, $tree, hash('sha256', $raw), 'awaiting_gateway_verification');
            $this->assertRecord($record, $sha);
            $record->forceFill(['phases' => [...$record->phases, 'swamp' => [
                'outcome' => 'awaiting_gateway_verification', 'attempt_id' => $identity['attempt_id'], 'receipt' => $prepared,
            ]]])->saveOrFail();
            $tokenProof = $this->grants->verify($policy);
            $accepted = $this->call($connection, [...$identity, 'action' => 'accept', 'receipt_hash' => $prepared['receipt_hash']]);
            $this->assertReceipt($accepted, $identity, $tree, hash('sha256', $raw), 'verified');
            $comparison = $accepted;
            unset($comparison['completed_at']);
            $comparison['state'] = $prepared['state'];
            if ($comparison !== $prepared || ! is_string($accepted['completed_at'] ?? null)) {
                throw $this->refused();
            }
            $this->assertRecord($record, $sha);

            return ['outcome' => 'verified', 'receipt' => $accepted, 'configured_token' => $tokenProof];
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw $this->refused();
        }
    }

    private function assertRecord(GatewayRelease $record, string $sha): void
    {
        $record->refresh();
        $held = $record->outcome === 'paused' && data_get($record->phases, 'hold.record') === $record->id
            && data_get($record->phases, 'hold.outcome') === 'held';
        if (($record->outcome !== GatewayRelease::Running && ! $held) || $record->sha !== $sha
            || $record->release_id !== GatewayReleaseCommit::id($sha)
            || GatewayRelease::query()->where('outcome', GatewayRelease::Running)->whereKeyNot($record->id)->exists()) {
            throw $this->refused();
        }
    }

    /** @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    private function call(SshConnection $connection, array $request, bool $verifyDigest = false): array
    {
        $result = $this->ssh->execute($connection, new RemoteCommand(
            ['sudo', '-n', '/usr/local/libexec/orbit-swamp-release-load.py', '--stdin-json'],
            input: json_encode($request, JSON_THROW_ON_ERROR), timeout: 620, maxOutputBytes: 2 * 1024 * 1024,
        ));
        if (! $result->succeeded() || $result->truncated) {
            throw $this->refused();
        }
        if ($verifyDigest) {
            $document = json_decode($result->stdout, false, 512, JSON_THROW_ON_ERROR);
            if (! $document instanceof stdClass || ! is_string($document->receipt_hash ?? null)) {
                throw $this->refused();
            }
            $hash = $document->receipt_hash;
            unset($document->receipt_hash);
            if (! hash_equals($hash, hash('sha256', json_encode($this->canonical($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)))) {
                throw $this->refused();
            }
        }

        return ValidatedData::object(json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $receipt
     * @param  array<string,mixed>  $identity
     */
    private function assertReceipt(array $receipt, array $identity, string $tree, string $manifest, string $state): void
    {
        foreach ($identity as $key => $value) {
            if (($receipt[$key] ?? null) !== $value) {
                throw $this->refused();
            }
        }
        $runtime = $receipt['runtime_before'] ?? null;
        $audit = $receipt['extension_audit'] ?? null;
        $inventory = $receipt['workflow_inventory'] ?? null;
        if (($receipt['state'] ?? null) !== $state || ($receipt['requested_sha'] ?? null) !== $identity['release_sha']
            || ($receipt['disk_sha'] ?? null) !== $identity['release_sha'] || ($receipt['swamp_tree'] ?? null) !== $tree
            || ! is_array($runtime) || ! is_int($runtime['pid'] ?? null) || $runtime['pid'] < 1 || ! is_string($runtime['invocation'] ?? null) || $runtime['invocation'] === ''
            || ($receipt['runtime_after'] ?? null) !== $runtime || ($receipt['restarts'] ?? null) !== 0 || ($receipt['worker_restarts'] ?? null) !== 0
            || data_get($receipt, 'reload_result.success') !== true || ! is_string($receipt['reload_ack_at'] ?? null)
            || data_get($receipt, 'manifest.source_sha') !== $identity['release_sha'] || data_get($receipt, 'manifest.hash') !== $manifest
            || data_get($receipt, 'manifest.applied') !== true || data_get($receipt, 'health.loopback_status') !== 200 || data_get($receipt, 'health.configured_ca_status') !== 200
            || ! is_array($audit) || ! is_int($audit['checked'] ?? null) || $audit['checked'] < 1 || $audit['matched'] !== $audit['checked'] || ($audit['mismatches'] ?? null) !== []
            || ! is_array($inventory) || ($inventory['equal'] ?? null) !== true || ! is_int($inventory['disk_count'] ?? null) || $inventory['disk_count'] < 1
            || ($inventory['served_count'] ?? null) !== $inventory['disk_count'] || ($inventory['scope'] ?? null) !== 'all_id_name_pairs'
            || ! is_array($receipt['workflows'] ?? null) || ! is_array(data_get($receipt, 'drain.active_running_run_ids'))
            || ! is_string($receipt['receipt_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['receipt_hash']) !== 1) {
            throw $this->refused();
        }
        foreach ($receipt['workflows'] as $workflow) {
            if (! is_array($workflow) || ($workflow['equal'] ?? null) !== true || ! is_string($workflow['disk_hash'] ?? null)
                || ($workflow['served_hash'] ?? null) !== $workflow['disk_hash']) {
                throw $this->refused();
            }
        }
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $fields = get_object_vars($value);
            ksort($fields);

            return (object) array_map($this->canonical(...), $fields);
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($this->canonical(...), $value);
        }

        return $value;
    }

    private function refused(): GatewayReleaseException
    {
        return new GatewayReleaseException('swamp', 'gateway.release_swamp_load_unverified', 'Native publication was not verified for this release. Keep the Gateway paused and inspect the pinned attempt.');
    }
}
