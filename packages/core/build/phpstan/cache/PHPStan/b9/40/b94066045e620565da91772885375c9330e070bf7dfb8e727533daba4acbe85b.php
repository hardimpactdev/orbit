<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/Requests/EnableServiceRequest.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Http\Integrations\Orbit\Requests\EnableServiceRequest
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-dc41afa9104a0b4b4d113e0250a82981563b0e47d2243e7d4562901f6f3444fd',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Http/Integrations/Orbit/Requests/EnableServiceRequest.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests',
    'name' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
    'shortName' => 'EnableServiceRequest',
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
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
      'service' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
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
      'options' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'name' => 'options',
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
        'endColumn' => 37,
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
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 19,
                'endLine' => 19,
                'startTokenPos' => 88,
                'startFilePos' => 456,
                'endTokenPos' => 89,
                'endFilePos' => 457,
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
            'endColumn' => 37,
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
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
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Http\\Integrations\\Orbit\\Requests\\EnableServiceRequest',
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