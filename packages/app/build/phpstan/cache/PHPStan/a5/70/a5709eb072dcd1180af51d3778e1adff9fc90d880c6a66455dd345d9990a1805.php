<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../hardimpactdev/orbit-core/src/Models/Project.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\Project
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-269aee210c265b56ca148171044ab98e40b2f05721673d1d39517fdf2f0be5c3-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\Project',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../hardimpactdev/orbit-core/src/Models/Project.php',
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
    'startLine' => 28,
    'endLine' => 104,
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
            'startLine' => 34,
            'endLine' => 34,
            'startTokenPos' => 60,
            'startFilePos' => 957,
            'endTokenPos' => 60,
            'endFilePos' => 964,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 34,
        'endLine' => 34,
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
            'startLine' => 36,
            'endLine' => 36,
            'startTokenPos' => 71,
            'startFilePos' => 1008,
            'endTokenPos' => 71,
            'endFilePos' => 1022,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 36,
        'endLine' => 36,
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
            'startLine' => 38,
            'endLine' => 38,
            'startTokenPos' => 82,
            'startFilePos' => 1060,
            'endTokenPos' => 82,
            'endFilePos' => 1068,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 38,
        'endLine' => 38,
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
            'startLine' => 40,
            'endLine' => 40,
            'startTokenPos' => 93,
            'startFilePos' => 1109,
            'endTokenPos' => 93,
            'endFilePos' => 1120,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 40,
        'endLine' => 40,
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
            'startLine' => 42,
            'endLine' => 42,
            'startTokenPos' => 104,
            'startFilePos' => 1170,
            'endTokenPos' => 104,
            'endFilePos' => 1190,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 42,
        'endLine' => 42,
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
            'startLine' => 44,
            'endLine' => 44,
            'startTokenPos' => 115,
            'startFilePos' => 1235,
            'endTokenPos' => 115,
            'endFilePos' => 1250,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 44,
        'endLine' => 44,
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
            'startLine' => 46,
            'endLine' => 46,
            'startTokenPos' => 126,
            'startFilePos' => 1289,
            'endTokenPos' => 126,
            'endFilePos' => 1298,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 46,
        'endLine' => 46,
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
            'startLine' => 48,
            'endLine' => 48,
            'startTokenPos' => 137,
            'startFilePos' => 1339,
            'endTokenPos' => 137,
            'endFilePos' => 1350,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 48,
        'endLine' => 48,
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
            'startLine' => 50,
            'endLine' => 50,
            'startTokenPos' => 148,
            'startFilePos' => 1386,
            'endTokenPos' => 148,
            'endFilePos' => 1392,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 50,
        'endLine' => 50,
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
            'startLine' => 52,
            'endLine' => 52,
            'startTokenPos' => 159,
            'startFilePos' => 1429,
            'endTokenPos' => 159,
            'endFilePos' => 1436,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 52,
        'endLine' => 52,
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
            'startLine' => 30,
            'endLine' => 32,
            'startTokenPos' => 40,
            'startFilePos' => 872,
            'endTokenPos' => 49,
            'endFilePos' => 920,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 30,
        'endLine' => 32,
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
            'startLine' => 54,
            'endLine' => 69,
            'startTokenPos' => 168,
            'startFilePos' => 1466,
            'endTokenPos' => 212,
            'endFilePos' => 1763,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 54,
        'endLine' => 69,
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
        'startLine' => 71,
        'endLine' => 74,
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
        'startLine' => 76,
        'endLine' => 79,
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
        'startLine' => 81,
        'endLine' => 93,
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
        'startLine' => 95,
        'endLine' => 98,
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
        'startLine' => 100,
        'endLine' => 103,
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