<?php

declare(strict_types=1);

use App\Docs\Librarian\Rules\ActivityLoggingContractRule;
use App\Docs\Librarian\Rules\AppNodeWriteDenialRule;
use App\Docs\Librarian\Rules\AppPhpVersionContractRule;
use App\Docs\Librarian\Rules\BehaviorContractStructureRule;
use App\Docs\Librarian\Rules\CanonicalBehaviorBoundaryRule;
use App\Docs\Librarian\Rules\CanonicalTechnicalContractRule;
use App\Docs\Librarian\Rules\CommandContractComplexityRule;
use App\Docs\Librarian\Rules\CommandDirectoryStructureRule;
use App\Docs\Librarian\Rules\CommandPageStructureRule;
use App\Docs\Librarian\Rules\CommonFailureNotRestatedRule;
use App\Docs\Librarian\Rules\CompoundCommandPrefixRule;
use App\Docs\Librarian\Rules\ConceptIndexRule;
use App\Docs\Librarian\Rules\ConvertedFamilyStructureRule;
use App\Docs\Librarian\Rules\DestructiveConsentRule;
use App\Docs\Librarian\Rules\DoctorIssueCodePrefixRule;
use App\Docs\Librarian\Rules\DoctorRelationshipReferenceRule;
use App\Docs\Librarian\Rules\DoctorWarningCoherenceRule;
use App\Docs\Librarian\Rules\DriftIssueSuffixRule;
use App\Docs\Librarian\Rules\ErrorCodeRegistryRule;
use App\Docs\Librarian\Rules\ExitStatusPolicyRule;
use App\Docs\Librarian\Rules\FamilyCommandPrefixRule;
use App\Docs\Librarian\Rules\HumanRendererProgressTreeRule;
use App\Docs\Librarian\Rules\InputModeContractRule;
use App\Docs\Librarian\Rules\JsonRendererEnvelopeRule;
use App\Docs\Librarian\Rules\JsonRendererExampleRule;
use App\Docs\Librarian\Rules\JsonWarningShapeRule;
use App\Docs\Librarian\Rules\MarkdownLinkIntegrityRule;
use App\Docs\Librarian\Rules\NextActionContractRule;
use App\Docs\Librarian\Rules\NoCommandAmbiguityFilesRule;
use App\Docs\Librarian\Rules\NonStateDomainHandoffRule;
use App\Docs\Librarian\Rules\NoPerCommandAuthorizationSectionRule;
use App\Docs\Librarian\Rules\ProductCodeNamespaceRule;
use App\Docs\Librarian\Rules\PublicCommandPageBoundaryRule;
use App\Docs\Librarian\Rules\PublicJsonOptionContractRule;
use App\Docs\Librarian\Rules\ReadCommandNoLiveProbeRule;
use App\Docs\Librarian\Rules\ReaderAddressRule;
use App\Docs\Librarian\Rules\RendererPrimitiveReferenceRule;
use App\Docs\Librarian\Rules\RoleCompanionCoverageRule;
use App\Docs\Librarian\Rules\SharedFailureVocabularyRule;
use App\Docs\Librarian\Rules\SignatureArgumentOrderRule;
use App\Docs\Librarian\Rules\SignatureOptionConsistencyRule;
use App\Docs\Librarian\Rules\TechnicalSlotSemanticsRule;
use App\Docs\Librarian\Rules\TechnicalTestMappingRule;
use App\Docs\Librarian\Rules\TestMappingFormatRule;
use HardImpact\Librarian\Docs\DocsConfig;
use HardImpact\Librarian\Linting\Rules\BulletComplexityRule;
use HardImpact\Librarian\Linting\Rules\CompoundNounStackRule;
use HardImpact\Librarian\Linting\Rules\DocumentComplexityRule;
use HardImpact\Librarian\Linting\Rules\LongSectionStructureRule;
use HardImpact\Librarian\Linting\Rules\RequirementSmellRule;
use HardImpact\Librarian\Linting\Rules\SectionOpenerProseRule;
use HardImpact\Librarian\Linting\Rules\SentenceCaseHeadingRule;
use HardImpact\Librarian\Linting\Rules\TableProseComplexityRule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $this->docsRoot = makeOrbitLibrarianDocsFixture();

    config()->set('librarian.path', "{$this->docsRoot}/docs");
    config()->set('librarian.rules', [
        ConvertedFamilyStructureRule::class,
        CommandDirectoryStructureRule::class,
        FamilyCommandPrefixRule::class,
        CompoundCommandPrefixRule::class,
        NoCommandAmbiguityFilesRule::class,
        TechnicalSlotSemanticsRule::class,
        CommandPageStructureRule::class,
        DocumentComplexityRule::class,
        CompoundNounStackRule::class,
        RequirementSmellRule::class,
        SentenceCaseHeadingRule::class,
        LongSectionStructureRule::class,
        BulletComplexityRule::class,
        SectionOpenerProseRule::class,
        TableProseComplexityRule::class,
        MarkdownLinkIntegrityRule::class,
        ProductCodeNamespaceRule::class,
        PublicJsonOptionContractRule::class,
        JsonRendererEnvelopeRule::class,
        ExitStatusPolicyRule::class,
        NoPerCommandAuthorizationSectionRule::class,
        DoctorIssueCodePrefixRule::class,
        InputModeContractRule::class,
        DestructiveConsentRule::class,
        JsonRendererExampleRule::class,
        JsonWarningShapeRule::class,
        RendererPrimitiveReferenceRule::class,
        CanonicalTechnicalContractRule::class,
        BehaviorContractStructureRule::class,
        CanonicalBehaviorBoundaryRule::class,
        PublicCommandPageBoundaryRule::class,
        SignatureArgumentOrderRule::class,
        SignatureOptionConsistencyRule::class,
        ActivityLoggingContractRule::class,
        ErrorCodeRegistryRule::class,
        DoctorWarningCoherenceRule::class,
        SharedFailureVocabularyRule::class,
        NextActionContractRule::class,
        AppPhpVersionContractRule::class,
        AppNodeWriteDenialRule::class,
        ReadCommandNoLiveProbeRule::class,
        DriftIssueSuffixRule::class,
        CommonFailureNotRestatedRule::class,
        HumanRendererProgressTreeRule::class,
        ConceptIndexRule::class,
        RoleCompanionCoverageRule::class,
        TechnicalTestMappingRule::class,
        TestMappingFormatRule::class,
        DoctorRelationshipReferenceRule::class,
        NonStateDomainHandoffRule::class,
        ReaderAddressRule::class,
        CommandContractComplexityRule::class,
    ]);

    app()->forgetInstance(DocsConfig::class);
});

