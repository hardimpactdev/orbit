<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\NodeDiskFilesystemData;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use RuntimeException;
use Throwable;

final readonly class NativeNodeStateInspector implements NodeStateInspector
{
    public function __construct(
        private AgentCommandRunner $agents,
        private CommandDeadline $deadline,
        private AgentDelivery $delivery,
    ) {}

    public function inspect(Node $node): NodeInspectionData
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            return new NodeInspectionData(false, null, null, null);
        }
        try {
            $result = $this->agents->run($node, 'doctor.inspect-node', ['user' => $node->user, 'address' => $node->wireguard_ip], $this->deadline->cap(30.0));
        } catch (AgentCommandRefused) {
            throw new DoctorInspectionException;
        } catch (AgentRunnerFailure $exception) {
            if (! in_array($exception->kind, [AgentRunnerFailure::Transport, AgentRunnerFailure::Timeout], true)) {
                throw new DoctorInspectionException;
            }

            return new NodeInspectionData(false, null, null, null);
        } catch (Throwable) {
            return new NodeInspectionData(false, null, null, null);
        }
        if (! in_array($result['platform'] ?? null, ['linux', 'darwin', 'freebsd'], true)
            || ! in_array($result['architecture'] ?? null, ['x86_64', 'aarch64'], true)
            || ! is_bool($result['wireguard'] ?? null)
            || ! is_array($result['disks'] ?? null) || ! array_is_list($result['disks'])) {
            throw new DoctorInspectionException;
        }
        foreach (['agent_binary', 'agent_unit', 'agent_active'] as $key) {
            if (! array_key_exists($key, $result) || ($result[$key] !== null && ! is_bool($result[$key]))) {
                throw new DoctorInspectionException;
            }
        }
        foreach (['agent_checksum', 'secret_checksum'] as $key) {
            if (! array_key_exists($key, $result) || ($result[$key] !== null && (! is_string($result[$key]) || preg_match('/\A[a-f0-9]{64}\z/', $result[$key]) !== 1))) {
                throw new DoctorInspectionException;
            }
        }
        $disks = [];
        foreach ($result['disks'] as $disk) {
            if (! is_array($disk) || ! in_array($disk['location'] ?? null, ['root', 'home'], true)
                || ! is_int($disk['available_kib'] ?? null) || ! is_int($disk['size_kib'] ?? null)
                || ! array_key_exists('free_inodes', $disk) || ! array_key_exists('total_inodes', $disk)
                || ($disk['free_inodes'] !== null && ! is_int($disk['free_inodes']))
                || ($disk['total_inodes'] !== null && ! is_int($disk['total_inodes']))) {
                throw new DoctorInspectionException;
            }
            $disks[] = new NodeDiskFilesystemData($disk['location'], $disk['available_kib'], $disk['size_kib'], $disk['free_inodes'], $disk['total_inodes']);
        }
        try {
            $pin = $result['platform'] === 'linux' ? $this->delivery->pin($node) : null;
        } catch (RuntimeException) {
            $pin = null;
        }

        return new NodeInspectionData(
            true, $result['platform'], $result['architecture'], $result['wireguard'],
            $result['agent_binary'], $result['agent_unit'], $result['agent_active'],
            $pin === null ? null : hash_equals($pin, $result['agent_checksum'] ?? ''),
            $result['secret_checksum'], $disks,
        );
    }
}
