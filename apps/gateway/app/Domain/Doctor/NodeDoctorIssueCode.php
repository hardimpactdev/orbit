<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum NodeDoctorIssueCode: string implements DoctorIssueCode
{
    case LifecycleNotActive = 'node.lifecycle_not_active';
    case DiskLow = 'node.disk_low';
    case ClockUnsynchronized = 'node.clock_unsynchronized';
    case InstanceBridgeUnhealthy = 'node.instance_bridge_unhealthy';
    case SshUnreachable = 'node.ssh_unreachable';
    case PlatformMismatch = 'node.platform_mismatch';
    case ArchitectureMismatch = 'node.architecture_mismatch';
    case PrivateDnsUplinkMissing = 'node.private_dns_uplink_missing';
    case PrivateDnsPolicyMismatch = 'node.private_dns_policy_mismatch';
    case PrivateDnsUnreachable = 'node.private_dns_unreachable';
    case PublicDnsUnreachable = 'node.public_dns_unreachable';
    case WireGuardAddressMismatch = 'node.wireguard_ip_mismatch';
    case AgentMissing = 'node.agent_missing';
    case AgentBinaryMismatch = 'node.agent_binary_mismatch';
    case WorkerFailed = 'node.worker_failed';
    case AgentInactive = 'node.agent_inactive';
    case AgentViewStale = 'node.agent_view_stale';
    case AgentSecretMismatch = 'node.agent_secret_mismatch';
    case ReleaseLag = 'node.release_lag';
    case RebootDue = 'node.reboot_due';
    case IdRangesLow = 'node.id_ranges_low';
    case RouteResidueRetained = 'node.route_residue_retained';
    case CliForeign = 'node.cli_foreign';
    case InspectionFailed = 'node.inspection_failed';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Node;
    }
}
