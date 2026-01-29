<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/OrbitConnector.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Http\Integrations\Orbit\OrbitConnector
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-355006626a325337bc80c315f4ae6e0dbda21e0714797969d899e039b9cd8ff9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/OrbitConnector.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
    'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
    'shortName' => 'OrbitConnector',
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
    'endLine' => 82,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Saloon\\Http\\Connector',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Saloon\\Traits\\Plugins\\AcceptsJson',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'testingMockClient' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'name' => 'testingMockClient',
        'modifiers' => 18,
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
                  'name' => 'Saloon\\Http\\Faking\\MockClient',
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
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 16,
            'endLine' => 16,
            'startTokenPos' => 50,
            'startFilePos' => 339,
            'endTokenPos' => 50,
            'endFilePos' => 342,
          ),
        ),
        'docComment' => '/**
 * Static mock client for testing.
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 16,
        'endLine' => 16,
        'startColumn' => 5,
        'endColumn' => 59,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'baseUrl' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'name' => 'baseUrl',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 9,
        'endColumn' => 33,
        'isPromoted' => true,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'timeout' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'name' => 'timeout',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 20,
        'endLine' => 20,
        'startColumn' => 9,
        'endColumn' => 35,
        'isPromoted' => true,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
    ),
    'immediateMethods' => 
    array (
      '__construct' => 
      array (
        'name' => '__construct',
        'parameters' => 
        array (
          'baseUrl' => 
          array (
            'name' => 'baseUrl',
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
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 19,
            'endLine' => 19,
            'startColumn' => 9,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'timeout' => 
          array (
            'name' => 'timeout',
            'default' => 
            array (
              'code' => '30',
              'attributes' => 
              array (
                'startLine' => 20,
                'endLine' => 20,
                'startTokenPos' => 75,
                'startFilePos' => 447,
                'endTokenPos' => 75,
                'endFilePos' => 448,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 20,
            'endLine' => 20,
            'startColumn' => 9,
            'endColumn' => 35,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 18,
        'endLine' => 26,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'aliasName' => NULL,
      ),
      'resolveBaseUrl' => 
      array (
        'name' => 'resolveBaseUrl',
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
        'startLine' => 28,
        'endLine' => 31,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'aliasName' => NULL,
      ),
      'defaultHeaders' => 
      array (
        'name' => 'defaultHeaders',
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
        'docComment' => NULL,
        'startLine' => 33,
        'endLine' => 39,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'aliasName' => NULL,
      ),
      'defaultConfig' => 
      array (
        'name' => 'defaultConfig',
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
        'docComment' => NULL,
        'startLine' => 41,
        'endLine' => 52,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'aliasName' => NULL,
      ),
      'forEnvironment' => 
      array (
        'name' => 'forEnvironment',
        'parameters' => 
        array (
          'tld' => 
          array (
            'name' => 'tld',
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
            'startLine' => 60,
            'endLine' => 60,
            'startColumn' => 43,
            'endColumn' => 53,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'timeout' => 
          array (
            'name' => 'timeout',
            'default' => 
            array (
              'code' => '30',
              'attributes' => 
              array (
                'startLine' => 60,
                'endLine' => 60,
                'startTokenPos' => 261,
                'startFilePos' => 1626,
                'endTokenPos' => 261,
                'endFilePos' => 1627,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 60,
            'endLine' => 60,
            'startColumn' => 56,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a connector for the given environment.
 *
 * In local/development mode, uses orbit-web.{tld} (development instance).
 * In production mode (bundled in CLI), uses orbit.{tld} (bundled instance).
 */',
        'startLine' => 60,
        'endLine' => 65,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'aliasName' => NULL,
      ),
      'setMockClient' => 
      array (
        'name' => 'setMockClient',
        'parameters' => 
        array (
          'mockClient' => 
          array (
            'name' => 'mockClient',
            'default' => NULL,
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
                      'name' => 'Saloon\\Http\\Faking\\MockClient',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 70,
            'endLine' => 70,
            'startColumn' => 42,
            'endColumn' => 64,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set a mock client for testing (static, applies to all instances).
 */',
        'startLine' => 70,
        'endLine' => 73,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'aliasName' => NULL,
      ),
      'clearMockClient' => 
      array (
        'name' => 'clearMockClient',
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
 * Clear the static mock client.
 */',
        'startLine' => 78,
        'endLine' => 81,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\OrbitConnector',
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