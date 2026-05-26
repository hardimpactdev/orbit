<?php

declare(strict_types=1);

use App\Docs\Librarian\Rules\ActivityLoggingContractRule;
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
use HardImpact\Librarian\Linting\Rules\BulletComplexityRule;
use HardImpact\Librarian\Linting\Rules\CompoundNounStackRule;
use HardImpact\Librarian\Linting\Rules\DocumentComplexityRule;
use HardImpact\Librarian\Linting\Rules\LongSectionStructureRule;
use HardImpact\Librarian\Linting\Rules\RequirementSmellRule;
use HardImpact\Librarian\Linting\Rules\SectionOpenerProseRule;
use HardImpact\Librarian\Linting\Rules\SentenceCaseHeadingRule;
use HardImpact\Librarian\Linting\Rules\TableProseComplexityRule;

return [
    'path' => base_path('docs'),

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
    ],
];
