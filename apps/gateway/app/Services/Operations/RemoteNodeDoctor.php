<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Models\OperationRun;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use JsonException;
use Throwable;

class RemoteNodeDoctor
{
    private const int DOCTOR_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly ?RunsInternalCommands $localExecutor = null,
        private readonly ?WorkloadNodeDoctorIssueParser $doctorIssues = null,
    ) {}

    public function issues(Node $node, OperationRun $operationRun): ?int
    {
        try {
            $result = $this->localExecutor()->runInternal(
                node: $node,
                commandName: 'internal:doctor-self',
                transportOptions: [
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => $operationRun->id,
                    ],
                    'timeout' => self::DOCTOR_TIMEOUT_SECONDS,
                    'throw' => false,
                ],
            );
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        // Lenient parse is safe: this post-update doctor count is display-only.
        // Unknown (null) does not remediate or rewrite node state.
        try {
            $data = RemoteShellSuccessData::fromJsonEnvelope($result);
        } catch (JsonException) {
            return null;
        }

        if (! is_string($data['output'] ?? null)) {
            return null;
        }

        return $this->doctorIssues()->fromOutput($data['output']);
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RunsInternalCommands::class);
    }

    private function doctorIssues(): WorkloadNodeDoctorIssueParser
    {
        return $this->doctorIssues ?? app(WorkloadNodeDoctorIssueParser::class);
    }
}
