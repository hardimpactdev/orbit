<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Models/Deployment.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\Deployment
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-3c37b38cafe649239d60e0c84ed52aef9d9e183cc1ede46e516b896307f9fcec',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Models/Deployment.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Models',
    'name' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
    'shortName' => 'Deployment',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @property int $id
 * @property int $project_id
 * @property int $environment_id
 * @property string $status
 * @property string|null $local_path
 * @property string|null $url
 * @property string|null $orchestrator_id
 * @property \\Carbon\\Carbon $created_at
 * @property \\Carbon\\Carbon $updated_at
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 21,
    'endLine' => 74,
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
      'STATUS_PENDING' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'name' => 'STATUS_PENDING',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'pending\'',
          'attributes' => 
          array (
            'startLine' => 23,
            'endLine' => 23,
            'startTokenPos' => 43,
            'startFilePos' => 537,
            'endTokenPos' => 43,
            'endFilePos' => 545,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 23,
        'endLine' => 23,
        'startColumn' => 5,
        'endColumn' => 37,
      ),
      'STATUS_DEPLOYING' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'name' => 'STATUS_DEPLOYING',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'deploying\'',
          'attributes' => 
          array (
            'startLine' => 25,
            'endLine' => 25,
            'startTokenPos' => 52,
            'startFilePos' => 578,
            'endTokenPos' => 52,
            'endFilePos' => 588,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 25,
        'endLine' => 25,
        'startColumn' => 5,
        'endColumn' => 41,
      ),
      'STATUS_ACTIVE' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'name' => 'STATUS_ACTIVE',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'active\'',
          'attributes' => 
          array (
            'startLine' => 27,
            'endLine' => 27,
            'startTokenPos' => 61,
            'startFilePos' => 618,
            'endTokenPos' => 61,
            'endFilePos' => 625,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 27,
        'endLine' => 27,
        'startColumn' => 5,
        'endColumn' => 35,
      ),
      'STATUS_ERROR' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'name' => 'STATUS_ERROR',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'error\'',
          'attributes' => 
          array (
            'startLine' => 29,
            'endLine' => 29,
            'startTokenPos' => 70,
            'startFilePos' => 654,
            'endTokenPos' => 70,
            'endFilePos' => 660,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 29,
        'endLine' => 29,
        'startColumn' => 5,
        'endColumn' => 33,
      ),
    ),
    'immediateProperties' => 
    array (
      'fillable' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'name' => 'fillable',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'project_id\', \'environment_id\', \'status\', \'local_path\', \'url\']',
          'attributes' => 
          array (
            'startLine' => 31,
            'endLine' => 37,
            'startTokenPos' => 79,
            'startFilePos' => 690,
            'endTokenPos' => 96,
            'endFilePos' => 799,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 31,
        'endLine' => 37,
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
      'project' => 
      array (
        'name' => 'project',
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
        'startLine' => 39,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
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
        'startLine' => 44,
        'endLine' => 47,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'aliasName' => NULL,
      ),
      'server' => 
      array (
        'name' => 'server',
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
        'docComment' => '/** @deprecated Use environment() instead */',
        'startLine' => 50,
        'endLine' => 53,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'aliasName' => NULL,
      ),
      'isPending' => 
      array (
        'name' => 'isPending',
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
        'startLine' => 55,
        'endLine' => 58,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'aliasName' => NULL,
      ),
      'isDeploying' => 
      array (
        'name' => 'isDeploying',
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
        'startLine' => 60,
        'endLine' => 63,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'aliasName' => NULL,
      ),
      'isActive' => 
      array (
        'name' => 'isActive',
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
        'startLine' => 65,
        'endLine' => 68,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'aliasName' => NULL,
      ),
      'hasError' => 
      array (
        'name' => 'hasError',
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
        'startLine' => 70,
        'endLine' => 73,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Deployment',
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