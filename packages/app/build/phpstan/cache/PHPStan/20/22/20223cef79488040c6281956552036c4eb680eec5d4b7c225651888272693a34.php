<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/Tools/StartTool.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Ui\Mcp\Tools\StartTool
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-1ecdde22388c95fb38508e873af7d00205446c753c45dbf1320395ef8a54c99c',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/Tools/StartTool.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
    'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
    'shortName' => 'StartTool',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 15,
    'endLine' => 60,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Laravel\\Mcp\\Server\\Tool',
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
      'name' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'name' => 'name',
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
        'default' => 
        array (
          'code' => '\'orbit_start\'',
          'attributes' => 
          array (
            'startLine' => 17,
            'endLine' => 17,
            'startTokenPos' => 70,
            'startFilePos' => 416,
            'endTokenPos' => 70,
            'endFilePos' => 428,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 5,
        'endColumn' => 43,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'description' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'name' => 'description',
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
        'default' => 
        array (
          'code' => '\'Start all Orbit Docker services (DNS, PHP, Caddy, PostgreSQL, Redis, Mailpit, and enabled optional services)\'',
          'attributes' => 
          array (
            'startLine' => 19,
            'endLine' => 19,
            'startTokenPos' => 81,
            'startFilePos' => 468,
            'endTokenPos' => 81,
            'endFilePos' => 577,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 147,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'serviceControl' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'name' => 'serviceControl',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\ServiceControlService',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 21,
        'endLine' => 21,
        'startColumn' => 33,
        'endColumn' => 79,
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
          'serviceControl' => 
          array (
            'name' => 'serviceControl',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\ServiceControlService',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 21,
            'endLine' => 21,
            'startColumn' => 33,
            'endColumn' => 79,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 21,
        'endLine' => 21,
        'startColumn' => 5,
        'endColumn' => 83,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'aliasName' => NULL,
      ),
      'schema' => 
      array (
        'name' => 'schema',
        'parameters' => 
        array (
          'schema' => 
          array (
            'name' => 'schema',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Contracts\\JsonSchema\\JsonSchema',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 23,
            'endLine' => 23,
            'startColumn' => 28,
            'endColumn' => 45,
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
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 23,
        'endLine' => 26,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'aliasName' => NULL,
      ),
      'handle' => 
      array (
        'name' => 'handle',
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
                'name' => 'Laravel\\Mcp\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 28,
            'endLine' => 28,
            'startColumn' => 28,
            'endColumn' => 43,
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
            'name' => 'Laravel\\Mcp\\ResponseFactory',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 28,
        'endLine' => 59,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\StartTool',
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