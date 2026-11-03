<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

/** What synchronization did with `.env.testing`, for the activity record. It holds no value. */
final readonly class InstanceTestEnvironmentOutcome
{
    public const string Written = 'written';

    public const string Unchanged = 'unchanged';

    public const string SkippedTracked = 'skipped_tracked';

    /** A forced sync without the Node agent's report: whether Git tracks the file is not known. */
    public const string SkippedUnobserved = 'skipped_unobserved';

    public function __construct(
        public string $status,
        public string $testDatabase,
    ) {}

    /** @return array{file: string, status: string, test_database: string} */
    public function toArray(): array
    {
        return [
            'file' => InstanceTestEnvironmentWriter::FILE,
            'status' => $this->status,
            'test_database' => $this->testDatabase,
        ];
    }
}
