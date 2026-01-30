<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/Tools/ProjectDeleteTool.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Ui\Mcp\Tools\ProjectDeleteTool
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-80a9987e0e77f5a0fb65059aecd249068d423c6f80d6c1edb0ee90aadd16bfcc',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/src/Mcp/Tools/ProjectDeleteTool.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
    'name' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
    'shortName' => 'ProjectDeleteTool',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 15,
    'endLine' => 66,
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
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
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
          'code' => '\'orbit_project_delete\'',
          'attributes' => 
          array (
            'startLine' => 17,
            'endLine' => 17,
            'startTokenPos' => 68,
            'startFilePos' => 414,
            'endTokenPos' => 68,
            'endFilePos' => 435,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 5,
        'endColumn' => 52,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'description' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
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
          'code' => '\'Delete a project with cascade deletion of GitHub repo and sequence entry\'',
          'attributes' => 
          array (
            'startLine' => 19,
            'endLine' => 19,
            'startTokenPos' => 79,
            'startFilePos' => 475,
            'endTokenPos' => 79,
            'endFilePos' => 548,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 111,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'projectService' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'name' => 'projectService',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\ProjectCliService',
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
        'endColumn' => 75,
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
          'projectService' => 
          array (
            'name' => 'projectService',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\OrbitCli\\ProjectCliService',
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
            'endColumn' => 75,
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
        'endColumn' => 79,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
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
            'startLine' => 26,
            'endLine' => 26,
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
        'startLine' => 26,
        'endLine' => 32,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
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
            'startLine' => 34,
            'endLine' => 34,
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
        'startLine' => 34,
        'endLine' => 65,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Mcp\\Tools\\ProjectDeleteTool',
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