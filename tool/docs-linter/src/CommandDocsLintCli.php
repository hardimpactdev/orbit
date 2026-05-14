<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

use Laravel\Pao\OutputCleaner;
use OrbitDocsLinter\Rules\ActivityLoggingContractRule;
use OrbitDocsLinter\Rules\AppNodeWriteDenialRule;
use OrbitDocsLinter\Rules\AppPhpVersionContractRule;
use OrbitDocsLinter\Rules\BehaviorContractStructureRule;
use OrbitDocsLinter\Rules\BulletComplexityRule;
use OrbitDocsLinter\Rules\CanonicalBehaviorBoundaryRule;
use OrbitDocsLinter\Rules\CanonicalTechnicalContractRule;
use OrbitDocsLinter\Rules\CommandContractComplexityRule;
use OrbitDocsLinter\Rules\CommandDirectoryStructureRule;
use OrbitDocsLinter\Rules\CommandPageStructureRule;
use OrbitDocsLinter\Rules\CommonFailureNotRestatedRule;
use OrbitDocsLinter\Rules\CompoundCommandPrefixRule;
use OrbitDocsLinter\Rules\CompoundNounStackRule;
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
use OrbitDocsLinter\Rules\FamilyCommandPrefixRule;
use OrbitDocsLinter\Rules\HumanRendererProgressTreeRule;
use OrbitDocsLinter\Rules\InputModeContractRule;
use OrbitDocsLinter\Rules\JsonRendererEnvelopeRule;
use OrbitDocsLinter\Rules\JsonRendererExampleRule;
use OrbitDocsLinter\Rules\JsonWarningShapeRule;
use OrbitDocsLinter\Rules\LongSectionStructureRule;
use OrbitDocsLinter\Rules\MarkdownLinkIntegrityRule;
use OrbitDocsLinter\Rules\NextActionContractRule;
use OrbitDocsLinter\Rules\NoCommandAmbiguityFilesRule;
use OrbitDocsLinter\Rules\NonStateDomainHandoffRule;
use OrbitDocsLinter\Rules\NoPerCommandAuthorizationSectionRule;
use OrbitDocsLinter\Rules\ProductCodeNamespaceRule;
use OrbitDocsLinter\Rules\PublicCommandPageBoundaryRule;
use OrbitDocsLinter\Rules\PublicJsonOptionContractRule;
use OrbitDocsLinter\Rules\ReadCommandNoLiveProbeRule;
use OrbitDocsLinter\Rules\ReaderAddressRule;
use OrbitDocsLinter\Rules\RendererPrimitiveReferenceRule;
use OrbitDocsLinter\Rules\RequirementSmellRule;
use OrbitDocsLinter\Rules\RoleCompanionCoverageRule;
use OrbitDocsLinter\Rules\SectionOpenerProseRule;
use OrbitDocsLinter\Rules\SentenceCaseHeadingRule;
use OrbitDocsLinter\Rules\SharedFailureVocabularyRule;
use OrbitDocsLinter\Rules\SignatureArgumentOrderRule;
use OrbitDocsLinter\Rules\SignatureOptionConsistencyRule;
use OrbitDocsLinter\Rules\TableProseComplexityRule;
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

        if ($options['update_baseline']) {
            return $this->updateBaseline($repositoryRoot, $context, $options['group']);
        }

        $findings = $this->linter($repositoryRoot)->lint($context, $options['group']);
        $errors = array_values(array_filter(
            $findings,
            fn (CommandDocsLintFinding $finding): bool => $finding->severity === CommandDocsLintSeverity::Error,
        ));

        $exitCode = $this->exitCode($findings, $errors, $options['strict']);

        if ($options['format'] === 'agent') {
            $this->printAgentResult($findings, $errors, $exitCode);

            return $exitCode;
        }

        $this->printTextResult($findings, $errors);

        return $exitCode;
    }

    private function updateBaseline(string $repositoryRoot, CommandDocsLintContext $context, ?string $group): int
    {
        $linter = new CommandDocsLinter(
            rules: $this->rules(),
            baseline: CommandDocsLintBaseline::empty(),
        );

        $findings = $linter->lint($context, $group);
        $counts = [];

        foreach ($findings as $finding) {
            $key = "{$finding->path}\0{$finding->ruleId}\0{$finding->severity->value}";
            $counts[$key] ??= [
                'path' => $finding->path,
                'rule_id' => $finding->ruleId,
                'severity' => $finding->severity->value,
                'count' => 0,
            ];
            $counts[$key]['count']++;
        }

        $entries = array_values($counts);

        usort($entries, fn (array $first, array $second): int => [
            $first['path'],
            $first['rule_id'],
            $first['severity'],
        ] <=> [
            $second['path'],
            $second['rule_id'],
            $second['severity'],
        ]);

        $payload = json_encode(
            ['findings' => $entries],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $path = "{$repositoryRoot}/tool/docs-linter/baseline.json";
        file_put_contents($path, $payload."\n");

        fwrite(STDOUT, sprintf("Wrote %d baseline entries to %s\n", count($entries), $path));

        return 0;
    }

    /**
     * @param  list<CommandDocsLintFinding>  $findings
     * @param  list<CommandDocsLintFinding>  $errors
     */
    private function printTextResult(array $findings, array $errors): void
    {
        if ($findings === []) {
            fwrite(STDOUT, "Command docs lint passed.\n");

            return;
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
    }

    /**
     * @param  list<CommandDocsLintFinding>  $findings
     * @param  list<CommandDocsLintFinding>  $errors
     */
    private function printAgentResult(array $findings, array $errors, int $exitCode): void
    {
        $warnings = array_values(array_filter(
            $findings,
            fn (CommandDocsLintFinding $finding): bool => $finding->severity === CommandDocsLintSeverity::Warning,
        ));

        $payload = [
            'tool' => 'docs-lint',
            'result' => $exitCode === 0 ? 'passed' : 'failed',
            'issues' => count($findings),
            'errors' => count($errors),
            'warnings' => count($warnings),
        ];

        if ($findings !== []) {
            $payload['findings'] = array_map(
                fn (CommandDocsLintFinding $finding): array => $this->agentFinding($finding),
                $findings,
            );
        }

        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    /**
     * @return array{path: string, line?: int, severity: string, rule: string, message: string}
     */
    private function agentFinding(CommandDocsLintFinding $finding): array
    {
        $payload = [
            'path' => $finding->path,
            'severity' => $finding->severity->value,
            'rule' => $finding->ruleId,
            'message' => OutputCleaner::clean($finding->message),
        ];

        if ($finding->line !== null) {
            $payload['line'] = $finding->line;
        }

        return $payload;
    }

    /**
     * @param  list<CommandDocsLintFinding>  $findings
     * @param  list<CommandDocsLintFinding>  $errors
     */
    private function exitCode(array $findings, array $errors, bool $strict): int
    {
        if ($errors !== []) {
            return 1;
        }

        return $strict && $findings !== [] ? 1 : 0;
    }

    private function linter(string $repositoryRoot): CommandDocsLinter
    {
        return new CommandDocsLinter(
            rules: $this->rules(),
            baseline: CommandDocsLintBaseline::fromFile("{$repositoryRoot}/tool/docs-linter/baseline.json"),
        );
    }

    /**
     * @return list<CommandDocsLintRule>
     */
    private function rules(): array
    {
        return [
            new ConvertedFamilyStructureRule,
            new CommandDirectoryStructureRule,
            new CommandPageStructureRule,
            new FamilyCommandPrefixRule,
            new CompoundCommandPrefixRule,
            new NoCommandAmbiguityFilesRule,
            new TechnicalSlotSemanticsRule,
            new MarkdownLinkIntegrityRule,
            new ConceptIndexRule,
            new NonStateDomainHandoffRule,
            new CanonicalBehaviorBoundaryRule,
            new CanonicalTechnicalContractRule,
            new ActivityLoggingContractRule,
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
            new NoPerCommandAuthorizationSectionRule,
            new CommonFailureNotRestatedRule,
            new PublicCommandPageBoundaryRule,
            new InputModeContractRule,
            new PublicJsonOptionContractRule,
            new HumanRendererProgressTreeRule,
            new RendererPrimitiveReferenceRule,
            new ReadCommandNoLiveProbeRule,
            new JsonRendererEnvelopeRule,
            new JsonRendererExampleRule,
            new JsonWarningShapeRule,
            new NextActionContractRule,
            new SignatureArgumentOrderRule,
            new SignatureOptionConsistencyRule,
            new TechnicalTestMappingRule,
            new TestMappingFormatRule,
            new CommandContractComplexityRule,
            new DocumentComplexityRule,
            new RequirementSmellRule,
            new SentenceCaseHeadingRule,
            new CompoundNounStackRule,
            new LongSectionStructureRule,
            new BulletComplexityRule,
            new SectionOpenerProseRule,
            new TableProseComplexityRule,
            new ReaderAddressRule,
        ];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{path: string, group: ?string, help: bool, strict: bool, format: string, update_baseline: bool}
     */
    private function parseArguments(array $arguments): array
    {
        $options = [
            'path' => 'docs/commands',
            'group' => null,
            'help' => false,
            'strict' => false,
            'format' => 'text',
            'update_baseline' => false,
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

            if ($argument === '--update-baseline') {
                $options['update_baseline'] = true;

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

            if (str_starts_with($argument, '--format=')) {
                $options['format'] = $this->parseFormat(substr($argument, strlen('--format=')));

                continue;
            }

            fwrite(STDERR, "Unknown argument: {$argument}\n\n");
            $this->printHelp(STDERR);
            exit(2);
        }

        return $options;
    }

    private function parseFormat(string $format): string
    {
        $supportedFormats = ['text', 'agent'];

        if (in_array($format, $supportedFormats, true)) {
            return $format;
        }

        fwrite(STDERR, "Unknown format: {$format}\n\n");
        $this->printHelp(STDERR);
        exit(2);
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
  php tool/docs-linter/docs-linter.php [--path=docs/commands] [--group=structure|contracts|references|complexity|prose] [--format=text|agent] [--strict] [--update-baseline]

Examples:
  composer docs-lint
  composer docs-lint -- --format=text
  composer docs-lint -- --path=docs/commands/1_node
  composer docs-lint -- --path=docs/commands/1_node/1_node-new
  composer docs-lint -- --group=contracts
  composer docs-lint -- --strict
  composer docs-lint -- --update-baseline

HELP);
    }
}
