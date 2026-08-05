<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class ConvertedFamilyStructureRule implements GroupedRule
{
    /**
     * @var array<string, string>
     */
    private const array STATE_FAMILY_DOCTORS = [
        'app' => 'instance-doctor.md',
        'database' => 'database-doctor.md',
        'firewall' => 'firewall-doctor.md',
        'node' => 'node-doctor.md',
        'process' => 'process-doctor.md',
        'proxy' => 'proxy-doctor.md',
        'schedule' => 'schedule-doctor.md',
        'tool' => 'tool-doctor.md',
        'workspace' => 'workspace-doctor.md',
    ];

    public function __construct(
        private OrbitCommandDocs $docs,
    ) {}

    public function group(): string
    {
        return 'structure';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->familyDirectories() as $familyDirectory) {
            if (! is_file("{$familyDirectory}/README.md")) {
                $findings[] = $this->finding(
                    $this->docs->relativePath($familyDirectory),
                    'Converted family directories must contain README.md.',
                );
            }

            $doctorFile = self::STATE_FAMILY_DOCTORS[$this->docs->familyName($familyDirectory)] ?? null;

            if ($doctorFile !== null && ! is_file("{$familyDirectory}/{$doctorFile}")) {
                $findings[] = $this->finding(
                    $this->docs->relativePath($familyDirectory),
                    "State-family documentation directories must contain {$doctorFile}.",
                );
            }

            foreach ($this->docs->markdownFiles($familyDirectory, recursive: false) as $file) {
                if (! $this->isFlatCommandFile($file)) {
                    continue;
                }

                $findings[] = $this->finding(
                    $this->docs->relativePath($file),
                    'Converted family commands must use numbered command directories with split technical docs; move this flat command file into N_command-name/command-name.md with technical contracts.',
                );
            }
        }

        return $findings;
    }

    private function isFlatCommandFile(string $file): bool
    {
        return preg_match('/^[1-9]\d*_[a-z0-9]+(?:-[a-z0-9]+)*\.md$/', basename($file)) === 1;
    }

    private function finding(string $path, string $message): Finding
    {
        return new Finding(
            path: $path,
            line: null,
            severity: FindingSeverity::Error,
            rule: 'command_docs.converted_family_structure',
            message: $message,
        );
    }
}
