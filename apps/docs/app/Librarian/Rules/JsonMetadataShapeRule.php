<?php

declare(strict_types=1);

namespace App\Librarian\Rules;

use App\Librarian\JsonExampleParser;
use App\Librarian\JsonMetadataExampleDecoder;
use App\Librarian\JsonMetadataStructureInspector;
use App\Librarian\OrbitCommandDocs;
use HardImpact\Librarian\Linting\Finding;
use HardImpact\Librarian\Linting\FindingSeverity;
use HardImpact\Librarian\Linting\GroupedRule;

final readonly class JsonMetadataShapeRule implements GroupedRule
{
    public function __construct(
        private OrbitCommandDocs $docs,
        private JsonExampleParser $parser,
        private JsonMetadataExampleDecoder $decoder,
        private JsonMetadataStructureInspector $inspector,
    ) {}

    public function group(): string
    {
        return 'contracts';
    }

    public function check(): array
    {
        $findings = [];

        foreach ($this->docs->markdownFiles($this->docs->docsRoot()) as $file) {
            $contents = file_get_contents($file);

            if ($contents === false) {
                continue;
            }

            foreach ($this->parser->parse($file, $contents) as $example) {
                foreach ($this->decoder->decodeExamples($example->raw) as $decoded) {
                    foreach ($this->inspector->emptyMetaPaths($decoded) as $path) {
                        $findings[] = $this->finding(
                            $file,
                            sprintf(
                                'JSON example %d documents empty metadata at %s as an object; use an empty array [] to match the shared JSON envelope contract.',
                                $example->blockIndex + 1,
                                $path,
                            ),
                            $example->line,
                        );
                    }
                }
            }
        }

        return $findings;
    }

    private function finding(string $path, string $message, ?int $line = null): Finding
    {
        return new Finding(
            path: $this->docs->relativePath($path),
            line: $line,
            severity: FindingSeverity::Error,
            rule: 'command_docs.json_metadata_shape',
            message: $message,
        );
    }
}