afterEach(function (): void {
    deleteOrbitLibrarianDocsFixture($this->docsRoot);
});

it('passes valid Orbit command docs through Librarian structure linting', function (): void {
    writeOrbitCommandDocsFamily($this->docsRoot);

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'structure',
    ]);

    expect($exitCode)->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'tool' => 'librarian',
            'result' => 'passed',
            'issues' => 0,
        ]);
});

it('reports Orbit command family and command directory structure issues', function (): void {
    writeOrbitCommandDocsFamily($this->docsRoot, validCommandDirectory: false);
    unlink("{$this->docsRoot}/docs/domains/1_node/README.md");
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/2_node-remove.md', '# Flat command');

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'structure',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(array_column($payload['findings'], 'rule'))->toContain(
            'command_docs.converted_family_structure',
            'command_docs.command_directory_structure',
        )
        ->and(array_column($payload['findings'], 'message'))->toContain(
            'Converted family directories must contain README.md.',
            'Converted family commands must use numbered command directories with split technical docs; move this flat command file into N_command-name/command-name.md with technical contracts.',
            'Command directories must contain a technical directory.',
        );
});

it('reports commands placed in the wrong non-operation family', function (): void {
    writeOrbitCommandDocsFamily($this->docsRoot);
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/2_app-register/app-register.md', "# `orbit app:register`\n");
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/2_app-register/technical/1_app-register.md', "# Technical Contract\n");
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/2_app-register/technical/6.1_app-register_output-render_human.md', "# Human Renderer\n");
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/2_app-register/technical/6.2_app-register_output-render_json.md', "# JSON Renderer\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'structure',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/2_app-register',
            'severity' => 'error',
            'rule' => 'command_docs.family_command_prefix',
            'message' => 'Command `app-register` does not belong in the `node` family; non-operation family commands must start with `node-`.',
        ]);
});

it('reports split compound command prefixes in Orbit command docs', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit workspace:setup-step-add`\n\nRun `orbit workspace:setup-step-add` to add a step.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'structure',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'])->toHaveCount(2)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'line' => 1,
            'severity' => 'error',
            'rule' => 'command_docs.compound_command_prefix',
            'message' => 'Command `workspace:setup-step-add` splits a compound command prefix. Use `workspace-setup-step:add` so the longest command prefix stays before the colon.',
        ])
        ->and($payload['findings'][1])->toMatchArray([
            'line' => 3,
            'rule' => 'command_docs.compound_command_prefix',
        ]);
});

it('reports command ambiguity files inside Orbit command docs', function (): void {
    writeOrbitCommandDocsFamily($this->docsRoot);
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/1_node-new/ambiguity.md', "# Ambiguity\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'structure',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/ambiguity.md',
            'severity' => 'error',
            'rule' => 'command_docs.no_command_ambiguity_files',
            'message' => 'Command ambiguity tracking lives outside the repository; remove ambiguity.md and move unresolved decisions to the external tracker.',
        ]);
});

it('reports reserved technical slots with the wrong semantic filename', function (): void {
    writeOrbitCommandDocsFamily($this->docsRoot);
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/1_node-new/technical/2_node-new_on-app-node.md', "# Wrong Slot\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'structure',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/2_node-new_on-app-node.md',
            'severity' => 'error',
            'rule' => 'command_docs.technical_slot_semantics',
            'message' => 'Reserved technical slot 2 must be named 2_node-new_on-client.md.',
        ]);
});

it('reports broken relative markdown links inside Orbit command docs', function (): void {
    writeOrbitCommandDocsFamily($this->docsRoot);
    writeOrbitDocsFile(
        $this->docsRoot,
        'docs/domains/1_node/1_node-new/node-new.md',
        "# `orbit node:new`\n\n[Missing](missing.md)\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'rule' => 'command_docs.markdown_link_integrity',
            'message' => 'Markdown link target does not exist: missing.md.',
        ]);
});

it('reports family concept documents missing from the top-level concept index', function (): void {
    writeOrbitDocsFile($this->docsRoot, 'docs/concepts.md', "# Concepts\n");
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/node-concepts.md', "# Node Concepts\n\n- **Node intent:** Desired node state.\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/concepts.md',
            'severity' => 'error',
            'rule' => 'command_docs.concept_index',
            'message' => 'Top-level concepts index must include a `## Node Concepts` section for docs/domains/1_node/node-concepts.md.',
        ]);
});

