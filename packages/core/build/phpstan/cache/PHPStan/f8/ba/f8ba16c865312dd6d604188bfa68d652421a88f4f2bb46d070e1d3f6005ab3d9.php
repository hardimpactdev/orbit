<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/core/vendor/composer/../laravel/framework/src/Illuminate/Http/Client/Batch.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Http\Client\Batch
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-09294422ec7e163afd461df4f1f17bb060b66a69d240fe11481bfc14ac4dff40-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Http\\Client\\Batch',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/vendor/composer/../laravel/framework/src/Illuminate/Http/Client/Batch.php',
      ),
    ),
    'namespace' => 'Illuminate\\Http\\Client',
    'name' => 'Illuminate\\Http\\Client\\Batch',
    'shortName' => 'Batch',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @mixin \\Illuminate\\Http\\Client\\Factory
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 18,
    'endLine' => 451,
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
      'factory' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'factory',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The factory instance.
 *
 * @var \\Illuminate\\Http\\Client\\Factory
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 25,
        'endLine' => 25,
        'startColumn' => 5,
        'endColumn' => 23,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'requests' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'requests',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[]',
          'attributes' => 
          array (
            'startLine' => 32,
            'endLine' => 32,
            'startTokenPos' => 72,
            'startFilePos' => 654,
            'endTokenPos' => 73,
            'endFilePos' => 655,
          ),
        ),
        'docComment' => '/**
 * The array of requests.
 *
 * @var array<array-key, \\Illuminate\\Http\\Client\\PendingRequest>
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 32,
        'endLine' => 32,
        'startColumn' => 5,
        'endColumn' => 29,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'totalRequests' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'totalRequests',
        'modifiers' => 1,
        'type' => NULL,
        'default' => 
        array (
          'code' => '0',
          'attributes' => 
          array (
            'startLine' => 39,
            'endLine' => 39,
            'startTokenPos' => 84,
            'startFilePos' => 801,
            'endTokenPos' => 84,
            'endFilePos' => 801,
          ),
        ),
        'docComment' => '/**
 * The total number of requests that belong to the batch.
 *
 * @var non-negative-int
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 39,
        'endLine' => 39,
        'startColumn' => 5,
        'endColumn' => 30,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'pendingRequests' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'pendingRequests',
        'modifiers' => 1,
        'type' => NULL,
        'default' => 
        array (
          'code' => '0',
          'attributes' => 
          array (
            'startLine' => 46,
            'endLine' => 46,
            'startTokenPos' => 95,
            'startFilePos' => 947,
            'endTokenPos' => 95,
            'endFilePos' => 947,
          ),
        ),
        'docComment' => '/**
 * The total number of requests that are still pending.
 *
 * @var non-negative-int
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 46,
        'endLine' => 46,
        'startColumn' => 5,
        'endColumn' => 32,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'failedRequests' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'failedRequests',
        'modifiers' => 1,
        'type' => NULL,
        'default' => 
        array (
          'code' => '0',
          'attributes' => 
          array (
            'startLine' => 53,
            'endLine' => 53,
            'startTokenPos' => 106,
            'startFilePos' => 1086,
            'endTokenPos' => 106,
            'endFilePos' => 1086,
          ),
        ),
        'docComment' => '/**
 * The total number of requests that have failed.
 *
 * @var non-negative-int
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 53,
        'endLine' => 53,
        'startColumn' => 5,
        'endColumn' => 31,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'handler' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'handler',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The handler function for the Guzzle client.
 *
 * @var callable
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 60,
        'endLine' => 60,
        'startColumn' => 5,
        'endColumn' => 23,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'beforeCallback' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'beforeCallback',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 67,
            'endLine' => 67,
            'startTokenPos' => 124,
            'startFilePos' => 1379,
            'endTokenPos' => 124,
            'endFilePos' => 1382,
          ),
        ),
        'docComment' => '/**
 * The callback to run before the first request from the batch runs.
 *
 * @var (\\Closure($this): void)|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 67,
        'endLine' => 67,
        'startColumn' => 5,
        'endColumn' => 37,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'progressCallback' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'progressCallback',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 74,
            'endLine' => 74,
            'startTokenPos' => 135,
            'startFilePos' => 1598,
            'endTokenPos' => 135,
            'endFilePos' => 1601,
          ),
        ),
        'docComment' => '/**
 * The callback to run after a request from the batch succeeds.
 *
 * @var (\\Closure($this, int|string, \\Illuminate\\Http\\Client\\Response): void)|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 74,
        'endLine' => 74,
        'startColumn' => 5,
        'endColumn' => 39,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'catchCallback' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'catchCallback',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 81,
            'endLine' => 81,
            'startTokenPos' => 146,
            'startFilePos' => 1896,
            'endTokenPos' => 146,
            'endFilePos' => 1899,
          ),
        ),
        'docComment' => '/**
 * The callback to run after a request from the batch fails.
 *
 * @var (\\Closure($this, int|string, \\Illuminate\\Http\\Client\\Response|\\Illuminate\\Http\\Client\\RequestException|\\Illuminate\\Http\\Client\\ConnectionException): void)|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 81,
        'endLine' => 81,
        'startColumn' => 5,
        'endColumn' => 36,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'thenCallback' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'thenCallback',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 88,
            'endLine' => 88,
            'startTokenPos' => 157,
            'startFilePos' => 2123,
            'endTokenPos' => 157,
            'endFilePos' => 2126,
          ),
        ),
        'docComment' => '/**
 * The callback to run if all the requests from the batch succeeded.
 *
 * @var (\\Closure($this, array<int|string, \\Illuminate\\Http\\Client\\Response>): void)|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 88,
        'endLine' => 88,
        'startColumn' => 5,
        'endColumn' => 35,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'finallyCallback' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'finallyCallback',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 95,
            'endLine' => 95,
            'startTokenPos' => 168,
            'startFilePos' => 2353,
            'endTokenPos' => 168,
            'endFilePos' => 2356,
          ),
        ),
        'docComment' => '/**
 * The callback to run after all the requests from the batch finish.
 *
 * @var (\\Closure($this, array<int|string, \\Illuminate\\Http\\Client\\Response>): void)|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 95,
        'endLine' => 95,
        'startColumn' => 5,
        'endColumn' => 38,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'inProgress' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'inProgress',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'false',
          'attributes' => 
          array (
            'startLine' => 102,
            'endLine' => 102,
            'startTokenPos' => 179,
            'startFilePos' => 2466,
            'endTokenPos' => 179,
            'endFilePos' => 2470,
          ),
        ),
        'docComment' => '/**
 * If the batch already was sent.
 *
 * @var bool
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 102,
        'endLine' => 102,
        'startColumn' => 5,
        'endColumn' => 34,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'createdAt' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'createdAt',
        'modifiers' => 1,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 109,
            'endLine' => 109,
            'startTokenPos' => 190,
            'startFilePos' => 2606,
            'endTokenPos' => 190,
            'endFilePos' => 2609,
          ),
        ),
        'docComment' => '/**
 * The date when the batch was created.
 *
 * @var \\Carbon\\CarbonImmutable|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 109,
        'endLine' => 109,
        'startColumn' => 5,
        'endColumn' => 29,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'finishedAt' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'finishedAt',
        'modifiers' => 1,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 116,
            'endLine' => 116,
            'startTokenPos' => 201,
            'startFilePos' => 2743,
            'endTokenPos' => 201,
            'endFilePos' => 2746,
          ),
        ),
        'docComment' => '/**
 * The date when the batch finished.
 *
 * @var \\Carbon\\CarbonImmutable|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 116,
        'endLine' => 116,
        'startColumn' => 5,
        'endColumn' => 30,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'concurrencyLimit' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'name' => 'concurrencyLimit',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'null',
          'attributes' => 
          array (
            'startLine' => 123,
            'endLine' => 123,
            'startTokenPos' => 212,
            'startFilePos' => 2878,
            'endTokenPos' => 212,
            'endFilePos' => 2881,
          ),
        ),
        'docComment' => '/**
 * The maximum number of concurrent requests.
 *
 * @var int|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 123,
        'endLine' => 123,
        'startColumn' => 5,
        'endColumn' => 39,
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
      '__construct' => 
      array (
        'name' => '__construct',
        'parameters' => 
        array (
          'factory' => 
          array (
            'name' => 'factory',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 128,
                'endLine' => 128,
                'startTokenPos' => 230,
                'startFilePos' => 2997,
                'endTokenPos' => 230,
                'endFilePos' => 3000,
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
                      'name' => 'Illuminate\\Http\\Client\\Factory',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 128,
            'endLine' => 128,
            'startColumn' => 33,
            'endColumn' => 56,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new request batch instance.
 */',
        'startLine' => 128,
        'endLine' => 133,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'as' => 
      array (
        'name' => 'as',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
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
            'startLine' => 143,
            'endLine' => 143,
            'startColumn' => 24,
            'endColumn' => 34,
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
 * Add a request to the batch with a key.
 *
 * @param  string  $key
 * @return \\Illuminate\\Http\\Client\\PendingRequest
 *
 * @throws \\Illuminate\\Http\\Client\\BatchInProgressException
 */',
        'startLine' => 143,
        'endLine' => 152,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'newRequest' => 
      array (
        'name' => 'newRequest',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Add a request to the batch with a numeric index.
 *
 * @return \\Illuminate\\Http\\Client\\PendingRequest|\\GuzzleHttp\\Promise\\Promise
 *
 * @throws \\Illuminate\\Http\\Client\\BatchInProgressException
 */',
        'startLine' => 161,
        'endLine' => 170,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'before' => 
      array (
        'name' => 'before',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Closure',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 178,
            'endLine' => 178,
            'startColumn' => 28,
            'endColumn' => 44,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Register a callback to run before the first request from the batch runs.
 *
 * @param  (\\Closure($this): void)  $callback
 * @return Batch
 */',
        'startLine' => 178,
        'endLine' => 183,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'progress' => 
      array (
        'name' => 'progress',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Closure',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 191,
            'endLine' => 191,
            'startColumn' => 30,
            'endColumn' => 46,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Register a callback to run after a request from the batch succeeds.
 *
 * @param  (\\Closure($this, int|string, \\Illuminate\\Http\\Client\\Response): void)  $callback
 * @return Batch
 */',
        'startLine' => 191,
        'endLine' => 196,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'catch' => 
      array (
        'name' => 'catch',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Closure',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 204,
            'endLine' => 204,
            'startColumn' => 27,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Register a callback to run after a request from the batch fails.
 *
 * @param  (\\Closure($this, int|string, \\Illuminate\\Http\\Client\\Response|\\Illuminate\\Http\\Client\\RequestException|\\Illuminate\\Http\\Client\\ConnectionException): void)  $callback
 * @return Batch
 */',
        'startLine' => 204,
        'endLine' => 209,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'then' => 
      array (
        'name' => 'then',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Closure',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 217,
            'endLine' => 217,
            'startColumn' => 26,
            'endColumn' => 42,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Register a callback to run after all the requests from the batch succeed.
 *
 * @param  (\\Closure($this, array<int|string, \\Illuminate\\Http\\Client\\Response>): void)  $callback
 * @return Batch
 */',
        'startLine' => 217,
        'endLine' => 222,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'finally' => 
      array (
        'name' => 'finally',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Closure',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 230,
            'endLine' => 230,
            'startColumn' => 29,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Register a callback to run after all the requests from the batch finish.
 *
 * @param  (\\Closure($this, array<int|string, \\Illuminate\\Http\\Client\\Response>): void)  $callback
 * @return Batch
 */',
        'startLine' => 230,
        'endLine' => 235,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'concurrency' => 
      array (
        'name' => 'concurrency',
        'parameters' => 
        array (
          'limit' => 
          array (
            'name' => 'limit',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'int',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 243,
            'endLine' => 243,
            'startColumn' => 33,
            'endColumn' => 42,
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
            'name' => 'self',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the maximum number of concurrent requests.
 *
 * @param  int  $limit
 * @return Batch
 */',
        'startLine' => 243,
        'endLine' => 248,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'defer' => 
      array (
        'name' => 'defer',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Illuminate\\Support\\Defer\\DeferredCallback',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Defer the batch to run in the background after the current task has finished.
 *
 * @return \\Illuminate\\Support\\Defer\\DeferredCallback
 */',
        'startLine' => 255,
        'endLine' => 258,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'send' => 
      array (
        'name' => 'send',
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
        'docComment' => '/**
 * Send all of the requests in the batch.
 *
 * @return array<int|string, \\Illuminate\\Http\\Client\\Response|\\Illuminate\\Http\\Client\\RequestException>
 */',
        'startLine' => 265,
        'endLine' => 355,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => true,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'asyncRequest' => 
      array (
        'name' => 'asyncRequest',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Retrieve a new async pending request.
 *
 * @return \\Illuminate\\Http\\Client\\PendingRequest
 */',
        'startLine' => 362,
        'endLine' => 365,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'processedRequests' => 
      array (
        'name' => 'processedRequests',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the total number of requests that have been processed by the batch thus far.
 *
 * @return non-negative-int
 */',
        'startLine' => 372,
        'endLine' => 375,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'finished' => 
      array (
        'name' => 'finished',
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
 * Determine if the batch has finished executing.
 *
 * @return bool
 */',
        'startLine' => 382,
        'endLine' => 385,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'incrementPendingRequests' => 
      array (
        'name' => 'incrementPendingRequests',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Increment the count of total and pending requests in the batch.
 *
 * @return void
 */',
        'startLine' => 392,
        'endLine' => 396,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'decrementPendingRequests' => 
      array (
        'name' => 'decrementPendingRequests',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Decrement the count of pending requests in the batch.
 *
 * @return void
 */',
        'startLine' => 403,
        'endLine' => 406,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'hasFailures' => 
      array (
        'name' => 'hasFailures',
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
 * Determine if the batch has job failures.
 *
 * @return bool
 */',
        'startLine' => 413,
        'endLine' => 416,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'incrementFailedRequests' => 
      array (
        'name' => 'incrementFailedRequests',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Increment the count of failed requests in the batch.
 *
 * @return void
 */',
        'startLine' => 423,
        'endLine' => 426,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      'getRequests' => 
      array (
        'name' => 'getRequests',
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
        'docComment' => '/**
 * Get the requests in the batch.
 *
 * @return array<array-key, \\Illuminate\\Http\\Client\\PendingRequest>
 */',
        'startLine' => 433,
        'endLine' => 436,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
        'aliasName' => NULL,
      ),
      '__call' => 
      array (
        'name' => '__call',
        'parameters' => 
        array (
          'method' => 
          array (
            'name' => 'method',
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
            'startLine' => 447,
            'endLine' => 447,
            'startColumn' => 28,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'parameters' => 
          array (
            'name' => 'parameters',
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
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 447,
            'endLine' => 447,
            'startColumn' => 44,
            'endColumn' => 60,
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
 * Add a request to the batch with a numeric index.
 *
 * @param  string  $method
 * @param  array  $parameters
 * @return \\Illuminate\\Http\\Client\\PendingRequest|\\GuzzleHttp\\Promise\\Promise
 *
 * @throws \\Illuminate\\Http\\Client\\BatchInProgressException
 */',
        'startLine' => 447,
        'endLine' => 450,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http\\Client',
        'declaringClassName' => 'Illuminate\\Http\\Client\\Batch',
        'implementingClassName' => 'Illuminate\\Http\\Client\\Batch',
        'currentClassName' => 'Illuminate\\Http\\Client\\Batch',
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