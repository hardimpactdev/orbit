<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

interface CommandDocsLintRule
{
    public function id(): string;

    public function group(): string;

    /**
     * @return list<CommandDocsLintFinding>
     */
    public function check(CommandDocsLintContext $context): array;
}
