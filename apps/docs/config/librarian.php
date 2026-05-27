<?php

declare(strict_types=1);

use App\Librarian\Rules\ActivityLoggingContractRule;
use App\Librarian\Rules\AppPhpVersionContractRule;
use App\Librarian\Rules\BehaviorContractStructureRule;
use App\Librarian\Rules\CanonicalBehaviorBoundaryRule;
use App\Librarian\Rules\CanonicalTechnicalContractRule;
use App\Librarian\Rules\CommandContractComplexityRule;
use App\Librarian\Rules\CommandDirectoryStructureRule;
use App\Librarian\Rules\CommandPageStructureRule;
use App\Librarian\Rules\CommonFailureNotRestatedRule;
use App\Librarian\Rules\CompoundCommandPrefixRule;
use App\Librarian\Rules\ConceptIndexRule;
use App\Librarian\Rules\ConvertedFamilyStructureRule;
use App\Librarian\Rules\DestructiveConsentRule;
use App\Librarian\Rules\DoctorIssueCodePrefixRule;
use App\Librarian\Rules\DoctorRelationshipReferenceRule;
use App\Librarian\Rules\DoctorWarningCoherenceRule;
use App\Librarian\Rules\DriftIssueSuffixRule;
use App\Librarian\Rules\ErrorCodeRegistryRule;
use App\Librarian\Rules\ExitStatusPolicyRule;
use App\Librarian\Rules\FamilyCommandPrefixRule;
use App\Librarian\Rules\HumanRendererProgressTreeRule;
use App\Librarian\Rules\InputModeContractRule;
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
use App\Librarian\Rules\ReadCommandNoLiveProbeRule;
use App\Librarian\Rules\ReaderAddressRule;
use App\Librarian\Rules\RendererPrimitiveReferenceRule;
use App\Librarian\Rules\RoleCompanionCoverageRule;
use App\Librarian\Rules\SharedFailureVocabularyRule;
use App\Librarian\Rules\SignatureArgumentOrderRule;
use App\Librarian\Rules\SignatureOptionConsistencyRule;
use App\Librarian\Rules\TechnicalSlotSemanticsRule;
use App\Librarian\Rules\TechnicalTestMappingRule;
use App\Librarian\Rules\TestMappingFormatRule;
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
        NoLegacyNarrativeRule::class,
    ],
];
