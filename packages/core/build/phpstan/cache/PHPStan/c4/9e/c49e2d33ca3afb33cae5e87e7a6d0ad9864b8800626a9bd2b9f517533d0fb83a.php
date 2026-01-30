<?php declare(strict_types = 1);

// ftm-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Provision/ProvisionPipeline.php
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v4-2.3.1',
   'data' => 
  array (
    0 => 
    array (
      '65df1e633f65539afefa8423d9c9e486' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => NULL,
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => NULL,
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      'd8dcf692a267f864ec73defb5b02f1c4' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => '__construct',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      'f13478727a22a5b75e633b1cd3497c8a' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'run',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      'c06bf6129ff901c768285edb709a0e92' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'runMinimal',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      'e84cfef86177cf50326d78c93fe78bf5' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'runFull',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      '29da1850c124530caff4fef39b0050fc' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'cloneRepository',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      '690a21b5b2c8fde23247287357f6cbfb' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'createFromTemplate',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      '55495911921f1e8e64700a8048f334ec' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'forkRepository',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      '2c94c3923e505a3638b1cca84e01c354' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'getGitHubService',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
      'dcccf781b8c9f571ed6290978a87708d' => 
      \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
         'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
         'uses' => 
        array (
          'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
          'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
          'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
          'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
          'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
          'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
          'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
          'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
          'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
          'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
          'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
          'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
          'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
          'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
          'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
          'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        ),
         'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
         'functionName' => 'getPostgresConfig',
         'templatePhpDocNodes' => 
        array (
        ),
         'parent' => 
        \PHPStan\Analyser\IntermediaryNameScope::__set_state(array(
           'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision',
           'uses' => 
          array (
            'provisionloggercontract' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
            'provisioncontext' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
            'stepresult' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'buildassets' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\BuildAssets',
            'clonerepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CloneRepository',
            'configureenvironment' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureEnvironment',
            'configuretrustedproxies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ConfigureTrustedProxies',
            'createdatabase' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateDatabase',
            'creategithubrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\CreateGitHubRepository',
            'detectnodepackagemanager' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\DetectNodePackageManager',
            'forkrepository' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\ForkRepository',
            'generateappkey' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\GenerateAppKey',
            'installcomposerdependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallComposerDependencies',
            'installnodedependencies' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\InstallNodeDependencies',
            'runmigrations' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\RunMigrations',
            'setphpversion' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
          ),
           'className' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\ProvisionPipeline',
           'functionName' => NULL,
           'templatePhpDocNodes' => 
          array (
          ),
           'parent' => NULL,
           'typeAliasesMap' => 
          array (
          ),
           'bypassTypeAliases' => false,
           'constUses' => 
          array (
          ),
           'typeAliasClassName' => NULL,
           'traitData' => NULL,
        )),
         'typeAliasesMap' => 
        array (
        ),
         'bypassTypeAliases' => false,
         'constUses' => 
        array (
        ),
         'typeAliasClassName' => NULL,
         'traitData' => NULL,
      )),
    ),
    1 => 
    array (
      '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Provision/ProvisionPipeline.php' => '556f38843a8ffe4c550526689f711e4568f033f36dd95becf423819e8d742a0a',
    ),
  ),
));