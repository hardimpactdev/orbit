<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

use OrbitDocsLinter\Rules\AppNodeWriteDenialRule;
use OrbitDocsLinter\Rules\AppPhpVersionContractRule;
use OrbitDocsLinter\Rules\BehaviorContractStructureRule;
use OrbitDocsLinter\Rules\CanonicalTechnicalContractRule;
use OrbitDocsLinter\Rules\CommandContractComplexityRule;
use OrbitDocsLinter\Rules\CommandDirectoryStructureRule;
use OrbitDocsLinter\Rules\ConceptIndexRule;
use OrbitDocsLinter\Rules\ConvertedFamilyStructureRule;
use OrbitDocsLinter\Rules\DestructiveConsentRule;
use OrbitDocsLinter\Rules\DoctorIssueCodePrefixRule;
use OrbitDocsLinter\Rules\DoctorRelationshipReferenceRule;
use OrbitDocsLinter\Rules\DoctorWarningCoherenceRule;
use OrbitDocsLinter\Rules\DocumentComplexityRule;
use OrbitDocsLinter\Rules\DriftIssueSuffixRule;
use OrbitDocsLinter\Rules\ErrorCodeRegistryRule;
use OrbitDocsLinter\Rules\ExitStatusPolicyRule;
use OrbitDocsLinter\Rules\HumanRendererProgressTreeRule;
use OrbitDocsLinter\Rules\InputModeContractRule;
use OrbitDocsLinter\Rules\JsonRendererEnvelopeRule;
use OrbitDocsLinter\Rules\JsonRendererExampleRule;
use OrbitDocsLinter\Rules\JsonWarningShapeRule;
use OrbitDocsLinter\Rules\MarkdownLinkIntegrityRule;
use OrbitDocsLinter\Rules\NextActionContractRule;
use OrbitDocsLinter\Rules\NoCommandAmbiguityFilesRule;
use OrbitDocsLinter\Rules\ProductCodeNamespaceRule;
use OrbitDocsLinter\Rules\PublicJsonOptionContractRule;
use OrbitDocsLinter\Rules\ReadCommandNoLiveProbeRule;
use OrbitDocsLinter\Rules\RequirementSmellRule;
use OrbitDocsLinter\Rules\RoleCompanionCoverageRule;
use OrbitDocsLinter\Rules\SharedFailureVocabularyRule;
use OrbitDocsLinter\Rules\SignatureOptionConsistencyRule;
use OrbitDocsLinter\Rules\TechnicalSlotSemanticsRule;
use OrbitDocsLinter\Rules\TechnicalTestMappingRule;
use OrbitDocsLinter\Rules\TestMappingFormatRule;

