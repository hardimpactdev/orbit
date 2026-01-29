<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/core/vendor/composer/../saloonphp/saloon/src/Traits/HasDebugging.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Saloon\Traits\HasDebugging
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-3b774b09a1f2469ca2bc0738a370a9f9721c96caa97e9dbe3a7cef4a859aac2c-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Saloon\\Traits\\HasDebugging',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/vendor/composer/../saloonphp/saloon/src/Traits/HasDebugging.php',
      ),
    ),
    'namespace' => 'Saloon\\Traits',
    'name' => 'Saloon\\Traits\\HasDebugging',
    'shortName' => 'HasDebugging',
    'isInterface' => false,
    'isTrait' => true,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 12,
    'endLine' => 96,
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
    ),
    'immediateMethods' => 
    array (
      'debugRequest' => 
      array (
        'name' => 'debugRequest',
        'parameters' => 
        array (
          'onRequest' => 
          array (
            'name' => 'onRequest',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 22,
                'endLine' => 22,
                'startTokenPos' => 56,
                'startFilePos' => 518,
                'endTokenPos' => 56,
                'endFilePos' => 521,
              ),
            ),
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
                      'name' => 'callable',
                      'isIdentifier' => true,
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
            'startLine' => 22,
            'endLine' => 22,
            'startColumn' => 34,
            'endColumn' => 60,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'die' => 
          array (
            'name' => 'die',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 22,
                'endLine' => 22,
                'startTokenPos' => 65,
                'startFilePos' => 536,
                'endTokenPos' => 65,
                'endFilePos' => 540,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'bool',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 22,
            'endLine' => 22,
            'startColumn' => 63,
            'endColumn' => 79,
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
            'name' => 'static',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Register a request debugger
 *
 * Leave blank for a default debugger (requires symfony/var-dump)
 *
 * @param callable(\\Saloon\\Http\\PendingRequest, \\Psr\\Http\\Message\\RequestInterface): void|null $onRequest
 * @return $this
 */',
        'startLine' => 22,
        'endLine' => 45,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Saloon\\Traits',
        'declaringClassName' => 'Saloon\\Traits\\HasDebugging',
        'implementingClassName' => 'Saloon\\Traits\\HasDebugging',
        'currentClassName' => 'Saloon\\Traits\\HasDebugging',
        'aliasName' => NULL,
      ),
      'debugResponse' => 
      array (
        'name' => 'debugResponse',
        'parameters' => 
        array (
          'onResponse' => 
          array (
            'name' => 'onResponse',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 55,
                'endLine' => 55,
                'startTokenPos' => 196,
                'startFilePos' => 1733,
                'endTokenPos' => 196,
                'endFilePos' => 1736,
              ),
            ),
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
                      'name' => 'callable',
                      'isIdentifier' => true,
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
            'startLine' => 55,
            'endLine' => 55,
            'startColumn' => 35,
            'endColumn' => 62,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'die' => 
          array (
            'name' => 'die',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 55,
                'endLine' => 55,
                'startTokenPos' => 205,
                'startFilePos' => 1751,
                'endTokenPos' => 205,
                'endFilePos' => 1755,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'bool',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 55,
            'endLine' => 55,
            'startColumn' => 65,
            'endColumn' => 81,
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
            'name' => 'static',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Register a response debugger
 *
 * Leave blank for a default debugger (requires symfony/var-dump)
 *
 * @param callable(\\Saloon\\Http\\Response, \\Psr\\Http\\Message\\ResponseInterface): void|null $onResponse
 * @return $this
 */',
        'startLine' => 55,
        'endLine' => 78,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Saloon\\Traits',
        'declaringClassName' => 'Saloon\\Traits\\HasDebugging',
        'implementingClassName' => 'Saloon\\Traits\\HasDebugging',
        'currentClassName' => 'Saloon\\Traits\\HasDebugging',
        'aliasName' => NULL,
      ),
      'debug' => 
      array (
        'name' => 'debug',
        'parameters' => 
        array (
          'die' => 
          array (
            'name' => 'die',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 92,
                'endLine' => 92,
                'startTokenPos' => 335,
                'startFilePos' => 3093,
                'endTokenPos' => 335,
                'endFilePos' => 3097,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'bool',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 92,
            'endLine' => 92,
            'startColumn' => 27,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => true,
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
 * Dump a pretty output of the request and response.
 *
 * This is useful if you would like to see the request right before it is sent
 * to inspect the body and URI to ensure it is correct. You can also inspect
 * the raw response as it comes back.
 *
 * Note that any changes made to the PSR request by the sender will not be
 * reflected by this output.
 *
 * Requires symfony/var-dumper
 */',
        'startLine' => 92,
        'endLine' => 95,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Saloon\\Traits',
        'declaringClassName' => 'Saloon\\Traits\\HasDebugging',
        'implementingClassName' => 'Saloon\\Traits\\HasDebugging',
        'currentClassName' => 'Saloon\\Traits\\HasDebugging',
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