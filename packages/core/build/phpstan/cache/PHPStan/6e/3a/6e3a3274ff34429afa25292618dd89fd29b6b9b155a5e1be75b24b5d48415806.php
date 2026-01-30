<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Models/SshKey.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\SshKey
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-e9b96c250982f0611e95bc37133011ec59f2b4fae7e8fb54ce84f99b117ad99a',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Models/SshKey.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Models',
    'name' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
    'shortName' => 'SshKey',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @property int $id
 * @property string $name
 * @property string $public_key
 * @property bool $is_default
 * @property \\Carbon\\Carbon $created_at
 * @property \\Carbon\\Carbon $updated_at
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 17,
    'endLine' => 43,
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
    ),
    'immediateProperties' => 
    array (
      'fillable' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'name' => 'fillable',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'name\', \'public_key\', \'is_default\']',
          'attributes' => 
          array (
            'startLine' => 19,
            'endLine' => 19,
            'startTokenPos' => 38,
            'startFilePos' => 367,
            'endTokenPos' => 46,
            'endFilePos' => 402,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 63,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'casts' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'name' => 'casts',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'is_default\' => \'boolean\']',
          'attributes' => 
          array (
            'startLine' => 21,
            'endLine' => 23,
            'startTokenPos' => 55,
            'startFilePos' => 429,
            'endTokenPos' => 64,
            'endFilePos' => 470,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 21,
        'endLine' => 23,
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
      'getDefault' => 
      array (
        'name' => 'getDefault',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionUnionType',
          'data' => 
          array (
            'types' => 
            array (
              0 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'self',
                  'isIdentifier' => false,
                ),
              ),
              1 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'null',
                  'isIdentifier' => true,
                ),
              ),
            ),
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 25,
        'endLine' => 29,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'aliasName' => NULL,
      ),
      'setAsDefault' => 
      array (
        'name' => 'setAsDefault',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 31,
        'endLine' => 35,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'aliasName' => NULL,
      ),
      'getKeyTypeAttribute' => 
      array (
        'name' => 'getKeyTypeAttribute',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 37,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\SshKey',
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