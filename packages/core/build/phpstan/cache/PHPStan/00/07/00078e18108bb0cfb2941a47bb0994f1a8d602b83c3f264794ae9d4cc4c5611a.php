<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Models/UserPreference.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\UserPreference
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-668f013cc12d5469e6e94bd6e664619d0057a7e2a98f682c7fef858e615fa4b8',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Models/UserPreference.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Models',
    'name' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
    'shortName' => 'UserPreference',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @property int $id
 * @property string $key
 * @property array|null $value
 * @property \\Carbon\\Carbon $created_at
 * @property \\Carbon\\Carbon $updated_at
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 16,
    'endLine' => 46,
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'name' => 'fillable',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'key\', \'value\']',
          'attributes' => 
          array (
            'startLine' => 18,
            'endLine' => 21,
            'startTokenPos' => 38,
            'startFilePos' => 343,
            'endTokenPos' => 46,
            'endFilePos' => 381,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 18,
        'endLine' => 21,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'casts' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'name' => 'casts',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'value\' => \'array\']',
          'attributes' => 
          array (
            'startLine' => 23,
            'endLine' => 25,
            'startTokenPos' => 55,
            'startFilePos' => 408,
            'endTokenPos' => 64,
            'endFilePos' => 442,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 23,
        'endLine' => 25,
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
      'getValue' => 
      array (
        'name' => 'getValue',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 27,
            'endLine' => 27,
            'startColumn' => 37,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 27,
                'endLine' => 27,
                'startTokenPos' => 86,
                'startFilePos' => 512,
                'endTokenPos' => 86,
                'endFilePos' => 515,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'mixed',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 27,
            'endLine' => 27,
            'startColumn' => 50,
            'endColumn' => 70,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'mixed',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 27,
        'endLine' => 32,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'aliasName' => NULL,
      ),
      'setValue' => 
      array (
        'name' => 'setValue',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 34,
            'endLine' => 34,
            'startColumn' => 37,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'mixed',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 34,
            'endLine' => 34,
            'startColumn' => 50,
            'endColumn' => 61,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 34,
        'endLine' => 40,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'aliasName' => NULL,
      ),
      'deleteKey' => 
      array (
        'name' => 'deleteKey',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'string',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 42,
            'endLine' => 42,
            'startColumn' => 38,
            'endColumn' => 48,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
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
        'startLine' => 42,
        'endLine' => 45,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\UserPreference',
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