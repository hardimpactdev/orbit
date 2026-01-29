<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Provision/Actions/SetPhpVersion.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\Provision\Actions\SetPhpVersion
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-cc24167cccde63b5040961bb016693d9c015c8514838556bd2d6e20259decfee',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Provision/Actions/SetPhpVersion.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
    'shortName' => 'SetPhpVersion',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 65568,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 12,
    'endLine' => 105,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
      'AVAILABLE_VERSIONS' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'name' => 'AVAILABLE_VERSIONS',
        'modifiers' => 4,
        'type' => NULL,
        'value' => 
        array (
          'code' => '[\'8.5\', \'8.4\', \'8.3\']',
          'attributes' => 
          array (
            'startLine' => 14,
            'endLine' => 14,
            'startTokenPos' => 53,
            'startFilePos' => 366,
            'endTokenPos' => 61,
            'endFilePos' => 386,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 14,
        'endLine' => 14,
        'startColumn' => 5,
        'endColumn' => 61,
      ),
      'DEFAULT_VERSION' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'name' => 'DEFAULT_VERSION',
        'modifiers' => 4,
        'type' => NULL,
        'value' => 
        array (
          'code' => '\'8.5\'',
          'attributes' => 
          array (
            'startLine' => 16,
            'endLine' => 16,
            'startTokenPos' => 72,
            'startFilePos' => 426,
            'endTokenPos' => 72,
            'endFilePos' => 430,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 16,
        'endLine' => 16,
        'startColumn' => 5,
        'endColumn' => 42,
      ),
    ),
    'immediateProperties' => 
    array (
    ),
    'immediateMethods' => 
    array (
      'handle' => 
      array (
        'name' => 'handle',
        'parameters' => 
        array (
          'context' => 
          array (
            'name' => 'context',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Data\\ProvisionContext',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 18,
            'endLine' => 18,
            'startColumn' => 28,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'logger' => 
          array (
            'name' => 'logger',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 18,
            'endLine' => 18,
            'startColumn' => 55,
            'endColumn' => 85,
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
            'name' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 18,
        'endLine' => 44,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'aliasName' => NULL,
      ),
      'detectPhpVersionFromComposer' => 
      array (
        'name' => 'detectPhpVersionFromComposer',
        'parameters' => 
        array (
          'projectPath' => 
          array (
            'name' => 'projectPath',
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
            'startLine' => 46,
            'endLine' => 46,
            'startColumn' => 51,
            'endColumn' => 69,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'logger' => 
          array (
            'name' => 'logger',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 46,
            'endLine' => 46,
            'startColumn' => 72,
            'endColumn' => 102,
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
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 46,
        'endLine' => 74,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'aliasName' => NULL,
      ),
      'getRecommendedPhpVersion' => 
      array (
        'name' => 'getRecommendedPhpVersion',
        'parameters' => 
        array (
          'constraint' => 
          array (
            'name' => 'constraint',
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
            'startLine' => 76,
            'endLine' => 76,
            'startColumn' => 47,
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
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 76,
        'endLine' => 104,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\Provision\\Actions\\SetPhpVersion',
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