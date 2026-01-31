<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../hardimpactdev/orbit-core/src/Models/Environment.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\Environment
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-ca4c4f77740d7a7a0e0f27b3c35532aac60f5a2cbf8dcffe7fa7dd885287fcab-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../hardimpactdev/orbit-core/src/Models/Environment.php',
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
    'startLine' => 38,
    'endLine' => 188,
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
            'startLine' => 42,
            'endLine' => 42,
            'startTokenPos' => 53,
            'startFilePos' => 1206,
            'endTokenPos' => 53,
            'endFilePos' => 1219,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 42,
        'endLine' => 42,
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
            'startLine' => 44,
            'endLine' => 44,
            'startTokenPos' => 62,
            'startFilePos' => 1249,
            'endTokenPos' => 62,
            'endFilePos' => 1256,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 44,
        'endLine' => 44,
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
            'startLine' => 46,
            'endLine' => 46,
            'startTokenPos' => 71,
            'startFilePos' => 1285,
            'endTokenPos' => 71,
            'endFilePos' => 1291,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 46,
        'endLine' => 46,
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
            'startLine' => 48,
            'endLine' => 48,
            'startTokenPos' => 80,
            'startFilePos' => 1318,
            'endTokenPos' => 80,
            'endFilePos' => 1331,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 48,
        'endLine' => 48,
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
            'startLine' => 50,
            'endLine' => 72,
            'startTokenPos' => 89,
            'startFilePos' => 1361,
            'endTokenPos' => 154,
            'endFilePos' => 1845,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 50,
        'endLine' => 72,
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
            'startLine' => 74,
            'endLine' => 83,
            'startTokenPos' => 163,
            'startFilePos' => 1872,
            'endTokenPos' => 221,
            'endFilePos' => 2173,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 74,
        'endLine' => 83,
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
            'startLine' => 85,
            'endLine' => 87,
            'startTokenPos' => 230,
            'startFilePos' => 2201,
            'endTokenPos' => 235,
            'endFilePos' => 2235,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 85,
        'endLine' => 87,
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
        'startLine' => 89,
        'endLine' => 94,
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
            'startLine' => 96,
            'endLine' => 96,
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
                'startLine' => 96,
                'endLine' => 96,
                'startTokenPos' => 309,
                'startFilePos' => 2511,
                'endTokenPos' => 309,
                'endFilePos' => 2514,
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
            'startLine' => 96,
            'endLine' => 96,
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
        'startLine' => 96,
        'endLine' => 103,
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
        'startLine' => 105,
        'endLine' => 108,
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
        'startLine' => 110,
        'endLine' => 113,
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
        'startLine' => 115,
        'endLine' => 118,
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
        'startLine' => 120,
        'endLine' => 129,
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
        'startLine' => 131,
        'endLine' => 134,
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
        'startLine' => 136,
        'endLine' => 139,
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
        'startLine' => 145,
        'endLine' => 148,
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
        'startLine' => 154,
        'endLine' => 157,
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
        'startLine' => 163,
        'endLine' => 172,
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
        'startLine' => 177,
        'endLine' => 187,
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