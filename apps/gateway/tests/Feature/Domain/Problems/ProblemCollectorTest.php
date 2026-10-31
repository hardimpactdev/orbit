<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorFamilyReportData;
use App\Data\Doctor\DoctorIssueData;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Domain\Doctor\DoctorFamily;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Problems\ProblemCollector;
use App\Domain\Problems\ProblemEvidence;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Activity;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TestOrbitHome;

it('cuts a doctor summary and an assistance reason to 1000 characters', function (): void {
    $long = str_repeat('é', 1001);
    $stored = app(ProblemEvidence::class)->apply([], [
        'summary' => $long,
        'assistance_reason' => $long,
        'error_message' => $long,
    ]);

    expect(mb_strlen($stored['summary']))->toBe(1000)
        ->and($stored['summary'])->toBe(str_repeat('é', 1000))
        ->and(mb_strlen($stored['assistance_reason']))->toBe(1000)
        ->and($stored['assistance_reason'])->toBe(str_repeat('é', 1000))
        ->and($stored['error_message'])->toBe($long);
});

function problem_collector_sandbox(): void
{
    $path = TestOrbitHome::scratch('problem-collector');
    mkdir($path.'/logs', 0700, true);
    app()->useStoragePath($path);
    app(TaskExtensionState::class)->enable();
}

function problem_collector_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.random_int(10, 200),
        'user' => 'orbit',
    ]);
}

function problem_activity(string $command, ?string $errorCode, ?int $exitCode, ?string $requestId = null): Activity
{
    return Activity::query()->create([
        'log_name' => 'commands',
        'description' => $command,
        'request_id' => $requestId ?? (string) Str::uuid(),
        'command' => $command,
        'status' => 'failed',
        'exit_code' => $exitCode,
        'error_code' => $errorCode,
        'properties' => [
            'path' => '/resources/'.$command,
            'error_message' => 'token=supersecretvalue',
        ],
    ]);
}

function problem_activity_at(
    string $command,
    ?string $errorCode,
    ?int $exitCode,
    Carbon $createdAt,
    ?string $requestId = null,
): Activity {
    $activity = problem_activity($command, $errorCode, $exitCode, $requestId);
    $activity->forceFill(['created_at' => $createdAt])->save();

    return $activity;
}

it('resolves only the successfully observed Doctor resource within a mixed family', function (): void {
    problem_collector_sandbox();
    $node = problem_collector_node('mixed');
    $rows = [];
    foreach ([101, 102, 103] as $id) {
        $rows[$id] = ProblemFingerprint::query()->create([
            'fingerprint' => 'doctor|instance.checkout_missing|instance|'.$id, 'source' => ProblemSource::Doctor, 'occurrences' => 0,
            'evidence' => ['doctor_node' => (string) $node->id, 'doctor_family' => 'instance', 'doctor_code' => 'instance.checkout_missing', 'doctor_resource' => $id, 'doctor_observed_at' => now()->toISOString()],
        ]);
    }
    $this->travel(1)->minutes();
    $family = DoctorFamilyReportData::fromIssues(DoctorFamily::Instance, 3, [
        DoctorIssueData::fromInternal(DoctorFamily::Instance, 'instance.checkout_missing', DoctorIssueKind::Drift, 102, 'failing', 'Still missing', true, false),
    ], [101, 102]);
    app(ProblemRecovery::class)->doctor(DoctorReportData::fromNodes([
        DoctorNodeReportData::fromFamilies($node->id, $node->name, [$family]),
    ]), now());
    expect($rows[101]->refresh()->evidence['resolution'] ?? null)->toBe('fresh_doctor_success')
        ->and($rows[102]->refresh()->evidence)->not->toHaveKey('resolved_at')
        ->and($rows[103]->refresh()->evidence)->not->toHaveKey('resolved_at');
});

it('ignores informational inventory while confirming a fresh resource observation', function (): void {
    problem_collector_sandbox();
    $node = problem_collector_node('tools');
    $row = ProblemFingerprint::query()->create(['fingerprint' => 'doctor|tool.not_installed|tool|101', 'source' => ProblemSource::Doctor, 'occurrences' => 0, 'evidence' => ['doctor_node' => (string) $node->id, 'doctor_family' => 'tool', 'doctor_code' => 'tool.not_installed', 'doctor_resource' => '101', 'doctor_observed_at' => now()->toISOString()]]);
    $this->travel(1)->minutes();
    $family = DoctorFamilyReportData::fromIssues(DoctorFamily::Tool, 1, [
        DoctorIssueData::fromInternal(DoctorFamily::Tool, 'tool.inventory_scan', DoctorIssueKind::Informational, null, null, 'Unused manager absent', null, null),
    ], [101]);
    app(ProblemRecovery::class)->doctor(DoctorReportData::fromNodes([
        DoctorNodeReportData::fromFamilies($node->id, $node->name, [$family]),
    ]), now());
    expect($row->refresh()->evidence['resolution'] ?? null)->toBe('fresh_doctor_success');
});

it('recovers a partial check whose fingerprint is hashed using the stored issue code', function (): void {
    problem_collector_sandbox();
    $node = problem_collector_node('hashed-check');
    $resource = str_repeat('long-resource-', 25);
    $code = 'schedule.orphan_artifact';
    $report = DoctorReportData::fromNodes([DoctorNodeReportData::fromFamilies($node->id, $node->name, [
        DoctorFamilyReportData::fromIssues(DoctorFamily::Schedule, 0, [
            DoctorIssueData::fromInternal(DoctorFamily::Schedule, $code, DoctorIssueKind::Drift, $resource, null, 'Orphan present', 'absent', 'present'),
        ]),
    ])]);
    app(ProblemCollector::class)->recordDoctor($report, now());
    $row = ProblemFingerprint::query()->sole();
    expect($row->fingerprint)->toStartWith('doctor#');
    expect($row->evidence['doctor_code'] ?? null)->toBe($code);
    $this->travel(1)->minutes();
    $clean = DoctorFamilyReportData::fromIssues(DoctorFamily::Schedule, 0, [], observedInventories: [$code => []]);

    app(ProblemCollector::class)->recordDoctor(DoctorReportData::fromNodes([
        DoctorNodeReportData::fromFamilies($node->id, $node->name, [$clean]),
    ]), now());

    expect($row->refresh()->evidence['resolution'] ?? null)->toBe('fresh_doctor_success');
});
