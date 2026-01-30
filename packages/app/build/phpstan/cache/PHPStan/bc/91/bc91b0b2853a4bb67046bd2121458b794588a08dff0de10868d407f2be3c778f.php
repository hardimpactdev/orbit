<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../saloonphp/saloon/src/Traits/HasMockClient.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Saloon\Traits\HasMockClient
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-afe769c77e2fc997852e4d3732c411eaba28efc6ca6cbda4a7ebdc2cacd58751-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Saloon\\Traits\\HasMockClient',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../saloonphp/saloon/src/Traits/HasMockClient.php',
      ),
    ),
    'namespace' => 'Saloon\\Traits',
    'name' => 'Saloon\\Traits\\HasMockClient',
    'shortName' => 'HasMockClient',
    'isInterface' => false,
    'isTrait' => true,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 9,
    'endLine' => 43,
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
    ),
    'immediateProperties' => 
    array (
      'mockClient' => 
      array (
        'declaringClassName' => 'Saloon\\Traits\\HasMockClient',
        'implementingClassName' => 'Saloon\\Traits\\HasMockClient',
        'name' => 'mockClient',
        'modifiers' => 2,
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
            'startLine' => 14,
            'endLine' => 14,
            'startTokenPos' => 37,
            'startFilePos' => 192,
            'endTokenPos' => 37,
            'endFilePos' => 195,
          ),
        ),
        'docComment' => '/**
 * Mock Client
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 14,
        'endLine' => 14,
        'startColumn' => 5,
        'endColumn' => 45,
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
      'withMockClient' => 
      array (
        'name' => 'withMockClient',
        'parameters' => 
        array (
          'mockClient' => 
          array (
            'name' => 'mockClient',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Saloon\\Http\\Faking\\MockClient',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 21,
            'endLine' => 21,
            'startColumn' => 36,
            'endColumn' => 57,
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
            'name' => 'static',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Specify a mock client.
 *
 * @return $this
 */',
        'startLine' => 21,
        'endLine' => 26,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Saloon\\Traits',
        'declaringClassName' => 'Saloon\\Traits\\HasMockClient',
        'implementingClassName' => 'Saloon\\Traits\\HasMockClient',
        'currentClassName' => 'Saloon\\Traits\\HasMockClient',
        'aliasName' => NULL,
      ),
      'getMockClient' => 
      array (
        'name' => 'getMockClient',
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
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the mock client.
 */',
        'startLine' => 31,
        'endLine' => 34,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Saloon\\Traits',
        'declaringClassName' => 'Saloon\\Traits\\HasMockClient',
        'implementingClassName' => 'Saloon\\Traits\\HasMockClient',
        'currentClassName' => 'Saloon\\Traits\\HasMockClient',
        'aliasName' => NULL,
      ),
      'hasMockClient' => 
      array (
        'name' => 'hasMockClient',
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
        'docComment' => '/**
 * Determine if the instance has a mock client
 */',
        'startLine' => 39,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Saloon\\Traits',
        'declaringClassName' => 'Saloon\\Traits\\HasMockClient',
        'implementingClassName' => 'Saloon\\Traits\\HasMockClient',
        'currentClassName' => 'Saloon\\Traits\\HasMockClient',
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