<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/Tools/WorktreesTool.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Ui\Mcp\Tools\WorktreesTool
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-d99b6b6c6258b3728dcbdeb2bda331c9fb98719193ece4e55523d74ffebf5052',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/Tools/WorktreesTool.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
    'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
    'shortName' => 'WorktreesTool',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
      0 => 
      array (
        'name' => 'Laravel\\Mcp\\Server\\Tools\\Annotations\\IsReadOnly',
        'isRepeated' => false,
        'arguments' => 
        array (
        ),
      ),
    ),
    'startLine' => 16,
    'endLine' => 78,
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
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
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
          'code' => '\'orbit_worktrees\'',
          'attributes' => 
          array (
            'startLine' => 19,
            'endLine' => 19,
            'startTokenPos' => 77,
            'startFilePos' => 475,
            'endTokenPos' => 77,
            'endFilePos' => 491,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 47,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'description' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
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
          'code' => '\'List git worktrees with their subdomains\'',
          'attributes' => 
          array (
            'startLine' => 21,
            'endLine' => 21,
            'startTokenPos' => 88,
            'startFilePos' => 531,
            'endTokenPos' => 88,
            'endFilePos' => 572,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 21,
        'endLine' => 21,
        'startColumn' => 5,
        'endColumn' => 79,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'worktreeService' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'name' => 'worktreeService',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\WorktreeService',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 23,
        'endLine' => 23,
        'startColumn' => 33,
        'endColumn' => 74,
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
          'worktreeService' => 
          array (
            'name' => 'worktreeService',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\WorktreeService',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 23,
            'endLine' => 23,
            'startColumn' => 33,
            'endColumn' => 74,
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
        'startLine' => 23,
        'endLine' => 23,
        'startColumn' => 5,
        'endColumn' => 78,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
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
            'startLine' => 28,
            'endLine' => 28,
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
        'docComment' => '/**
 * @return array<string, mixed>
 */',
        'startLine' => 28,
        'endLine' => 33,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
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
            'startLine' => 35,
            'endLine' => 35,
            'startColumn' => 28,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
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
                  'name' => 'Laravel\\Mcp\\Response',
                  'isIdentifier' => false,
                ),
              ),
              1 => 
              array (
                'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
                'data' => 
                array (
                  'name' => 'Laravel\\Mcp\\ResponseFactory',
                  'isIdentifier' => false,
                ),
              ),
            ),
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 35,
        'endLine' => 77,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\WorktreesTool',
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