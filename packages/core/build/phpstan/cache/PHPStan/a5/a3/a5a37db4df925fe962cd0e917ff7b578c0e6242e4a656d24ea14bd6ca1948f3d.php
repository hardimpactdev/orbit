<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Models/Environment.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\Environment
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-b0bdd508f5deae2ee22a295f53c84f78014234e122e1cf1ef7b3958fbb5217f1',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Models/Environment.php',
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
    'docComment' => '/**
 * @property int $id
 * @property string $name
 * @property string $host
 * @property string $user
 * @property int $port
 * @property bool $is_local
 * @property bool $is_active
 * @property bool $external_access
 * @property string|null $external_host
 * @property bool $is_default
 * @property string|null $tld
 * @property string|null $editor_scheme
 * @property string|null $cli_version
 * @property string|null $cli_path
 * @property \\Carbon\\Carbon|null $cli_checked_at
 * @property string|null $orchestrator_url
 * @property array|null $metadata
 * @property \\Carbon\\Carbon|null $last_connected_at
 * @property string $status
 * @property array|null $provisioning_log
 * @property string|null $provisioning_error
 * @property int|null $provisioning_step
 * @property int|null $provisioning_total_steps
 * @property \\Carbon\\Carbon $created_at
 * @property \\Carbon\\Carbon $updated_at
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 37,
    'endLine' => 187,
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
            'startLine' => 41,
            'endLine' => 41,
            'startTokenPos' => 48,
            'startFilePos' => 1154,
            'endTokenPos' => 48,
            'endFilePos' => 1167,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 41,
        'endLine' => 41,
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
            'startLine' => 43,
            'endLine' => 43,
            'startTokenPos' => 57,
            'startFilePos' => 1197,
            'endTokenPos' => 57,
            'endFilePos' => 1204,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 43,
        'endLine' => 43,
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
            'startLine' => 45,
            'endLine' => 45,
            'startTokenPos' => 66,
            'startFilePos' => 1233,
            'endTokenPos' => 66,
            'endFilePos' => 1239,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 45,
        'endLine' => 45,
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
            'startLine' => 47,
            'endLine' => 47,
            'startTokenPos' => 75,
            'startFilePos' => 1266,
            'endTokenPos' => 75,
            'endFilePos' => 1279,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 47,
        'endLine' => 47,
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
            'startLine' => 49,
            'endLine' => 71,
            'startTokenPos' => 84,
            'startFilePos' => 1309,
            'endTokenPos' => 149,
            'endFilePos' => 1793,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 49,
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
            'startLine' => 73,
            'endLine' => 82,
            'startTokenPos' => 158,
            'startFilePos' => 1820,
            'endTokenPos' => 216,
            'endFilePos' => 2121,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 73,
        'endLine' => 82,
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
            'startLine' => 84,
            'endLine' => 86,
            'startTokenPos' => 225,
            'startFilePos' => 2149,
            'endTokenPos' => 230,
            'endFilePos' => 2183,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 84,
        'endLine' => 86,
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
        'startLine' => 88,
        'endLine' => 93,
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
            'startLine' => 95,
            'endLine' => 95,
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
                'startLine' => 95,
                'endLine' => 95,
                'startTokenPos' => 304,
                'startFilePos' => 2459,
                'endTokenPos' => 304,
                'endFilePos' => 2462,
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
            'startLine' => 95,
            'endLine' => 95,
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
        'startLine' => 95,
        'endLine' => 102,
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
        'startLine' => 104,
        'endLine' => 107,
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
        'startLine' => 109,
        'endLine' => 112,
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
        'startLine' => 114,
        'endLine' => 117,
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
        'startLine' => 119,
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
        'startLine' => 130,
        'endLine' => 133,
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
        'startLine' => 135,
        'endLine' => 138,
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
        'startLine' => 144,
        'endLine' => 147,
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
        'startLine' => 153,
        'endLine' => 156,
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
        'startLine' => 162,
        'endLine' => 171,
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
        'startLine' => 176,
        'endLine' => 186,
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