it('reports missing role companion contracts when canonical docs declare role-specific behavior', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(extra: "\nRole-specific behavior is defined in these companion contracts.\n"),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.role_companion_coverage',
            'message' => 'Canonical contract declares role-specific companion behavior but 2_node-new_on-client.md is missing.',
        ]);
});

it('allows deployment companion subsets without app-role companion contracts', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(extra: "\nDeployment-context test mapping lives in:\n\n- [Client](2_node-new_on-client.md#test-mapping)\n- [Gateway](3_node-new_on-gateway-node.md#test-mapping)\n"),
    );
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/README.md', "# Domain Docs\n\n## JSON Envelope\n\nShared envelope.\n");

    $companionContract = "# Technical Contract: `node:new` From Configured Clients\n\n"
        ."[Back to `node:new` technical contract.](1_node-new.md)\n\n"
        ."## Allowed Paths\n\n"
        ."| Context | Behavior |\n"
        ."| --- | --- |\n"
        ."| Configured client | Forward to the gateway. |\n\n"
        ."## Test Mapping\n\n"
        ."| Path | Coverage |\n"
        ."| --- | --- |\n"
        ."| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers optional deployment-context companion slots. |\n";

    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/1_node-new/technical/2_node-new_on-client.md', $companionContract);
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/1_node-new/technical/3_node-new_on-gateway-node.md', $companionContract);

    config()->set('librarian.rules', [RoleCompanionCoverageRule::class]);
    app()->forgetInstance(DocsConfig::class);

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($payload['findings'] ?? [])->toBe([])
        ->and($exitCode)->toBe(0)
        ->and($payload)->toMatchArray([
            'tool' => 'librarian',
            'result' => 'passed',
            'issues' => 0,
        ]);
});

it('reports technical command files without test mapping sections', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        humanRendererContract: "# Human Renderer\n\n## Primitive\n\nNone. No human renderer primitive.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.1_node-new_output-render_human.md',
            'severity' => 'error',
            'rule' => 'command_docs.technical_test_mapping',
            'message' => 'Technical command files must include "## Test Mapping".',
        ]);
});

it('reports test mapping sections without a path coverage table', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        humanRendererContract: "# Human Renderer\n\n## Primitive\n\nNone. No human renderer primitive.\n\n## Test Mapping\n\n- Covers it.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.1_node-new_output-render_human.md',
            'severity' => 'error',
            'rule' => 'command_docs.test_mapping_format',
            'message' => 'Test Mapping must include a "| Path | Coverage |" table.',
        ]);
});

it('reports canonical doctor relationship sections that omit the family doctor contract', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(doctorRelationship: "Node doctor verifies drift.\n"),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.doctor_relationship_reference',
            'message' => 'Doctor Relationship must reference the family doctor contract node-doctor.md.',
        ]);
});

it('reports non-state domains without state-family doctor handoffs', function (): void {
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/10_php/README.md', "# PHP Commands\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'references',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/10_php/README.md',
            'severity' => 'error',
            'rule' => 'command_docs.non_state_domain_handoff',
            'message' => 'Non-state command domain README files must include a `## State Ownership` section.',
        ]);
});

it('reports public Orbit command pages without modern sections', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\n**Purpose:** Create a node.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'structure',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['warnings'])->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'line' => 1,
            'severity' => 'warning',
            'rule' => 'command_docs.command_page_structure',
        ]);
});

it('reports plural product code prefixes while allowing explicit storage fields', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\nThe DNS config is rendered from `nodes.tld` and `nodes.wireguard_address` state.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['issues'])->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.product_code_namespace',
            'message' => 'Product code `nodes.wireguard_address` must use singular prefix `node.`; plural dotted names are allowed only for explicit storage fields.',
        ]);
});

