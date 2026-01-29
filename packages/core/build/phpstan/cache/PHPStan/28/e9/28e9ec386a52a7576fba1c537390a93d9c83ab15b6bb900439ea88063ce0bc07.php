<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Models/Project.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\Project
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-0eb4a265b82d22cd96299fcb3b669565c6de4f9bcee5c92c9a2e10129d70a600',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Models/Project.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Models',
    'name' => 'HardImpact\\Orbit\\Core\\Models\\Project',
    'shortName' => 'Project',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @property int $id
 * @property int|null $environment_id
 * @property string $name
 * @property string|null $display_name
 * @property string $slug
 * @property string|null $path
 * @property string|null $php_version
 * @property string|null $github_repo
 * @property string|null $project_type
 * @property bool $has_public_folder
 * @property string|null $domain
 * @property string|null $url
 * @property string|null $status
 * @property string|null $error_message
 * @property string|null $job_id
 * @property \\Illuminate\\Support\\Carbon|null $created_at
 * @property \\Illuminate\\Support\\Carbon|null $updated_at
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 30,
    'endLine' => 106,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Database\\Eloquent\\Model',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
      'STATUS_QUEUED' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_QUEUED',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'queued\'',
          'attributes' => 
          array (
            'startLine' => 36,
            'endLine' => 36,
            'startTokenPos' => 68,
            'startFilePos' => 983,
            'endTokenPos' => 68,
            'endFilePos' => 990,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 36,
        'endLine' => 36,
        'startColumn' => 5,
        'endColumn' => 42,
      ),
      'STATUS_CREATING_REPO' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_CREATING_REPO',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'creating_repo\'',
          'attributes' => 
          array (
            'startLine' => 38,
            'endLine' => 38,
            'startTokenPos' => 79,
            'startFilePos' => 1034,
            'endTokenPos' => 79,
            'endFilePos' => 1048,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 38,
        'endLine' => 38,
        'startColumn' => 5,
        'endColumn' => 56,
      ),
      'STATUS_CLONING' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_CLONING',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'cloning\'',
          'attributes' => 
          array (
            'startLine' => 40,
            'endLine' => 40,
            'startTokenPos' => 90,
            'startFilePos' => 1086,
            'endTokenPos' => 90,
            'endFilePos' => 1094,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 40,
        'endLine' => 40,
        'startColumn' => 5,
        'endColumn' => 44,
      ),
      'STATUS_SETTING_UP' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_SETTING_UP',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'setting_up\'',
          'attributes' => 
          array (
            'startLine' => 42,
            'endLine' => 42,
            'startTokenPos' => 101,
            'startFilePos' => 1135,
            'endTokenPos' => 101,
            'endFilePos' => 1146,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 42,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 50,
      ),
      'STATUS_INSTALLING_COMPOSER' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_INSTALLING_COMPOSER',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'installing_composer\'',
          'attributes' => 
          array (
            'startLine' => 44,
            'endLine' => 44,
            'startTokenPos' => 112,
            'startFilePos' => 1196,
            'endTokenPos' => 112,
            'endFilePos' => 1216,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 44,
        'endLine' => 44,
        'startColumn' => 5,
        'endColumn' => 68,
      ),
      'STATUS_INSTALLING_NPM' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_INSTALLING_NPM',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'installing_npm\'',
          'attributes' => 
          array (
            'startLine' => 46,
            'endLine' => 46,
            'startTokenPos' => 123,
            'startFilePos' => 1261,
            'endTokenPos' => 123,
            'endFilePos' => 1276,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 46,
        'endLine' => 46,
        'startColumn' => 5,
        'endColumn' => 58,
      ),
      'STATUS_BUILDING' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_BUILDING',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'building\'',
          'attributes' => 
          array (
            'startLine' => 48,
            'endLine' => 48,
            'startTokenPos' => 134,
            'startFilePos' => 1315,
            'endTokenPos' => 134,
            'endFilePos' => 1324,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 48,
        'endLine' => 48,
        'startColumn' => 5,
        'endColumn' => 46,
      ),
      'STATUS_FINALIZING' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_FINALIZING',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'finalizing\'',
          'attributes' => 
          array (
            'startLine' => 50,
            'endLine' => 50,
            'startTokenPos' => 145,
            'startFilePos' => 1365,
            'endTokenPos' => 145,
            'endFilePos' => 1376,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 50,
        'endLine' => 50,
        'startColumn' => 5,
        'endColumn' => 50,
      ),
      'STATUS_READY' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_READY',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'ready\'',
          'attributes' => 
          array (
            'startLine' => 52,
            'endLine' => 52,
            'startTokenPos' => 156,
            'startFilePos' => 1412,
            'endTokenPos' => 156,
            'endFilePos' => 1418,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 52,
        'endLine' => 52,
        'startColumn' => 5,
        'endColumn' => 40,
      ),
      'STATUS_FAILED' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'STATUS_FAILED',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'failed\'',
          'attributes' => 
          array (
            'startLine' => 54,
            'endLine' => 54,
            'startTokenPos' => 167,
            'startFilePos' => 1455,
            'endTokenPos' => 167,
            'endFilePos' => 1462,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 54,
        'endLine' => 54,
        'startColumn' => 5,
        'endColumn' => 42,
      ),
    ),
    'immediateProperties' => 
    array (
      'casts' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'casts',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'has_public_folder\' => \'boolean\']',
          'attributes' => 
          array (
            'startLine' => 32,
            'endLine' => 34,
            'startTokenPos' => 48,
            'startFilePos' => 898,
            'endTokenPos' => 57,
            'endFilePos' => 946,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 32,
        'endLine' => 34,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'fillable' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'name' => 'fillable',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'environment_id\', \'name\', \'display_name\', \'slug\', \'path\', \'php_version\', \'github_repo\', \'project_type\', \'has_public_folder\', \'domain\', \'url\', \'status\', \'error_message\', \'job_id\']',
          'attributes' => 
          array (
            'startLine' => 56,
            'endLine' => 71,
            'startTokenPos' => 176,
            'startFilePos' => 1492,
            'endTokenPos' => 220,
            'endFilePos' => 1789,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 56,
        'endLine' => 71,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
    ),
    'immediateMethods' => 
    array (
      'deployments' => 
      array (
        'name' => 'deployments',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Illuminate\\Database\\Eloquent\\Relations\\HasMany',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 73,
        'endLine' => 76,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'aliasName' => NULL,
      ),
      'environment' => 
      array (
        'name' => 'environment',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Illuminate\\Database\\Eloquent\\Relations\\BelongsTo',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 78,
        'endLine' => 81,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'aliasName' => NULL,
      ),
      'isProvisioning' => 
      array (
        'name' => 'isProvisioning',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 83,
        'endLine' => 95,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'aliasName' => NULL,
      ),
      'isReady' => 
      array (
        'name' => 'isReady',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 97,
        'endLine' => 100,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'aliasName' => NULL,
      ),
      'isFailed' => 
      array (
        'name' => 'isFailed',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 102,
        'endLine' => 105,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'aliasName' => NULL,
      ),
    ),
    'traitsData' => 
    array (
      'aliases' => 
      array (
      ),
      'modifiers' => 
      array (
      ),
      'precedences' => 
      array (
      ),
      'hashes' => 
      array (
      ),
    ),
  ),
));