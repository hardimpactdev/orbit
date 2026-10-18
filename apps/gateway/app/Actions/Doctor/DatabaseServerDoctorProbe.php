<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Doctor\DatabaseServerDoctorIssueCode;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;

final readonly class DatabaseServerDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(private ValkeyServerAdmin $admin) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::DatabaseServer;
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $servers = DatabaseServer::query()
            ->where('node_id', $context->node->id)
            ->where('driver', DatabaseDriver::Redis)
            ->whereHas('process', static fn ($query) => $query->where('desired_state', DesiredProcessState::Running))
            ->with(['node', 'databaseConnections.users'])
            ->orderBy('id')
            ->get();
        $issues = [];

        foreach ($servers as $server) {
            try {
                if ($this->admin->acceptsAnonymous($server)) {
                    $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::Open,
                        'A client without a password can run a command.', 'authentication required', 'open');
                }

                if ($this->admin->aclFile($server) !== DatabaseServerProcess::VALKEY_ACL_FILE) {
                    $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::AclFileMissing,
                        'Valkey does not run with Orbit\'s ACL file.', 'managed ACL file', 'missing or different');
                }

                if (ValkeyAcl::changes($server, $this->admin->aclUsers($server)) !== ['set' => [], 'delete' => []]) {
                    $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::AclMismatch,
                        'The live ACL users differ from the recorded users.', 'recorded users', 'different');
                }
            } catch (ResourceOperationException) {
                $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::InspectionFailed,
                    'Database server inspection could not be verified.', 'verifiable', 'unverifiable');
            }
        }

        return DoctorFamilyReportData::fromIssues($this->family(), $servers->count(), $issues);
    }

    private function issue(
        DatabaseServer $server,
        DatabaseServerDoctorIssueCode $code,
        string $summary,
        string $expected,
        string $observed,
    ): DoctorIssueData {
        return new DoctorIssueData(
            $code,
            $code === DatabaseServerDoctorIssueCode::InspectionFailed ? DoctorIssueKind::Unverifiable : DoctorIssueKind::Drift,
            $this->family()->value,
            $server->id,
            $server->slug,
            $summary,
            $expected,
            $observed,
        );
    }
}
