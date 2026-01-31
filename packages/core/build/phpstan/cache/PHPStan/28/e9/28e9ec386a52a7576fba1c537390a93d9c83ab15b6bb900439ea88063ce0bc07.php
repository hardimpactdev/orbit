<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Models/Project.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\Project
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-eeef06d046c3cfa96c04beca350ed50a33fc1a14686626e415836bf27c410802',
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
    'startLine' => 29,
    'endLine' => 100,
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
            'startLine' => 35,
            'endLine' => 35,
            'startTokenPos' => 63,
            'startFilePos' => 931,
            'endTokenPos' => 63,
            'endFilePos' => 938,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 35,
        'endLine' => 35,
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
            'startLine' => 37,
            'endLine' => 37,
            'startTokenPos' => 74,
            'startFilePos' => 982,
            'endTokenPos' => 74,
            'endFilePos' => 996,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 37,
        'endLine' => 37,
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
            'startLine' => 39,
            'endLine' => 39,
            'startTokenPos' => 85,
            'startFilePos' => 1034,
            'endTokenPos' => 85,
            'endFilePos' => 1042,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 39,
        'endLine' => 39,
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
            'startLine' => 41,
            'endLine' => 41,
            'startTokenPos' => 96,
            'startFilePos' => 1083,
            'endTokenPos' => 96,
            'endFilePos' => 1094,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 41,
        'endLine' => 41,
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
            'startLine' => 43,
            'endLine' => 43,
            'startTokenPos' => 107,
            'startFilePos' => 1144,
            'endTokenPos' => 107,
            'endFilePos' => 1164,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 43,
        'endLine' => 43,
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
            'startLine' => 45,
            'endLine' => 45,
            'startTokenPos' => 118,
            'startFilePos' => 1209,
            'endTokenPos' => 118,
            'endFilePos' => 1224,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 45,
        'endLine' => 45,
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
            'startLine' => 47,
            'endLine' => 47,
            'startTokenPos' => 129,
            'startFilePos' => 1263,
            'endTokenPos' => 129,
            'endFilePos' => 1272,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 47,
        'endLine' => 47,
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
            'startLine' => 49,
            'endLine' => 49,
            'startTokenPos' => 140,
            'startFilePos' => 1313,
            'endTokenPos' => 140,
            'endFilePos' => 1324,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 49,
        'endLine' => 49,
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
            'startLine' => 51,
            'endLine' => 51,
            'startTokenPos' => 151,
            'startFilePos' => 1360,
            'endTokenPos' => 151,
            'endFilePos' => 1366,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 51,
        'endLine' => 51,
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
            'startLine' => 53,
            'endLine' => 53,
            'startTokenPos' => 162,
            'startFilePos' => 1403,
            'endTokenPos' => 162,
            'endFilePos' => 1410,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 53,
        'endLine' => 53,
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
            'startLine' => 31,
            'endLine' => 33,
            'startTokenPos' => 43,
            'startFilePos' => 846,
            'endTokenPos' => 52,
            'endFilePos' => 894,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 31,
        'endLine' => 33,
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
            'startLine' => 55,
            'endLine' => 70,
            'startTokenPos' => 171,
            'startFilePos' => 1440,
            'endTokenPos' => 215,
            'endFilePos' => 1737,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 55,
        'endLine' => 70,
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
        'startLine' => 72,
        'endLine' => 75,
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
        'startLine' => 77,
        'endLine' => 89,
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
        'startLine' => 91,
        'endLine' => 94,
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
        'startLine' => 96,
        'endLine' => 99,
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