<?php

declare(strict_types=1);

use App\Librarian\Rules\ActivityLoggingContractRule;
use App\Librarian\Rules\AppPhpVersionContractRule;
use App\Librarian\Rules\BannedTermsRule;
use App\Librarian\Rules\BehaviorContractStructureRule;
use App\Librarian\Rules\CanonicalBehaviorBoundaryRule;
use App\Librarian\Rules\CanonicalTechnicalContractRule;
use App\Librarian\Rules\CommandContractComplexityRule;
use App\Librarian\Rules\CommandDirectoryStructureRule;
use App\Librarian\Rules\CommandPageStructureRule;
use App\Librarian\Rules\CommandSurfaceCoverageRule;
use App\Librarian\Rules\CommonFailureNotRestatedRule;
use App\Librarian\Rules\CompoundCommandPrefixRule;
use App\Librarian\Rules\ConceptIndexRule;
use App\Librarian\Rules\ConvertedFamilyStructureRule;
use App\Librarian\Rules\DestructiveConsentRule;
use App\Librarian\Rules\DoctorIssueCodePrefixRule;
use App\Librarian\Rules\DoctorIssueVisibilityPredicateRule;
use App\Librarian\Rules\DoctorRelationshipReferenceRule;
use App\Librarian\Rules\DoctorWarningCoherenceRule;
use App\Librarian\Rules\DriftIssueSuffixRule;
use App\Librarian\Rules\ErrorCodeRegistryRule;
use App\Librarian\Rules\ExitStatusPolicyRule;
use App\Librarian\Rules\FamilyCommandPrefixRule;
use App\Librarian\Rules\HumanRendererProgressTreeRule;
use App\Librarian\Rules\InputModeContractRule;
use App\Librarian\Rules\JsonMetadataShapeRule;
use App\Librarian\Rules\JsonRendererEnvelopeRule;
use App\Librarian\Rules\JsonRendererExampleRule;
use App\Librarian\Rules\JsonWarningShapeRule;
use App\Librarian\Rules\MarkdownLinkIntegrityRule;
use App\Librarian\Rules\NextActionContractRule;
use App\Librarian\Rules\NoCommandAmbiguityFilesRule;
use App\Librarian\Rules\NoLegacyNarrativeRule;
use App\Librarian\Rules\NonStateDomainHandoffRule;
use App\Librarian\Rules\NoPerCommandAuthorizationSectionRule;
use App\Librarian\Rules\ProductCodeNamespaceRule;
use App\Librarian\Rules\PublicCommandPageBoundaryRule;
use App\Librarian\Rules\PublicJsonOptionContractRule;
use App\Librarian\Rules\PublicStreamJsonOptionContractRule;
use App\Librarian\Rules\PublicUsageOptionCoverageRule;
use App\Librarian\Rules\ReadCommandNoLiveProbeRule;
use App\Librarian\Rules\ReaderAddressRule;
use App\Librarian\Rules\RendererPrimitiveReferenceRule;
use App\Librarian\Rules\RoleCompanionCoverageRule;
use App\Librarian\Rules\SharedFailureVocabularyRule;
use App\Librarian\Rules\SignatureArgumentOrderRule;
use App\Librarian\Rules\SignatureLiveSurfaceRule;
use App\Librarian\Rules\SignatureOptionConsistencyRule;
use App\Librarian\Rules\TechnicalCompanionCommandNameRule;
use App\Librarian\Rules\TechnicalSlotSemanticsRule;
use App\Librarian\Rules\TechnicalTestMappingRule;
use App\Librarian\Rules\TestMappingFormatRule;
use App\Librarian\Rules\TestMappingPathExistsRule;
use App\Librarian\Rules\TransitionalSseContractRule;
use App\Librarian\Rules\WorkspaceLifecycleInstanceScopeRule;
use HardImpact\Librarian\Linting\Rules\BulletComplexityRule;
use HardImpact\Librarian\Linting\Rules\CompoundNounStackRule;
use HardImpact\Librarian\Linting\Rules\DocumentComplexityRule;
use HardImpact\Librarian\Linting\Rules\LongSectionStructureRule;
use HardImpact\Librarian\Linting\Rules\RequirementSmellRule;
use HardImpact\Librarian\Linting\Rules\SectionOpenerProseRule;
use HardImpact\Librarian\Linting\Rules\SentenceCaseHeadingRule;
use HardImpact\Librarian\Linting\Rules\TableProseComplexityRule;

