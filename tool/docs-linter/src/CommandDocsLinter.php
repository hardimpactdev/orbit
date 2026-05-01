<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

final readonly class CommandDocsLinter
{
    private CommandDocsLintBaseline $baseline;

    /**
     * @param  list<CommandDocsLintRule>  $rules
     */
    public function __construct(
        private array $rules,
        ?CommandDocsLintBaseline $baseline = null,
    ) {
        $this->baseline = $baseline ?? CommandDocsLintBaseline::empty();
    }

    /**
     * @return list<CommandDocsLintFinding>
     */
    public function lint(CommandDocsLintContext $context, ?string $group): array
    {
        $findings = [];

        foreach ($this->rules as $rule) {
            if ($group !== null && $rule->group() !== $group) {
                continue;
            }

            array_push($findings, ...$rule->check($context));
        }

        $filteredFindings = $this->baseline->apply($findings);

        usort(
            $filteredFindings,
            fn (CommandDocsLintFinding $first, CommandDocsLintFinding $second): int => [
                $first->path,
                $first->line ?? PHP_INT_MAX,
                $first->severity->value,
                $first->ruleId,
                $first->message,
            ] <=> [
                $second->path,
                $second->line ?? PHP_INT_MAX,
                $second->severity->value,
                $second->ruleId,
                $second->message,
            ],
        );

        return $filteredFindings;
    }
}