it('reports public command pages that omit the json option from canonical signatures', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(signature: 'orbit node:new [--json]'),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.public_json_option_contract',
            'message' => 'Public command page must mention `--json` when the canonical signature accepts it.',
        ]);
});

it('reports json renderer files without the shared envelope link', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        jsonRendererContract: "# JSON Renderer\n\nThe success and error envelopes are command-specific here.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md',
            'severity' => 'error',
            'rule' => 'command_docs.json_envelope',
            'message' => 'JSON renderer files must include "## Envelope" with a link to the shared JSON Envelope contract.',
        ]);
});

it('reports per-command numeric exit status sections', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\n[Technical](technical/1_node-new.md)\n\n## Usage\n\nUse it.\n\n## Exit Codes\n\n- `0`: OK.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'line' => 9,
            'severity' => 'error',
            'rule' => 'command_docs.exit_status_policy',
            'message' => 'Use the shared exit status policy instead of per-command numeric exit-code sections.',
        ]);
});

it('reports dedicated authorization sections in canonical technical contracts', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(extra: "\n## Authorization\n\nOnly admins may run this.\n"),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.no_per_command_authorization_section',
            'message' => "Canonical technical contracts must not include a dedicated '## Authorization' section. Authorization is gateway-owned and applies generically to every API call; document role-specific rejections in Prerequisites and Failure Semantics.",
        ]);
});

it('reports doctor issue codes with another product family prefix', function (): void {
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/3_tool/tool-doctor.md', "# Tool Doctor\n\n## Tool Issue Codes\n\n| Code | Detected when |\n| --- | --- |\n| `dns.container_missing` | DNS container is missing. |\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/3_tool/tool-doctor.md',
            'severity' => 'error',
            'rule' => 'command_docs.doctor_issue_code_prefix',
            'message' => 'Doctor Tool Issue Codes code `dns.container_missing` must use singular product prefix tool.',
        ]);
});

it('reports canonical input contracts that repeat shared invocation behavior', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(inputContract: "Interactive input mode may prompt before continuing.\n"),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.input_mode_contract',
            'message' => 'Canonical Input Contract sections must link the shared Invocation Model.',
        ])
        ->and($payload['findings'][1])->toMatchArray([
            'rule' => 'command_docs.input_mode_contract',
            'message' => 'Canonical Input Contract sections should link the shared Invocation Model without repeating generic input-mode or `--json` behavior.',
        ]);
});

it('reports destructive commands without destructive consent contracts', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: "# Technical Contract: `orbit node:remove`\n\n**Effects:** destructive node removal.\n\n## Signature\n\n`orbit node:remove`\n\n## Input Contract\n\n[Invocation Model](../../README.md#invocation-model)\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(array_column($payload['findings'], 'rule'))->toContain('command_docs.destructive_consent')
        ->and(array_column($payload['findings'], 'message'))->toContain(
            'Destructive canonical contracts must include [--force] in the command signature.',
            'Destructive commands must include an interactive input-mode contract for confirmation prompting.',
        );
});

it('reports canonical technical contracts missing required sections', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: "# Technical Contract: `orbit node:new`\n\n**Owner:** Node domain.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and(array_column($payload['findings'], 'rule'))->toContain('command_docs.canonical_technical_contract')
        ->and(array_column($payload['findings'], 'message'))->toContain(
            'Canonical technical contracts must define "**Effects:**".',
            'Canonical technical contracts must include "## Behavior Contract".',
        );
});

it('reports flat behavior contracts without command-specific subsections', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(behaviorContract: "- Resolve the node.\n- Persist it.\n"),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'line' => 17,
            'severity' => 'warning',
            'rule' => 'command_docs.behavior_contract_structure',
            'message' => 'Behavior Contract must use meaningful command-specific level-3 subsections instead of a flat list.',
        ]);
});

it('reports renderer details inside canonical behavior sections', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(behaviorContract: "### Node Creation\n\nThe human renderer prints the created node.\n"),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'line' => 21,
            'severity' => 'error',
            'rule' => 'command_docs.canonical_behavior_boundaries',
            'message' => 'Renderer-specific behavior belongs in `6.1` or `6.2` renderer contracts, not canonical Behavior Contract.',
        ]);
});

it('reports json field paths on public command pages', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\nThe output includes success.data.node.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'line' => 3,
            'severity' => 'error',
            'rule' => 'command_docs.public_page_boundaries',
            'message' => 'Public command pages must not document JSON field paths. Move exact JSON shape to the `6.2` renderer contract and link it from the public page.',
        ]);
});

it('reports command signatures with options before arguments', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\nSupports `--json` output.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
        canonicalContract: validOrbitCanonicalContract(signature: 'orbit node:new [--json] {name}'),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'line' => 10,
            'severity' => 'error',
            'rule' => 'command_docs.signature_argument_order',
            'message' => 'Command signature arguments must come before flags. Required entries come before optional entries inside each group. Shared target flags use `--app`, `--workspace`, then `--node`; `--json` stays last. Expected signature: `orbit node:new {name} [--json]`.',
        ]);
});

