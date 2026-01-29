<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/core/vendor/composer/../guzzlehttp/psr7/src/Response.php-PHPStan\BetterReflection\Reflection\ReflectionClass-GuzzleHttp\Psr7\Response
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-85f711119e3248a8e7cc6c7a8ea74d02fb2193d0f3c7098015aed29a3376af8f-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'GuzzleHttp\\Psr7\\Response',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/vendor/composer/../guzzlehttp/psr7/src/Response.php',
      ),
    ),
    'namespace' => 'GuzzleHttp\\Psr7',
    'name' => 'GuzzleHttp\\Psr7\\Response',
    'shortName' => 'Response',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * PSR-7 response implementation.
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 13,
    'endLine' => 161,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
      0 => 'Psr\\Http\\Message\\ResponseInterface',
    ),
    'traitClassNames' => 
    array (
      0 => 'GuzzleHttp\\Psr7\\MessageTrait',
    ),
    'immediateConstants' => 
    array (
      'PHRASES' => 
      array (
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'name' => 'PHRASES',
        'modifiers' => 4,
        'type' => NULL,
        'value' => 
        array (
          'code' => '[100 => \'Continue\', 101 => \'Switching Protocols\', 102 => \'Processing\', 200 => \'OK\', 201 => \'Created\', 202 => \'Accepted\', 203 => \'Non-Authoritative Information\', 204 => \'No Content\', 205 => \'Reset Content\', 206 => \'Partial Content\', 207 => \'Multi-status\', 208 => \'Already Reported\', 300 => \'Multiple Choices\', 301 => \'Moved Permanently\', 302 => \'Found\', 303 => \'See Other\', 304 => \'Not Modified\', 305 => \'Use Proxy\', 306 => \'Switch Proxy\', 307 => \'Temporary Redirect\', 308 => \'Permanent Redirect\', 400 => \'Bad Request\', 401 => \'Unauthorized\', 402 => \'Payment Required\', 403 => \'Forbidden\', 404 => \'Not Found\', 405 => \'Method Not Allowed\', 406 => \'Not Acceptable\', 407 => \'Proxy Authentication Required\', 408 => \'Request Time-out\', 409 => \'Conflict\', 410 => \'Gone\', 411 => \'Length Required\', 412 => \'Precondition Failed\', 413 => \'Request Entity Too Large\', 414 => \'Request-URI Too Large\', 415 => \'Unsupported Media Type\', 416 => \'Requested range not satisfiable\', 417 => \'Expectation Failed\', 418 => \'I\\\'m a teapot\', 422 => \'Unprocessable Entity\', 423 => \'Locked\', 424 => \'Failed Dependency\', 425 => \'Unordered Collection\', 426 => \'Upgrade Required\', 428 => \'Precondition Required\', 429 => \'Too Many Requests\', 431 => \'Request Header Fields Too Large\', 451 => \'Unavailable For Legal Reasons\', 500 => \'Internal Server Error\', 501 => \'Not Implemented\', 502 => \'Bad Gateway\', 503 => \'Service Unavailable\', 504 => \'Gateway Time-out\', 505 => \'HTTP Version not supported\', 506 => \'Variant Also Negotiates\', 507 => \'Insufficient Storage\', 508 => \'Loop Detected\', 510 => \'Not Extended\', 511 => \'Network Authentication Required\']',
          'attributes' => 
          array (
            'startLine' => 18,
            'endLine' => 79,
            'startTokenPos' => 52,
            'startFilePos' => 338,
            'endTokenPos' => 474,
            'endFilePos' => 2442,
          ),
        ),
        'docComment' => '/** Map of standard HTTP status code/reason phrases */',
        'attributes' => 
        array (
        ),
        'startLine' => 18,
        'endLine' => 79,
        'startColumn' => 5,
        'endColumn' => 6,
      ),
    ),
    'immediateProperties' => 
    array (
      'reasonPhrase' => 
      array (
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'name' => 'reasonPhrase',
        'modifiers' => 4,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/** @var string */',
        'attributes' => 
        array (
        ),
        'startLine' => 82,
        'endLine' => 82,
        'startColumn' => 5,
        'endColumn' => 26,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'statusCode' => 
      array (
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'name' => 'statusCode',
        'modifiers' => 4,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/** @var int */',
        'attributes' => 
        array (
        ),
        'startLine' => 85,
        'endLine' => 85,
        'startColumn' => 5,
        'endColumn' => 24,
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
          'status' => 
          array (
            'name' => 'status',
            'default' => 
            array (
              'code' => '200',
              'attributes' => 
              array (
                'startLine' => 95,
                'endLine' => 95,
                'startTokenPos' => 506,
                'startFilePos' => 3049,
                'endTokenPos' => 506,
                'endFilePos' => 3051,
              ),
            ),
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
            'startLine' => 95,
            'endLine' => 95,
            'startColumn' => 9,
            'endColumn' => 25,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'headers' => 
          array (
            'name' => 'headers',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 96,
                'endLine' => 96,
                'startTokenPos' => 515,
                'startFilePos' => 3079,
                'endTokenPos' => 516,
                'endFilePos' => 3080,
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
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 96,
            'endLine' => 96,
            'startColumn' => 9,
            'endColumn' => 27,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'body' => 
          array (
            'name' => 'body',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 97,
                'endLine' => 97,
                'startTokenPos' => 523,
                'startFilePos' => 3099,
                'endTokenPos' => 523,
                'endFilePos' => 3102,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 97,
            'endLine' => 97,
            'startColumn' => 9,
            'endColumn' => 20,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'version' => 
          array (
            'name' => 'version',
            'default' => 
            array (
              'code' => '\'1.1\'',
              'attributes' => 
              array (
                'startLine' => 98,
                'endLine' => 98,
                'startTokenPos' => 532,
                'startFilePos' => 3131,
                'endTokenPos' => 532,
                'endFilePos' => 3135,
              ),
            ),
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
            'startLine' => 98,
            'endLine' => 98,
            'startColumn' => 9,
            'endColumn' => 31,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
          'reason' => 
          array (
            'name' => 'reason',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 99,
                'endLine' => 99,
                'startTokenPos' => 542,
                'startFilePos' => 3164,
                'endTokenPos' => 542,
                'endFilePos' => 3167,
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
                      'name' => 'string',
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
            'startLine' => 99,
            'endLine' => 99,
            'startColumn' => 9,
            'endColumn' => 30,
            'parameterIndex' => 4,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param int                                  $status  Status code
 * @param (string|string[])[]                  $headers Response headers
 * @param string|resource|StreamInterface|null $body    Response body
 * @param string                               $version Protocol version
 * @param string|null                          $reason  Reason phrase (when empty a default will be used based on the status code)
 */',
        'startLine' => 94,
        'endLine' => 117,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'GuzzleHttp\\Psr7',
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'currentClassName' => 'GuzzleHttp\\Psr7\\Response',
        'aliasName' => NULL,
      ),
      'getStatusCode' => 
      array (
        'name' => 'getStatusCode',
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
        'docComment' => NULL,
        'startLine' => 119,
        'endLine' => 122,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'GuzzleHttp\\Psr7',
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'currentClassName' => 'GuzzleHttp\\Psr7\\Response',
        'aliasName' => NULL,
      ),
      'getReasonPhrase' => 
      array (
        'name' => 'getReasonPhrase',
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
        'startLine' => 124,
        'endLine' => 127,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'GuzzleHttp\\Psr7',
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'currentClassName' => 'GuzzleHttp\\Psr7\\Response',
        'aliasName' => NULL,
      ),
      'withStatus' => 
      array (
        'name' => 'withStatus',
        'parameters' => 
        array (
          'code' => 
          array (
            'name' => 'code',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 129,
            'endLine' => 129,
            'startColumn' => 32,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'reasonPhrase' => 
          array (
            'name' => 'reasonPhrase',
            'default' => 
            array (
              'code' => '\'\'',
              'attributes' => 
              array (
                'startLine' => 129,
                'endLine' => 129,
                'startTokenPos' => 738,
                'startFilePos' => 3910,
                'endTokenPos' => 738,
                'endFilePos' => 3911,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 129,
            'endLine' => 129,
            'startColumn' => 39,
            'endColumn' => 56,
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
            'name' => 'Psr\\Http\\Message\\ResponseInterface',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 129,
        'endLine' => 143,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'GuzzleHttp\\Psr7',
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'currentClassName' => 'GuzzleHttp\\Psr7\\Response',
        'aliasName' => NULL,
      ),
      'assertStatusCodeIsInteger' => 
      array (
        'name' => 'assertStatusCodeIsInteger',
        'parameters' => 
        array (
          'statusCode' => 
          array (
            'name' => 'statusCode',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 148,
            'endLine' => 148,
            'startColumn' => 48,
            'endColumn' => 58,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * @param mixed $statusCode
 */',
        'startLine' => 148,
        'endLine' => 153,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'GuzzleHttp\\Psr7',
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'currentClassName' => 'GuzzleHttp\\Psr7\\Response',
        'aliasName' => NULL,
      ),
      'assertStatusCodeRange' => 
      array (
        'name' => 'assertStatusCodeRange',
        'parameters' => 
        array (
          'statusCode' => 
          array (
            'name' => 'statusCode',
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
            'startLine' => 155,
            'endLine' => 155,
            'startColumn' => 44,
            'endColumn' => 58,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 155,
        'endLine' => 160,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'GuzzleHttp\\Psr7',
        'declaringClassName' => 'GuzzleHttp\\Psr7\\Response',
        'implementingClassName' => 'GuzzleHttp\\Psr7\\Response',
        'currentClassName' => 'GuzzleHttp\\Psr7\\Response',
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