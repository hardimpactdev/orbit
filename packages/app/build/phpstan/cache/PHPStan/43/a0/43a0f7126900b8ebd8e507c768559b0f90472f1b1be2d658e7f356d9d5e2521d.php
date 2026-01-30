<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../hardimpactdev/orbit-core/src/Models/Environment.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\Environment
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-5c05a872d6b5935599a531c5528b4c7ff8dc4853f7847dd1add99e237b77326b-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../hardimpactdev/orbit-core/src/Models/Environment.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Models',
    'name' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
    'shortName' => 'Environment',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 9,
    'endLine' => 164,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Database\\Eloquent\\Model',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory',
    ),
    'immediateConstants' => 
    array (
      'STATUS_PROVISIONING' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'name' => 'STATUS_PROVISIONING',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'provisioning\'',
          'attributes' => 
          array (
            'startLine' => 13,
            'endLine' => 13,
            'startTokenPos' => 43,
            'startFilePos' => 283,
            'endTokenPos' => 43,
            'endFilePos' => 296,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 13,
        'endLine' => 13,
        'startColumn' => 5,
        'endColumn' => 47,
      ),
      'STATUS_ACTIVE' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'name' => 'STATUS_ACTIVE',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'active\'',
          'attributes' => 
          array (
            'startLine' => 15,
            'endLine' => 15,
            'startTokenPos' => 52,
            'startFilePos' => 326,
            'endTokenPos' => 52,
            'endFilePos' => 333,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 15,
        'endLine' => 15,
        'startColumn' => 5,
        'endColumn' => 35,
      ),
      'STATUS_ERROR' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'name' => 'STATUS_ERROR',
        'modifiers' => 1,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'error\'',
          'attributes' => 
          array (
            'startLine' => 17,
            'endLine' => 17,
            'startTokenPos' => 61,
            'startFilePos' => 362,
            'endTokenPos' => 61,
            'endFilePos' => 368,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 5,
        'endColumn' => 33,
      ),
    ),
    'immediateProperties' => 
    array (
      'table' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'name' => 'table',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '\'environments\'',
          'attributes' => 
          array (
            'startLine' => 19,
            'endLine' => 19,
            'startTokenPos' => 70,
            'startFilePos' => 395,
            'endTokenPos' => 70,
            'endFilePos' => 408,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 38,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'fillable' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'name' => 'fillable',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'name\', \'host\', \'user\', \'port\', \'is_local\', \'is_active\', \'external_access\', \'external_host\', \'is_default\', \'tld\', \'editor_scheme\', \'cli_version\', \'cli_path\', \'cli_checked_at\', \'metadata\', \'last_connected_at\', \'status\', \'provisioning_log\', \'provisioning_error\', \'provisioning_step\', \'provisioning_total_steps\']',
          'attributes' => 
          array (
            'startLine' => 21,
            'endLine' => 43,
            'startTokenPos' => 79,
            'startFilePos' => 438,
            'endTokenPos' => 144,
            'endFilePos' => 922,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 21,
        'endLine' => 43,
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'name' => 'casts',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'is_local\' => \'boolean\', \'is_active\' => \'boolean\', \'external_access\' => \'boolean\', \'is_default\' => \'boolean\', \'metadata\' => \'array\', \'last_connected_at\' => \'datetime\', \'cli_checked_at\' => \'datetime\', \'provisioning_log\' => \'array\']',
          'attributes' => 
          array (
            'startLine' => 45,
            'endLine' => 54,
            'startTokenPos' => 153,
            'startFilePos' => 949,
            'endTokenPos' => 211,
            'endFilePos' => 1250,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 45,
        'endLine' => 54,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'hidden' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'name' => 'hidden',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'provisioning_log\']',
          'attributes' => 
          array (
            'startLine' => 56,
            'endLine' => 58,
            'startTokenPos' => 220,
            'startFilePos' => 1278,
            'endTokenPos' => 225,
            'endFilePos' => 1312,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 56,
        'endLine' => 58,
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
      'hasCliCache' => 
      array (
        'name' => 'hasCliCache',
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
        'endLine' => 65,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
      'updateCliCache' => 
      array (
        'name' => 'updateCliCache',
        'parameters' => 
        array (
          'version' => 
          array (
            'name' => 'version',
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
            'startLine' => 67,
            'endLine' => 67,
            'startColumn' => 36,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'path' => 
          array (
            'name' => 'path',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 67,
                'endLine' => 67,
                'startTokenPos' => 299,
                'startFilePos' => 1588,
                'endTokenPos' => 299,
                'endFilePos' => 1591,
              ),
            ),
            'type' => 
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
                      'name' => 'string',
                      'isIdentifier' => true,
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 67,
            'endLine' => 67,
            'startColumn' => 53,
            'endColumn' => 72,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 67,
        'endLine' => 74,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
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
        'startLine' => 81,
        'endLine' => 84,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
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
        'startLine' => 86,
        'endLine' => 89,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
      'getSshConnectionString' => 
      array (
        'name' => 'getSshConnectionString',
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
        'startLine' => 91,
        'endLine' => 100,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
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
        'startLine' => 102,
        'endLine' => 105,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
      'getLocal' => 
      array (
        'name' => 'getLocal',
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
        'startLine' => 107,
        'endLine' => 110,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
      'getActive' => 
      array (
        'name' => 'getActive',
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
        'docComment' => '/**
 * Get the currently active environment.
 * Falls back to local environment if none is active.
 */',
        'startLine' => 116,
        'endLine' => 119,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
      'setAsActive' => 
      array (
        'name' => 'setAsActive',
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
        'docComment' => '/**
 * Set this environment as the active one.
 * Deactivates all other environments.
 */',
        'startLine' => 125,
        'endLine' => 128,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
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
        'startLine' => 130,
        'endLine' => 133,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
      'getEditor' => 
      array (
        'name' => 'getEditor',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the editor configuration for this environment.
 * Falls back to global setting if not set.
 */',
        'startLine' => 139,
        'endLine' => 148,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'aliasName' => NULL,
      ),
      'getEditorOptions' => 
      array (
        'name' => 'getEditorOptions',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get available editor options.
 */',
        'startLine' => 153,
        'endLine' => 163,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
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