it('reports companion options absent from the canonical signature', function (): void {
    writeOrbitCommandDocsFamily($this->docsRoot);
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/1_node/1_node-new/technical/2_node-new_on-control-node.md', "Uses `--force` here.\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/2_node-new_on-control-node.md',
            'line' => 1,
            'severity' => 'error',
            'rule' => 'command_docs.signature_option_consistency',
            'message' => 'Technical companion file mentions --force, but --force is not in the canonical command signature.',
        ]);
});

it('reports json examples that mix success and error envelopes', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        jsonRendererContract: "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n```json\n{\"success\": {}, \"error\": {}}\n```\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md',
            'line' => 11,
            'severity' => 'error',
            'rule' => 'command_docs.json_renderer_examples',
            'message' => 'JSON example 1 must not contain both top-level envelopes: success and error.',
        ]);
});

it('reports json warning entries that omit required warning fields', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        jsonRendererContract: "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n```json\n{\"success\": {\"meta\": {\"warnings\": [{\"code\": \"node.drift\"}]}}}\n```\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md',
            'line' => 11,
            'severity' => 'error',
            'rule' => 'command_docs.json_warning_shape',
            'message' => 'JSON example 1 warning 1 is missing family.',
        ]);
});

it('reports renderer files without primitive references', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        humanRendererContract: "# Human Renderer\n\nRenders a table.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.renderer_primitive_reference');

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.1_node-new_output-render_human.md',
            'severity' => 'error',
            'rule' => 'command_docs.renderer_primitive_reference',
            'message' => 'Renderer files must include a "## Primitive" section that names the primitive (linking to docs/ux/commands/details/, docs/ux/commands/lists/, or docs/ux/commands/progress/) or explicitly declares "None." with a reason.',
        ]);
});

it('accepts the show detail renderer primitive reference', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        humanRendererContract: "# Human Renderer\n\n## Primitive\n\n- Detail: [Show detail](../../../../ux/commands/details/show-detail.md)\n\n## Progress Tree\n\nNo progress tree. The command is a registry read expected to complete below one second.\n\n## Test Mapping\n\n| Path | Coverage |\n| --- | --- |\n| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers show-detail primitive references. |\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.renderer_primitive_reference');

    expect($exitCode)->toBe(0)
        ->and($matchingFindings)->toBe([]);
});

it('reports canonical technical contracts without activity logging declarations', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(activityLogging: ''),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = array_values(array_filter(
        $payload['findings'],
        fn (array $finding): bool => $finding['rule'] === 'command_docs.activity_logging_contract',
    ));

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.activity_logging_contract',
            'message' => 'Canonical technical contracts must include `## Activity Logging` declaring the per-command Loggable contract.',
        ]);
});

it('reports unregistered product error codes in json renderer examples', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        jsonRendererContract: "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n```json\n{\"error\": {\"code\": \"unknown.failure\"}}\n```\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = array_values(array_filter(
        $payload['findings'],
        fn (array $finding): bool => $finding['rule'] === 'command_docs.error_code_registry',
    ));

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md',
            'severity' => 'error',
            'rule' => 'command_docs.error_code_registry',
            'message' => 'Error code `unknown.failure` uses unregistered product prefix `unknown`.',
        ]);
});

it('reports warning codes that do not match the declared doctor family', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        jsonRendererContract: "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n```json\n{\"success\": {\"meta\": {\"warnings\": [{\"code\": \"app.drift\", \"family\": \"node\", \"message\": \"Node drift exists.\", \"next_command\": \"doctor --family=node --fix\"}]}}}\n```\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = array_values(array_filter(
        $payload['findings'],
        fn (array $finding): bool => $finding['rule'] === 'command_docs.doctor_warning_coherence'
            && $finding['severity'] === 'error',
    ));

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md',
            'severity' => 'error',
            'rule' => 'command_docs.doctor_warning_coherence',
            'message' => 'Warning code `app.drift` with family `node` must use singular product prefix `node.`.',
        ]);
});

it('reports stale shared failure vocabulary', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\nThe command may return `missing_input` when required data is absent.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = array_values(array_filter(
        $payload['findings'],
        fn (array $finding): bool => $finding['rule'] === 'command_docs.shared_failure_vocabulary',
    ));

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.shared_failure_vocabulary',
            'message' => 'Stale failure code `missing_input` is documented. Use validation_failed with error.meta.field for missing input.',
        ]);
});

it('reports json next command fields outside recovery metadata', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        jsonRendererContract: "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n```json\n{\"success\": {\"data\": {\"next_command\": \"orbit node:show\"}}}\n```\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = array_values(array_filter(
        $payload['findings'],
        fn (array $finding): bool => $finding['rule'] === 'command_docs.next_action_contract',
    ));

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md',
            'severity' => 'error',
            'rule' => 'command_docs.next_action_contract',
            'message' => 'JSON example 1 uses next_command at success.data.next_command; next_command is only allowed on warning or error recovery metadata.',
        ]);
});