return [
    'path' => base_path('content'),

    'generated_docs' => [
        'files' => [
            'README.md',
        ],
        'allowed' => [
            'testing',
            'ux',
            'superpowers',
        ],
    ],

    'rules' => [
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
        PublicStreamJsonOptionContractRule::class,
        PublicUsageOptionCoverageRule::class,
        JsonRendererEnvelopeRule::class,
        ExitStatusPolicyRule::class,
        NoPerCommandAuthorizationSectionRule::class,
        DoctorIssueCodePrefixRule::class,
        DoctorIssueVisibilityPredicateRule::class,
        InputModeContractRule::class,
        DestructiveConsentRule::class,
        JsonRendererExampleRule::class,
        JsonMetadataShapeRule::class,
        JsonWarningShapeRule::class,
        RendererPrimitiveReferenceRule::class,
        CanonicalTechnicalContractRule::class,
        BehaviorContractStructureRule::class,
        CanonicalBehaviorBoundaryRule::class,
        PublicCommandPageBoundaryRule::class,
        SignatureArgumentOrderRule::class,
        SignatureOptionConsistencyRule::class,
        TechnicalCompanionCommandNameRule::class,
        ActivityLoggingContractRule::class,
        ErrorCodeRegistryRule::class,
        DoctorWarningCoherenceRule::class,
        SharedFailureVocabularyRule::class,
        NextActionContractRule::class,
        AppPhpVersionContractRule::class,
        ReadCommandNoLiveProbeRule::class,
        DriftIssueSuffixRule::class,
        CommonFailureNotRestatedRule::class,
        HumanRendererProgressTreeRule::class,
        ConceptIndexRule::class,
        RoleCompanionCoverageRule::class,
        TechnicalTestMappingRule::class,
        TestMappingFormatRule::class,
        TestMappingPathExistsRule::class,
        DoctorRelationshipReferenceRule::class,
        NonStateDomainHandoffRule::class,
        ReaderAddressRule::class,
        CommandContractComplexityRule::class,
        NoLegacyNarrativeRule::class,
        CommandSurfaceCoverageRule::class,
        SignatureLiveSurfaceRule::class,
        TransitionalSseContractRule::class,
        WorkspaceLifecycleInstanceScopeRule::class,
        BannedTermsRule::class,
    ],

    /*
     * Terms retired by a product decision. Mentions anywhere in the product
     * docs fail `command_docs.banned_terms` unless the file is listed in the
     * entry's allow_paths for an intentional product-doc note.
     * Append an entry whenever a decision removes or renames a public
     * concept, in the same change that lands the decision.
     */
    'banned_terms' => [
        [
            'terms' => ['RustFS', 'rustfs'],
            'decision' => '2026-06-13 S3 backend is SeaweedFS',
            'replacement' => 'SeaweedFS / seaweedfs',
            'allow_paths' => [],
        ],
        [
            'terms' => ['app:exec', 'workspace:exec'],
            'decision' => '2026-06-03 Orbit has no command-exec surface',
            'replacement' => 'host `php`/`artisan`/`composer` directly on the app node source path',
            'allow_paths' => ['domains/5_app/README.md'],
        ],
        [
            'terms' => ['orbit:release-candidate:activate'],
            'decision' => '2026-06-22 manifest source selection moved to public CLI commands',
            'replacement' => '`manifest:update` / `manifest:remove`',
            'allow_paths' => [],
        ],
        [
            'terms' => ['process:edit'],
            'decision' => '2026-06-25 process:edit removed; process:update is the sole mutation command (solo todo #1981)',
            'replacement' => '`process:update`',
            'allow_paths' => [],
        ],
        [
            'terms' => ['app:codex'],
            'decision' => '2026-06-28 Codex App registration moved to the codex extension command family',
            'replacement' => '`codex:app`',
            'allow_paths' => ['domains/23_codex/README.md'],
        ],
        [
            'terms' => ['caller-role authorization'],
            'decision' => '2026-07-15 doctor drift is authoritative-state based, not caller-role visibility based',
            'replacement' => 'caller eligibility or explicit permission checks',
            'allow_paths' => [],
        ],
    ],
];