final class CommandDocsLintCli
{
    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): int
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $options = $this->parseArguments($arguments);

        if ($options['help']) {
            $this->printHelp();

            return 0;
        }

        $scanRoot = $this->resolvePath($repositoryRoot, $options['path']);
        $context = new CommandDocsLintContext(
            repositoryRoot: $repositoryRoot,
            scanRoot: $scanRoot,
        );

        $findings = $this->linter($repositoryRoot)->lint($context, $options['group']);
        $errors = array_values(array_filter(
            $findings,
            fn (CommandDocsLintFinding $finding): bool => $finding->severity === CommandDocsLintSeverity::Error,
        ));

        if ($findings === []) {
            fwrite(STDOUT, "Command docs lint passed.\n");

            return 0;
        }

        $currentPath = null;

        foreach ($findings as $finding) {
            if ($finding->path !== $currentPath) {
                $currentPath = $finding->path;
                fwrite(STDOUT, "{$currentPath}\n");
            }

            $line = $finding->line === null ? '' : ":{$finding->line}";

            fwrite(STDOUT, "  [{$finding->severity->value}] {$finding->ruleId}{$line}\n");
            fwrite(STDOUT, "  {$finding->message}\n");
        }

        fwrite(
            STDOUT,
            sprintf(
                "\n%d command documentation lint issue(s) found: %d error(s), %d warning(s).\n",
                count($findings),
                count($errors),
                count($findings) - count($errors),
            ),
        );

        if ($errors !== []) {
            return 1;
        }

        return $options['strict'] ? 1 : 0;
    }

    private function linter(string $repositoryRoot): CommandDocsLinter
    {
        return new CommandDocsLinter(
            rules: [
                new ConvertedFamilyStructureRule,
                new CommandDirectoryStructureRule,
                new NoCommandAmbiguityFilesRule,
                new TechnicalSlotSemanticsRule,
                new MarkdownLinkIntegrityRule,
                new ConceptIndexRule,
                new CanonicalTechnicalContractRule,
                new BehaviorContractStructureRule,
                new DestructiveConsentRule,
                new SharedFailureVocabularyRule,
                new ProductCodeNamespaceRule,
                new DriftIssueSuffixRule,
                new ExitStatusPolicyRule,
                new AppPhpVersionContractRule,
                new DoctorRelationshipReferenceRule,
                new DoctorIssueCodePrefixRule,
                new DoctorWarningCoherenceRule,
                new ErrorCodeRegistryRule,
                new RoleCompanionCoverageRule,
                new AppNodeWriteDenialRule,
                new InputModeContractRule,
                new PublicJsonOptionContractRule,
                new HumanRendererProgressTreeRule,
                new ReadCommandNoLiveProbeRule,
                new JsonRendererEnvelopeRule,
                new JsonRendererExampleRule,
                new JsonWarningShapeRule,
                new NextActionContractRule,
                new SignatureOptionConsistencyRule,
                new TechnicalTestMappingRule,
                new TestMappingFormatRule,
                new CommandContractComplexityRule,
                new DocumentComplexityRule,
                new RequirementSmellRule,
            ],
            baseline: CommandDocsLintBaseline::fromFile("{$repositoryRoot}/tool/docs-linter/baseline.json"),
        );
    }

    /**
     * @param  list<string>  $arguments
     * @return array{path: string, group: ?string, help: bool, strict: bool}
     */
    private function parseArguments(array $arguments): array
    {
        $options = [
            'path' => 'docs/commands',
            'group' => null,
            'help' => false,
            'strict' => false,
        ];

        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;

                continue;
            }

            if ($argument === '--strict') {
                $options['strict'] = true;

                continue;
            }

            if (str_starts_with($argument, '--path=')) {
                $options['path'] = substr($argument, strlen('--path='));

                continue;
            }

            if (str_starts_with($argument, '--group=')) {
                $options['group'] = $this->parseGroup(substr($argument, strlen('--group=')));

                continue;
            }

            fwrite(STDERR, "Unknown argument: {$argument}\n\n");
            $this->printHelp(STDERR);
            exit(2);
        }

        return $options;
    }

    private function parseGroup(string $group): string
    {
        $supportedGroups = ['structure', 'contracts', 'references', 'complexity', 'prose'];

        if (in_array($group, $supportedGroups, true)) {
            return $group;
        }

        fwrite(STDERR, "Unknown group: {$group}\n\n");
        $this->printHelp(STDERR);
        exit(2);
    }

    private function resolvePath(string $repositoryRoot, string $path): string
    {
        if (str_starts_with($path, '/')) {
            return rtrim($path, '/');
        }

        return rtrim("{$repositoryRoot}/{$path}", '/');
    }

    /**
     * @param  resource  $stream
     */
    private function printHelp($stream = STDOUT): void
    {
        fwrite($stream, <<<'HELP'
Usage:
  php tool/docs-linter/docs-linter.php [--path=docs/commands] [--group=structure|contracts|references|complexity|prose] [--strict]

Examples:
  composer docs-lint
  composer docs-lint -- --path=docs/commands/1_node
  composer docs-lint -- --path=docs/commands/1_node/1_node-new
  composer docs-lint -- --group=contracts
  composer docs-lint -- --strict

HELP);
    }
}
