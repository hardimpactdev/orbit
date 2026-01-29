<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/Requests/CreateProjectRequest.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\CreateProjectRequest
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-4dcf7119fc77b2fa45104e89683ed76225783a101e620e88b83f4da5b0dc1a99',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/Requests/CreateProjectRequest.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
    'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
    'shortName' => 'CreateProjectRequest',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 11,
    'endLine' => 30,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Saloon\\Http\\Request',
    'implementsClassNames' => 
    array (
      0 => 'Saloon\\Contracts\\Body\\HasBody',
    ),
    'traitClassNames' => 
    array (
      0 => 'Saloon\\Traits\\Body\\HasJsonBody',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'method' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'name' => 'method',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Saloon\\Enums\\Method',
            'isIdentifier' => false,
          ),
        ),
        'default' => 
        array (
          'code' => '\\Saloon\\Enums\\Method::POST',
          'attributes' => 
          array (
            'startLine' => 15,
            'endLine' => 15,
            'startTokenPos' => 61,
            'startFilePos' => 338,
            'endTokenPos' => 63,
            'endFilePos' => 349,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 15,
        'endLine' => 15,
        'startColumn' => 5,
        'endColumn' => 44,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'payload' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'name' => 'payload',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
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
        'endColumn' => 32,
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
          'payload' => 
          array (
            'name' => 'payload',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'array',
                'isIdentifier' => true,
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
            'endColumn' => 32,
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
        'startLine' => 17,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 8,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'aliasName' => NULL,
      ),
      'resolveEndpoint' => 
      array (
        'name' => 'resolveEndpoint',
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
        'startLine' => 21,
        'endLine' => 24,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'aliasName' => NULL,
      ),
      'defaultBody' => 
      array (
        'name' => 'defaultBody',
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
        'startLine' => 26,
        'endLine' => 29,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\CreateProjectRequest',
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