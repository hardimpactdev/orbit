<?php

declare(strict_types=1);

namespace App\Actions\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Domain\Doctor\DatabaseConnectionDoctorInspection;
use App\Domain\Doctor\DatabaseConnectionDoctorIssueCode;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorFamilyProbe;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\DoctorResourceKind;
use App\Domain\Doctor\DoctorResources;
use App\Domain\Instances\InstanceState;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;

final readonly class DatabaseConnectionDoctorProbe implements DoctorFamilyProbe
{
    public function __construct(
        private DatabaseConnectionDoctorInspection $inspection,
    ) {}

    public function family(): DoctorFamily
    {
        return DoctorFamily::DatabaseConnection;
    }

    /** @return list<DoctorResources> */
    public function resources(Node $node): array
    {
        $attachments = $this->attachmentsOn($node);
        $connections = $this->unattachedNodeConnections($node, $attachments);

        return [
            ...DoctorResources::groups($attachments->modelKeys(), kind: DoctorResourceKind::Attachments),
            ...DoctorResources::groups($connections->modelKeys(), kind: DoctorResourceKind::Connections),
        ];
    }

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData
    {
        $attachments = $this->attachmentsOn($context->node);
        $unattached = $this->unattachedNodeConnections($context->node, $attachments);
        $attachments = $context->select($attachments, DoctorResourceKind::Attachments);
        $unattached = $context->select($unattached, DoctorResourceKind::Connections);
        $checked = $attachments->count() + $unattached->count();

        if ($checked === 0) {
            return DoctorFamilyReportData::fromIssues(DoctorFamily::DatabaseConnection, 0, []);
        }

        $issues = [];
        $attachmentIssues = [];
        $observedResources = [];
        $observedChecks = [];

        foreach ($attachments as $attachment) {
            $found = $this->inspection->attachment($attachment);
            if ($found === []) {
                $observedChecks[$attachment->id] = [DatabaseConnectionDoctorIssueCode::Missing->value, DatabaseConnectionDoctorIssueCode::EnvMismatch->value];
                $collision = DatabaseConnection::query()->find($attachment->id);
                if (! $collision instanceof DatabaseConnection || $this->inspection->connection($collision) === null) {
                    $observedResources[] = $attachment->id;
                }
            }
            if ($found !== []) {
                $attachmentIssues[$attachment->instance_id] = [
                    ...($attachmentIssues[$attachment->instance_id] ?? []),
                    ...$found,
                ];
            }
        }

        if ($attachmentIssues !== []) {
            $instances = Instance::query()
                ->whereKey(array_keys($attachmentIssues))
                ->get()
                ->keyBy('id');

            foreach ($attachmentIssues as $instanceId => $found) {
                $instance = $instances->get($instanceId);
                if (! $instance instanceof Instance || $instance->status === InstanceState::Removing) {
                    continue;
                }

                $issues = [...$issues, ...$found];
            }
        }

        foreach ($unattached as $connection) {
            $issue = $this->inspection->connection($connection);
            if ($issue === null) {
                $collision = DatabaseConnectionTarget::query()->find($connection->id);
                if (! $collision instanceof DatabaseConnectionTarget || $this->inspection->attachment($collision) === []) {
                    $observedResources[] = $connection->id;
                }
            }

            if ($issue instanceof DoctorIssueData) {
                $issues[] = $issue;
            }
        }

        return DoctorFamilyReportData::fromIssues(DoctorFamily::DatabaseConnection, $checked, $issues, $observedResources, $observedChecks);
    }

    /** @return Collection<int, DatabaseConnectionTarget> */
    private function attachmentsOn(Node $node): Collection
    {
        return DatabaseConnectionTarget::query()
            ->with(['databaseConnection', 'instance'])
            ->whereIn(
                'instance_id',
                Instance::query()
                    ->select('id')
                    ->where('node_id', $node->id)
                    ->where('status', '!=', InstanceState::Removing),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, DatabaseConnectionTarget>  $attachments
     * @return Collection<int, DatabaseConnection>
     */
    private function unattachedNodeConnections(Node $node, Collection $attachments): Collection
    {
        $attachedIds = $attachments
            ->pluck('database_connection_id')
            ->filter(static fn (mixed $id): bool => is_int($id))
            ->all();

        return DatabaseConnection::query()
            ->where('node_id', $node->id)
            ->when(
                $attachedIds !== [],
                static fn ($query) => $query->whereNotIn('id', $attachedIds),
            )
            ->orderBy('id')
            ->get();
    }
}