it('reports app docs that use the old php option contract', function (): void {
    writeOrbitDocsFile($this->docsRoot, 'docs/domains/5_app/README.md', "# App Commands\n\nUse `--php` to select the runtime.\n");

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.app_php_version_contract');

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/5_app/README.md',
            'severity' => 'error',
            'rule' => 'command_docs.app_php_version_contract',
            'message' => 'App command docs must use `--php-version`; `--php` is not part of the converted contract.',
        ]);
});

it('reports app write commands without grant authorization contracts', function (): void {
    writeOrbitAppCommandDocsFamily($this->docsRoot);

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.app_node_write_denial');

    expect($exitCode)->toBe(1)
        ->and(array_column($matchingFindings, 'message'))->toContain(
            'App write commands must document the required `app:new` grant permission.',
            'App write commands must use authorization_failed for missing app write grants.',
        );
});

it('reports read-only command contracts that document implicit live checks', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(effects: 'Reads node state.'),
        jsonRendererContract: "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n```json\n{\"success\": {\"data\": {\"checks\": []}}}\n```\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.read_command_no_live_probe');

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md',
            'severity' => 'error',
            'rule' => 'command_docs.read_command_no_live_probe',
            'message' => 'Read commands must not document a checks JSON field unless live inspection is explicit.',
        ]);
});

it('reports drift issue codes that still use orphaned suffixes', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\nThe command may report `node.peer_orphaned` drift.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.drift_issue_suffix');

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.drift_issue_suffix',
            'message' => 'Drift issue code `node.peer_orphaned` must use `_extra` suffix (`node.peer_extra`), matching DriftKind::Extra.',
        ]);
});

it('reports canonical failure semantics that restate common failures', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(failureSemantics: "| Failure | Meaning |\n| --- | --- |\n| Validation failed | Input was invalid. |\n"),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.common_failure_not_restated');

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'severity' => 'error',
            'rule' => 'command_docs.common_failure_not_restated',
            'message' => "Failure Semantics restates the canonical Common Failures row 'Validation failed'. Remove it; document only command-specific failures.",
        ]);
});

it('reports human renderer files without progress tree sections', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        humanRendererContract: "# Human Renderer\n\n## Primitive\n\nNone. No human renderer primitive.\n\n## Test Mapping\n\n| Path | Coverage |\n| --- | --- |\n| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers human renderer documentation mapping for node commands. |\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'contracts',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.human_progress_tree');

    expect($exitCode)->toBe(1)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/6.1_node-new_output-render_human.md',
            'severity' => 'error',
            'rule' => 'command_docs.human_progress_tree',
            'message' => 'Human renderer files must include "## Progress Tree".',
        ]);
});

it('reports reader-facing sentences above the complexity threshold as warnings', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\nThis sentence deliberately contains enough plain prose words to exceed the calibrated reader facing threshold because it keeps adding condition actor action result context and operational detail long after the useful point has already been made for the reader today during repeated operational reviews.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'complexity',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['warnings'])->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'line' => 3,
            'severity' => 'warning',
            'rule' => 'librarian.document_complexity',
        ])
        ->and($payload['findings'][0]['message'])->toContain('Sentence has');
});

it('reports command public pages that read as specs instead of reader guidance', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:new`\n\n[Technical](technical/1_node-new.md)\n\n## Usage\n\nThe command prepares the requested node intent and stores the selected target state for later reconciliation by Orbit.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'prose',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.reader_address');

    expect($exitCode)->toBe(0)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'line' => 5,
            'severity' => 'warning',
            'rule' => 'command_docs.reader_address',
            'message' => 'Section "Usage" has no `you`/`your` and no imperative-led sentence. Address the reader directly so the section reads as guidance, not as a spec.',
        ]);
});

it('reports overly complex command contracts', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        canonicalContract: validOrbitCanonicalContract(behaviorContract: collect(range(1, 25))
            ->map(fn (int $index): string => "- Performs behavior step {$index}.\n")
            ->implode('')),
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'complexity',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $matchingFindings = findingsForRule($payload, 'command_docs.command_contract_complexity');

    expect($exitCode)->toBe(0)
        ->and($matchingFindings[0] ?? null)->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/technical/1_node-new.md',
            'severity' => 'warning',
            'rule' => 'command_docs.command_contract_complexity',
        ])
        ->and($matchingFindings[0]['message'])->toContain('Command contract complexity score is');
});

it('reports invented compound noun stacks as warnings', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:update`\n\nThe WG-served DNS layer aligned is refreshed after updates.\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains/1_node/1_node-new/node-new.md',
        '--group' => 'prose',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['warnings'])->toBe(1)
        ->and($payload['findings'][0])->toMatchArray([
            'path' => 'docs/domains/1_node/1_node-new/node-new.md',
            'line' => 3,
            'severity' => 'warning',
            'rule' => 'librarian.compound_noun_stack',
            'message' => 'Compound noun phrase "WG-served DNS layer aligned" stacks 3 modifiers before the head noun. Decompose into a sentence ("X that is Y by Z") instead.',
        ]);
});

