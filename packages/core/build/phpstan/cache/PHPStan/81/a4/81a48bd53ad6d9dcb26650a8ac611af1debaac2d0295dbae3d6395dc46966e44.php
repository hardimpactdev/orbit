<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/Requests/ConfigureServiceRequest.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\ConfigureServiceRequest
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-6e866218735f10ee0aa5b93ee665a2ad0fa7c483b49d4a756c8b976bc17c6c7d',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/Requests/ConfigureServiceRequest.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
    'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
    'shortName' => 'ConfigureServiceRequest',
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
    'endLine' => 31,
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
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
          'code' => '\\Saloon\\Enums\\Method::PUT',
          'attributes' => 
          array (
            'startLine' => 15,
            'endLine' => 15,
            'startTokenPos' => 61,
            'startFilePos' => 341,
            'endTokenPos' => 63,
            'endFilePos' => 351,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 15,
        'endLine' => 15,
        'startColumn' => 5,
        'endColumn' => 43,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'service' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'name' => 'service',
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
        'startLine' => 18,
        'endLine' => 18,
        'startColumn' => 9,
        'endColumn' => 33,
        'isPromoted' => true,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'serviceConfig' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'name' => 'serviceConfig',
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
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 9,
        'endColumn' => 43,
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
          'service' => 
          array (
            'name' => 'service',
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
            'startLine' => 18,
            'endLine' => 18,
            'startColumn' => 9,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'serviceConfig' => 
          array (
            'name' => 'serviceConfig',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 19,
                'endLine' => 19,
                'startTokenPos' => 88,
                'startFilePos' => 464,
                'endTokenPos' => 89,
                'endFilePos' => 465,
              ),
            ),
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
            'startLine' => 19,
            'endLine' => 19,
            'startColumn' => 9,
            'endColumn' => 43,
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
        'startLine' => 17,
        'endLine' => 20,
        'startColumn' => 5,
        'endColumn' => 8,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
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
        'startLine' => 22,
        'endLine' => 25,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
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
        'startLine' => 27,
        'endLine' => 30,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\ConfigureServiceRequest',
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