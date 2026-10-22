<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\DatabaseServerAdmin;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\PostgresStatements;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Doctor\DatabaseServerDoctorIssueCode;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class DatabaseServerDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(private ValkeyServerAdmin $admin, private DatabaseServerAdmin $sql) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::DatabaseServer;
    }

    /** @return list<DoctorResources> */
    public function resources(Node $node): array
    {
        return DoctorResources::groups($this->rows($node)->modelKeys(), 1);
    }

    /** @return Collection<int, DatabaseServer> */
    private function rows(Node $node): Collection
    {
        return DatabaseServer::query()
            ->where('node_id', $node->id)
            ->where(static fn ($query) => $query->where('driver', DatabaseDriver::Redis)
                ->orWhere('private_endpoints_managed', true)
                ->orWhere(static fn (Builder $query) => $query->where('driver', DatabaseDriver::Pgsql)->whereHas('databaseConnections.users')))
            ->whereHas('process', static fn ($query) => $query->where('desired_state', DesiredProcessState::Running))
            ->with(['node', 'databaseConnections.users'])
            ->orderBy('id')
            ->get();
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $servers = $this->rows($context->node);
        $servers = $context->select($servers);
        $issues = [];

        foreach ($servers as $server) {
            try {
                if ($server->private_endpoints_managed) {
                    $port = $server->driver->defaultPort() ?? 3306;
                    if (($server->process?->runtime_config['ports'] ?? []) !== DatabaseServerProcess::publishedPorts($server->node, $server->port, $port)) {
                        $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::PublicationMismatch,
                            'The Process publications differ from the managed private endpoints.', 'private endpoints', 'different');
                    }
                }
                if ($server->driver !== DatabaseDriver::Redis) {
                    if ($server->private_endpoints_managed && ! $this->sql->waitUntilReady($server, 5)) {
                        $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::LoginFailed,
                            'The SQL server did not accept its managed admin login.', 'authenticated', 'unavailable');
                    }
                    if ($server->private_endpoints_managed && $server->process !== null && DatabaseServerProcess::holdsRootPassword($server->process)) {
                        $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::InitializationSecretPresent,
                            'The Process still carries its first-start password.', 'removed', 'present');
                    }

                    if ($server->driver === DatabaseDriver::Pgsql) {
                        foreach ($server->databaseConnections as $connection) {
                            foreach ($connection->users as $user) {
                                $attribute = $this->sql->execute($server, new PostgresStatements()->replicationAttribute($user->username));
                                $expected = $user->replication ? 'true' : 'false';
                                if ($attribute !== [$expected]) {
                                    $issues[] = $this->issue($server, DatabaseServerDoctorIssueCode::ReplicationMismatch,
                                        "User [{$user->username}] has a different PostgreSQL replication attribute or is missing.",
                                        $expected, match ($attribute) {
                                            ['true'] => 'true',
                                            ['false'] => 'false',
                                            [] => 'missing',
                                            default => 'unverifiable',
                                        });
                                }
                            }
                        }
                    }

                    continue;
                }
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
