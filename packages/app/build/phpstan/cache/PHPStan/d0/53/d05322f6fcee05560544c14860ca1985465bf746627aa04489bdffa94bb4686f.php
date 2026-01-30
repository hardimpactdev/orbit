<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/app/src/Http/Controllers/ProjectController.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Ui\Http\Controllers\ProjectController
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-fcf28a2fd33d4b61eab5cae061da1a3b556f97528fe2905d5214fc4f02fa9dea',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/src/Http/Controllers/ProjectController.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers',
    'name' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
    'shortName' => 'ProjectController',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 13,
    'endLine' => 172,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\Controller',
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
      'connector' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'name' => 'connector',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\Shared\\ConnectorService',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 16,
        'endLine' => 16,
        'startColumn' => 9,
        'endColumn' => 45,
        'isPromoted' => true,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'environments' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'name' => 'environments',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\EnvironmentManager',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 9,
        'endColumn' => 50,
        'isPromoted' => true,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'config' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'name' => 'config',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\ConfigurationService',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 18,
        'endLine' => 18,
        'startColumn' => 9,
        'endColumn' => 46,
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
          'connector' => 
          array (
            'name' => 'connector',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\Shared\\ConnectorService',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 16,
            'endLine' => 16,
            'startColumn' => 9,
            'endColumn' => 45,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'environments' => 
          array (
            'name' => 'environments',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\EnvironmentManager',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 17,
            'endLine' => 17,
            'startColumn' => 9,
            'endColumn' => 50,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'config' => 
          array (
            'name' => 'config',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\ConfigurationService',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 18,
            'endLine' => 18,
            'startColumn' => 9,
            'endColumn' => 46,
            'parameterIndex' => 2,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 15,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 8,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'aliasName' => NULL,
      ),
      'store' => 
      array (
        'name' => 'store',
        'parameters' => 
        array (
          'request' => 
          array (
            'name' => 'request',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Http\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 25,
            'endLine' => 25,
            'startColumn' => 27,
            'endColumn' => 42,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new project in the active environment.
 * Always uses the Saloon API connector for consistency.
 */',
        'startLine' => 25,
        'endLine' => 83,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'aliasName' => NULL,
      ),
      'destroy' => 
      array (
        'name' => 'destroy',
        'parameters' => 
        array (
          'request' => 
          array (
            'name' => 'request',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Http\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 89,
            'endLine' => 89,
            'startColumn' => 29,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'slug' => 
          array (
            'name' => 'slug',
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
            'startLine' => 89,
            'endLine' => 89,
            'startColumn' => 47,
            'endColumn' => 58,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Delete a project from the active environment.
 * Always uses the Saloon API connector for consistency.
 */',
        'startLine' => 89,
        'endLine' => 115,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'aliasName' => NULL,
      ),
      'setPhpVersion' => 
      array (
        'name' => 'setPhpVersion',
        'parameters' => 
        array (
          'request' => 
          array (
            'name' => 'request',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Http\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 120,
            'endLine' => 120,
            'startColumn' => 35,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'project' => 
          array (
            'name' => 'project',
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
            'startLine' => 120,
            'endLine' => 120,
            'startColumn' => 53,
            'endColumn' => 67,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the PHP version for a project in the active environment.
 */',
        'startLine' => 120,
        'endLine' => 145,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'aliasName' => NULL,
      ),
      'resetPhpVersion' => 
      array (
        'name' => 'resetPhpVersion',
        'parameters' => 
        array (
          'project' => 
          array (
            'name' => 'project',
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
            'startLine' => 150,
            'endLine' => 150,
            'startColumn' => 37,
            'endColumn' => 51,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Reset the PHP version for a project to the environment default.
 */',
        'startLine' => 150,
        'endLine' => 171,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Controllers\\ProjectController',
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