it('opts into package prose hygiene rules through Librarian config', function (): void {
    writeOrbitCommandDocsFamily(
        $this->docsRoot,
        publicCommandPage: "# `orbit node:update`\n\nUse this as needed.\n\n## Ownership\n\n| Owner | Scope |\n| --- | --- |\n| Node | State |\n",
    );

    $exitCode = Artisan::call('librarian:lint', [
        '--format' => 'agent',
        '--path' => 'docs/domains',
        '--group' => 'prose',
    ]);
    $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and(array_column($payload['findings'], 'rule'))->toContain(
            'librarian.requirement_smell',
            'librarian.section_opener_prose',
        );
});

function makeOrbitLibrarianDocsFixture(): string
{
    $path = sys_get_temp_dir().'/orbit-librarian-'.bin2hex(random_bytes(6));

    mkdir($path, 0777, true);
    writeOrbitDocsFile($path, 'config/librarian-command-docs/state_families.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'node' => [\n        'singular' => 'node',\n        'doctor_doc' => 'docs/domains/1_node/node-doctor.md',\n    ],\n];\n");
    writeOrbitDocsFile($path, 'config/librarian-command-docs/error_codes.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'enforced_families' => [\n        'node',\n    ],\n    'shared' => [\n        'validation_failed',\n    ],\n    'products' => [\n        'node' => [\n            'not_found',\n        ],\n    ],\n];\n");
    writeOrbitDocsFile($path, 'config/librarian-command-docs/warning_codes.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n");

    return $path;
}

function deleteOrbitLibrarianDocsFixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}

function writeOrbitCommandDocsFamily(
    string $root,
    bool $validCommandDirectory = true,
    string $publicCommandPage = "# `orbit node:new`\n\n[Technical](technical/1_node-new.md)\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n",
    ?string $canonicalContract = null,
    string $humanRendererContract = "# Human Renderer\n\n## Primitive\n\nNone. No human renderer primitive.\n\n## Progress Tree\n\n```text\n┌ Creating Node\n○ Resolve target\n○ Create node intent\n└ Working...\n```\n\n## Test Mapping\n\n| Path | Coverage |\n| --- | --- |\n| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers human renderer documentation mapping for node commands. |\n",
    string $jsonRendererContract = "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n## Test Mapping\n\n| Path | Coverage |\n| --- | --- |\n| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers json renderer documentation mapping for node commands. |\n",
): void {
    $canonicalContract ??= validOrbitCanonicalContract();

    writeOrbitDocsFile($root, 'docs/domains/1_node/README.md', "# Node Commands\n");
    writeOrbitDocsFile($root, 'docs/domains/1_node/node.md', "# Node\n\n## Purpose\n\nNode command contracts describe how Orbit manages node identity, roles, relationships, and node-local integration.\n\n## Responsibilities\n\nNode docs own the node command family and its node-specific behavior contracts.\n\n## Boundaries\n\nShared command UX and cross-family behavior stay in their owning docs.\n");
    writeOrbitDocsFile($root, 'docs/domains/1_node/node-doctor.md', validOrbitNodeDoctorContract());
    writeOrbitDocsFile($root, 'docs/domains/1_node/1_node-new/node-new.md', $publicCommandPage);

    if (! $validCommandDirectory) {
        return;
    }

    writeOrbitDocsFile($root, 'docs/domains/1_node/1_node-new/technical/1_node-new.md', $canonicalContract);
    writeOrbitDocsFile($root, 'docs/domains/1_node/1_node-new/technical/6.1_node-new_output-render_human.md', $humanRendererContract);
    writeOrbitDocsFile($root, 'docs/domains/1_node/1_node-new/technical/6.2_node-new_output-render_json.md', $jsonRendererContract);
}

function writeOrbitAppCommandDocsFamily(string $root): void
{
    writeOrbitDocsFile($root, 'docs/domains/5_app/README.md', "# App Commands\n");
    writeOrbitDocsFile($root, 'docs/domains/5_app/app.md', "# App\n\n## Purpose\n\nApp command contracts describe how Orbit manages application intent and runtime ownership.\n\n## Responsibilities\n\nApp docs own the app command family and app-specific behavior contracts.\n\n## Boundaries\n\nShared command UX and cross-family behavior stay in their owning docs.\n");
    writeOrbitDocsFile($root, 'docs/domains/5_app/1_app-new/app-new.md', "# `orbit app:new`\n\n[Technical](technical/1_app-new.md)\n\n## Usage\n\nUse it.\n\n## Arguments and options\n\nNone.\n");
    writeOrbitDocsFile($root, 'docs/domains/5_app/1_app-new/technical/1_app-new.md', "# Technical Contract: `orbit app:new`\n\n"
        ."**Owner:** App domain.\n"
        ."**Effects:** Writes app intent.\n"
        ."**Prerequisites:** Gateway access.\n\n"
        ."## Signature\n\n"
        ."```text\norbit app:new\n```\n\n"
        ."## Input Contract\n\n"
        ."[Invocation Model](../../README.md#invocation-model)\n\n"
        ."## Behavior Contract\n\n"
        ."### App Creation\n\nCreates the app intent.\n\n"
        ."## Failure Semantics\n\n"
        ."Uses shared failure semantics.\n\n"
        ."## Doctor Relationship\n\n"
        ."References app-doctor.md for drift detection.\n\n"
        ."## Activity Logging\n\n"
        ."This command does not emit activity events.\n\n"
        ."## Test Mapping\n\n"
        ."| Path | Coverage |\n"
        ."| --- | --- |\n"
        ."| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers app command contracts. |\n");
    writeOrbitDocsFile($root, 'docs/domains/5_app/1_app-new/technical/6.1_app-new_output-render_human.md', "# Human Renderer\n\n## Primitive\n\nNone. No human renderer primitive.\n\n## Progress Tree\n\n```text\n┌ Creating App\n○ Resolve target\n○ Create app intent\n└ Working...\n```\n\n## Test Mapping\n\n| Path | Coverage |\n| --- | --- |\n| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers app human renderer mapping. |\n");
    writeOrbitDocsFile($root, 'docs/domains/5_app/1_app-new/technical/6.2_app-new_output-render_json.md', "# JSON Renderer\n\n## Primitive\n\nNone. JSON renderer.\n\n## Envelope\n\nUses [the shared JSON Envelope](../../../README.md#json-envelope) for success and error responses.\n\n## Test Mapping\n\n| Path | Coverage |\n| --- | --- |\n| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers app json renderer mapping. |\n");
}

function validOrbitCanonicalContract(
    string $signature = 'orbit node:new',
    string $effects = 'Writes node intent.',
    string $inputContract = "[Invocation Model](../../README.md#invocation-model)\n",
    string $behaviorContract = "### Node Creation\n\nCreates the node intent.\n",
    string $failureSemantics = "Uses shared failure semantics.\n",
    string $doctorRelationship = "References [node-doctor.md](../../node-doctor.md) for drift detection.\n",
    string $activityLogging = "This command does not emit activity events.\n",
    string $extra = '',
): string {
    return "# Technical Contract: `orbit node:new`\n\n"
        ."**Owner:** Node domain.\n"
        ."**Effects:** {$effects}\n"
        ."**Prerequisites:** Gateway access.\n\n"
        ."## Signature\n\n"
        ."```text\n{$signature}\n```\n\n"
        ."## Input Contract\n\n"
        ."{$inputContract}\n"
        ."## Behavior Contract\n\n"
        ."{$behaviorContract}\n"
        ."## Failure Semantics\n\n"
        ."{$failureSemantics}\n"
        ."## Doctor Relationship\n\n"
        ."{$doctorRelationship}\n"
        .($activityLogging === '' ? '' : "## Activity Logging\n\n{$activityLogging}\n")
        ."## Test Mapping\n\n"
        ."| Path | Coverage |\n"
        ."| --- | --- |\n"
        ."| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers canonical technical contract mapping for node commands. |\n"
        .$extra;
}

function validOrbitNodeDoctorContract(): string
{
    return "# Node Doctor\n\n"
        ."## Node Probe Layers\n\n"
        ."Probe facts are documented here.\n\n"
        ."## Node Issue Codes\n\n"
        ."| Code | Detected when |\n"
        ."| --- | --- |\n"
        ."| `node.drift` | Node drift exists. |\n\n"
        ."## Node Fix Map\n\n"
        ."| Code | Fix behavior |\n"
        ."| --- | --- |\n"
        ."| `node.drift` | Restore node intent. |\n\n"
        ."## Node Adopt Map\n\n"
        ."| Code | Adopt behavior |\n"
        ."| --- | --- |\n"
        ."| `node.drift` | Adopt node intent. |\n\n"
        ."## Test Mapping\n\n"
        ."| Path | Coverage |\n"
        ."| --- | --- |\n"
        ."| `tests/Feature/Librarian/OrbitCommandDocsRulesTest.php` | Covers node doctor relationship mapping for node commands. |\n";
}

function writeOrbitDocsFile(string $root, string $path, string $contents): void
{
    $fullPath = "{$root}/{$path}";

    if (! is_dir(dirname($fullPath))) {
        mkdir(dirname($fullPath), 0777, true);
    }

    file_put_contents($fullPath, $contents);
}

/**
 * @param  array{findings?: list<array{path: string, line?: int|null, severity: string, rule: string, message: string}>}  $payload
 * @return list<array{path: string, line?: int|null, severity: string, rule: string, message: string}>
 */
function findingsForRule(array $payload, string $rule): array
{
    return array_values(array_filter(
        $payload['findings'] ?? [],
        fn (array $finding): bool => $finding['rule'] === $rule,
    ));
}
