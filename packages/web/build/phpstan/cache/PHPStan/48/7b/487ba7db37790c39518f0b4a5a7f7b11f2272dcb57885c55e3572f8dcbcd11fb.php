<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/web/vendor/composer/../laravel/framework/src/Illuminate/Http/Request.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Http\Request
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-96ad949ecbd80eeb3907f4e684ac6b425bd5ac276e449d44ca6f8d608cc449c0-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Http\\Request',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/web/vendor/composer/../laravel/framework/src/Illuminate/Http/Request.php',
      ),
    ),
    'namespace' => 'Illuminate\\Http',
    'name' => 'Illuminate\\Http\\Request',
    'shortName' => 'Request',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @method array validate(array $rules, ...$params)
 * @method array validateWithBag(string $errorBag, array $rules, ...$params)
 * @method bool hasValidSignature(bool $absolute = true)
 * @method bool hasValidRelativeSignature()
 * @method bool hasValidSignatureWhileIgnoring($ignoreQuery = [], $absolute = true)
 * @method bool hasValidRelativeSignatureWhileIgnoring($ignoreQuery = [])
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 29,
    'endLine' => 839,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Symfony\\Component\\HttpFoundation\\Request',
    'implementsClassNames' => 
    array (
      0 => 'Illuminate\\Contracts\\Support\\Arrayable',
      1 => 'ArrayAccess',
    ),
    'traitClassNames' => 
    array (
      0 => 'Illuminate\\Http\\Concerns\\CanBePrecognitive',
      1 => 'Illuminate\\Http\\Concerns\\InteractsWithContentTypes',
      2 => 'Illuminate\\Http\\Concerns\\InteractsWithFlashData',
      3 => 'Illuminate\\Http\\Concerns\\InteractsWithInput',
      4 => 'Illuminate\\Support\\Traits\\Conditionable',
      5 => 'Illuminate\\Support\\Traits\\Macroable',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'json' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'name' => 'json',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The decoded JSON content for the request.
 *
 * @var \\Symfony\\Component\\HttpFoundation\\InputBag|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 43,
        'endLine' => 43,
        'startColumn' => 5,
        'endColumn' => 20,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'convertedFiles' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'name' => 'convertedFiles',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * All of the converted files for the request.
 *
 * @var array<int, \\Illuminate\\Http\\UploadedFile|\\Illuminate\\Http\\UploadedFile[]>
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 50,
        'endLine' => 50,
        'startColumn' => 5,
        'endColumn' => 30,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'userResolver' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'name' => 'userResolver',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The user resolver callback.
 *
 * @var \\Closure
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 57,
        'endLine' => 57,
        'startColumn' => 5,
        'endColumn' => 28,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'routeResolver' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'name' => 'routeResolver',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The route resolver callback.
 *
 * @var \\Closure
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 64,
        'endLine' => 64,
        'startColumn' => 5,
        'endColumn' => 29,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'cachedAcceptHeader' => 
      array (
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'name' => 'cachedAcceptHeader',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The cached "Accept" header value.
 *
 * @var string|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 71,
        'endLine' => 71,
        'startColumn' => 5,
        'endColumn' => 34,
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
      'capture' => 
      array (
        'name' => 'capture',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new Illuminate HTTP request from server variables.
 *
 * @return static
 */',
        'startLine' => 78,
        'endLine' => 83,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'instance' => 
      array (
        'name' => 'instance',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Return the Request instance.
 *
 * @return $this
 */',
        'startLine' => 90,
        'endLine' => 93,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'method' => 
      array (
        'name' => 'method',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the request method.
 *
 * @return string
 */',
        'startLine' => 100,
        'endLine' => 103,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'uri' => 
      array (
        'name' => 'uri',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a URI instance for the request.
 *
 * @return \\Illuminate\\Support\\Uri
 */',
        'startLine' => 110,
        'endLine' => 113,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'root' => 
      array (
        'name' => 'root',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the root URL for the application.
 *
 * @return string
 */',
        'startLine' => 120,
        'endLine' => 123,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'url' => 
      array (
        'name' => 'url',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the URL (no query string) for the request.
 *
 * @return string
 */',
        'startLine' => 130,
        'endLine' => 133,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'fullUrl' => 
      array (
        'name' => 'fullUrl',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the full URL for the request.
 *
 * @return string
 */',
        'startLine' => 140,
        'endLine' => 147,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'fullUrlWithQuery' => 
      array (
        'name' => 'fullUrlWithQuery',
        'parameters' => 
        array (
          'query' => 
          array (
            'name' => 'query',
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
            'startLine' => 155,
            'endLine' => 155,
            'startColumn' => 38,
            'endColumn' => 49,
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
 * Get the full URL for the request with the added query string parameters.
 *
 * @param  array  $query
 * @return string
 */',
        'startLine' => 155,
        'endLine' => 162,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'fullUrlWithoutQuery' => 
      array (
        'name' => 'fullUrlWithoutQuery',
        'parameters' => 
        array (
          'keys' => 
          array (
            'name' => 'keys',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 170,
            'endLine' => 170,
            'startColumn' => 41,
            'endColumn' => 45,
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
 * Get the full URL for the request without the given query string parameters.
 *
 * @param  array|string  $keys
 * @return string
 */',
        'startLine' => 170,
        'endLine' => 179,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'path' => 
      array (
        'name' => 'path',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the current path info for the request.
 *
 * @return string
 */',
        'startLine' => 186,
        'endLine' => 191,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'decodedPath' => 
      array (
        'name' => 'decodedPath',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the current decoded path info for the request.
 *
 * @return string
 */',
        'startLine' => 198,
        'endLine' => 201,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'segment' => 
      array (
        'name' => 'segment',
        'parameters' => 
        array (
          'index' => 
          array (
            'name' => 'index',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 210,
            'endLine' => 210,
            'startColumn' => 29,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 210,
                'endLine' => 210,
                'startTokenPos' => 714,
                'startFilePos' => 5123,
                'endTokenPos' => 714,
                'endFilePos' => 5126,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 210,
            'endLine' => 210,
            'startColumn' => 37,
            'endColumn' => 51,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a segment from the URI (1 based index).
 *
 * @param  int  $index
 * @param  string|null  $default
 * @return string|null
 */',
        'startLine' => 210,
        'endLine' => 213,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'segments' => 
      array (
        'name' => 'segments',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get all of the segments for the request path.
 *
 * @return array
 */',
        'startLine' => 220,
        'endLine' => 227,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'is' => 
      array (
        'name' => 'is',
        'parameters' => 
        array (
          'patterns' => 
          array (
            'name' => 'patterns',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => true,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 235,
            'endLine' => 235,
            'startColumn' => 24,
            'endColumn' => 35,
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
 * Determine if the current request URI matches a pattern.
 *
 * @param  mixed  ...$patterns
 * @return bool
 */',
        'startLine' => 235,
        'endLine' => 239,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'routeIs' => 
      array (
        'name' => 'routeIs',
        'parameters' => 
        array (
          'patterns' => 
          array (
            'name' => 'patterns',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => true,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 247,
            'endLine' => 247,
            'startColumn' => 29,
            'endColumn' => 40,
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
 * Determine if the route name matches a given pattern.
 *
 * @param  mixed  ...$patterns
 * @return bool
 */',
        'startLine' => 247,
        'endLine' => 250,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'fullUrlIs' => 
      array (
        'name' => 'fullUrlIs',
        'parameters' => 
        array (
          'patterns' => 
          array (
            'name' => 'patterns',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => true,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 258,
            'endLine' => 258,
            'startColumn' => 31,
            'endColumn' => 42,
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
 * Determine if the current request URL and query string match a pattern.
 *
 * @param  mixed  ...$patterns
 * @return bool
 */',
        'startLine' => 258,
        'endLine' => 262,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'host' => 
      array (
        'name' => 'host',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the host name.
 *
 * @return string
 */',
        'startLine' => 269,
        'endLine' => 272,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'httpHost' => 
      array (
        'name' => 'httpHost',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the HTTP host being requested.
 *
 * @return string
 */',
        'startLine' => 279,
        'endLine' => 282,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'schemeAndHttpHost' => 
      array (
        'name' => 'schemeAndHttpHost',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the scheme and HTTP host.
 *
 * @return string
 */',
        'startLine' => 289,
        'endLine' => 292,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'ajax' => 
      array (
        'name' => 'ajax',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the request is the result of an AJAX call.
 *
 * @return bool
 */',
        'startLine' => 299,
        'endLine' => 302,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'pjax' => 
      array (
        'name' => 'pjax',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the request is the result of a PJAX call.
 *
 * @return bool
 */',
        'startLine' => 309,
        'endLine' => 312,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'prefetch' => 
      array (
        'name' => 'prefetch',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the request is the result of a prefetch call.
 *
 * @return bool
 */',
        'startLine' => 319,
        'endLine' => 324,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'secure' => 
      array (
        'name' => 'secure',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the request is over HTTPS.
 *
 * @return bool
 */',
        'startLine' => 331,
        'endLine' => 334,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'ip' => 
      array (
        'name' => 'ip',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the client IP address.
 *
 * @return string|null
 */',
        'startLine' => 341,
        'endLine' => 344,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'ips' => 
      array (
        'name' => 'ips',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the client IP addresses.
 *
 * @return array
 */',
        'startLine' => 351,
        'endLine' => 354,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'userAgent' => 
      array (
        'name' => 'userAgent',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the client user agent.
 *
 * @return string|null
 */',
        'startLine' => 361,
        'endLine' => 364,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'getAcceptableContentTypes' => 
      array (
        'name' => 'getAcceptableContentTypes',
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
          0 => 
          array (
            'name' => 'Override',
            'isRepeated' => false,
            'arguments' => 
            array (
            ),
          ),
        ),
        'docComment' => '/**
 * {@inheritdoc}
 */',
        'startLine' => 369,
        'endLine' => 381,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'merge' => 
      array (
        'name' => 'merge',
        'parameters' => 
        array (
          'input' => 
          array (
            'name' => 'input',
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
            'startLine' => 389,
            'endLine' => 389,
            'startColumn' => 27,
            'endColumn' => 38,
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
 * Merge new input into the current request\'s input array.
 *
 * @param  array  $input
 * @return $this
 */',
        'startLine' => 389,
        'endLine' => 398,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'mergeIfMissing' => 
      array (
        'name' => 'mergeIfMissing',
        'parameters' => 
        array (
          'input' => 
          array (
            'name' => 'input',
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
            'startLine' => 406,
            'endLine' => 406,
            'startColumn' => 36,
            'endColumn' => 47,
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
 * Merge new input into the request\'s input, but only when that key is missing from the request.
 *
 * @param  array  $input
 * @return $this
 */',
        'startLine' => 406,
        'endLine' => 412,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'replace' => 
      array (
        'name' => 'replace',
        'parameters' => 
        array (
          'input' => 
          array (
            'name' => 'input',
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
            'startLine' => 420,
            'endLine' => 420,
            'startColumn' => 29,
            'endColumn' => 40,
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
 * Replace the input values for the current request.
 *
 * @param  array  $input
 * @return $this
 */',
        'startLine' => 420,
        'endLine' => 425,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'get' => 
      array (
        'name' => 'get',
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
            'startLine' => 437,
            'endLine' => 437,
            'startColumn' => 25,
            'endColumn' => 35,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 437,
                'endLine' => 437,
                'startTokenPos' => 1565,
                'startFilePos' => 10405,
                'endTokenPos' => 1565,
                'endFilePos' => 10408,
              ),
            ),
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'mixed',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 437,
            'endLine' => 437,
            'startColumn' => 38,
            'endColumn' => 58,
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
            'name' => 'mixed',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
          0 => 
          array (
            'name' => 'Override',
            'isRepeated' => false,
            'arguments' => 
            array (
            ),
          ),
        ),
        'docComment' => '/**
 * This method belongs to Symfony HttpFoundation and is not usually needed when using Laravel.
 *
 * Instead, you may use the "input" method.
 *
 * @param  string  $key
 * @param  mixed  $default
 * @return mixed
 */',
        'startLine' => 436,
        'endLine' => 440,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'json' => 
      array (
        'name' => 'json',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 449,
                'endLine' => 449,
                'startTokenPos' => 1600,
                'startFilePos' => 10722,
                'endTokenPos' => 1600,
                'endFilePos' => 10725,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 449,
            'endLine' => 449,
            'startColumn' => 26,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 449,
                'endLine' => 449,
                'startTokenPos' => 1607,
                'startFilePos' => 10739,
                'endTokenPos' => 1607,
                'endFilePos' => 10742,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 449,
            'endLine' => 449,
            'startColumn' => 39,
            'endColumn' => 53,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the JSON payload for the request.
 *
 * @param  string|null  $key
 * @param  mixed  $default
 * @return ($key is null ? \\Symfony\\Component\\HttpFoundation\\InputBag : mixed)
 */',
        'startLine' => 449,
        'endLine' => 460,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'getInputSource' => 
      array (
        'name' => 'getInputSource',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the input source for the request.
 *
 * @return \\Symfony\\Component\\HttpFoundation\\InputBag
 */',
        'startLine' => 467,
        'endLine' => 474,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'createFrom' => 
      array (
        'name' => 'createFrom',
        'parameters' => 
        array (
          'from' => 
          array (
            'name' => 'from',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'self',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 483,
            'endLine' => 483,
            'startColumn' => 39,
            'endColumn' => 48,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'to' => 
          array (
            'name' => 'to',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 483,
                'endLine' => 483,
                'startTokenPos' => 1790,
                'startFilePos' => 11654,
                'endTokenPos' => 1790,
                'endFilePos' => 11657,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 483,
            'endLine' => 483,
            'startColumn' => 51,
            'endColumn' => 60,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new request instance from the given Laravel request.
 *
 * @param  \\Illuminate\\Http\\Request  $from
 * @param  \\Illuminate\\Http\\Request|null  $to
 * @return static
 */',
        'startLine' => 483,
        'endLine' => 516,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'createFromBase' => 
      array (
        'name' => 'createFromBase',
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
                'name' => 'Symfony\\Component\\HttpFoundation\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 524,
            'endLine' => 524,
            'startColumn' => 43,
            'endColumn' => 65,
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
 * Create an Illuminate request from a Symfony instance.
 *
 * @param  \\Symfony\\Component\\HttpFoundation\\Request  $request
 * @return static
 */',
        'startLine' => 524,
        'endLine' => 540,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'duplicate' => 
      array (
        'name' => 'duplicate',
        'parameters' => 
        array (
          'query' => 
          array (
            'name' => 'query',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 548,
                'endLine' => 548,
                'startTokenPos' => 2180,
                'startFilePos' => 13431,
                'endTokenPos' => 2180,
                'endFilePos' => 13434,
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
                      'name' => 'array',
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
            'startLine' => 548,
            'endLine' => 548,
            'startColumn' => 31,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'request' => 
          array (
            'name' => 'request',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 548,
                'endLine' => 548,
                'startTokenPos' => 2190,
                'startFilePos' => 13455,
                'endTokenPos' => 2190,
                'endFilePos' => 13458,
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
                      'name' => 'array',
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
            'startLine' => 548,
            'endLine' => 548,
            'startColumn' => 53,
            'endColumn' => 74,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'attributes' => 
          array (
            'name' => 'attributes',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 548,
                'endLine' => 548,
                'startTokenPos' => 2200,
                'startFilePos' => 13482,
                'endTokenPos' => 2200,
                'endFilePos' => 13485,
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
                      'name' => 'array',
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
            'startLine' => 548,
            'endLine' => 548,
            'startColumn' => 77,
            'endColumn' => 101,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
          'cookies' => 
          array (
            'name' => 'cookies',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 548,
                'endLine' => 548,
                'startTokenPos' => 2210,
                'startFilePos' => 13506,
                'endTokenPos' => 2210,
                'endFilePos' => 13509,
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
                      'name' => 'array',
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
            'startLine' => 548,
            'endLine' => 548,
            'startColumn' => 104,
            'endColumn' => 125,
            'parameterIndex' => 3,
            'isOptional' => true,
          ),
          'files' => 
          array (
            'name' => 'files',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 548,
                'endLine' => 548,
                'startTokenPos' => 2220,
                'startFilePos' => 13528,
                'endTokenPos' => 2220,
                'endFilePos' => 13531,
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
                      'name' => 'array',
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
            'startLine' => 548,
            'endLine' => 548,
            'startColumn' => 128,
            'endColumn' => 147,
            'parameterIndex' => 4,
            'isOptional' => true,
          ),
          'server' => 
          array (
            'name' => 'server',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 548,
                'endLine' => 548,
                'startTokenPos' => 2230,
                'startFilePos' => 13551,
                'endTokenPos' => 2230,
                'endFilePos' => 13554,
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
                      'name' => 'array',
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
            'startLine' => 548,
            'endLine' => 548,
            'startColumn' => 150,
            'endColumn' => 170,
            'parameterIndex' => 5,
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
          0 => 
          array (
            'name' => 'Override',
            'isRepeated' => false,
            'arguments' => 
            array (
            ),
          ),
        ),
        'docComment' => '/**
 * {@inheritdoc}
 *
 * @return static
 */',
        'startLine' => 547,
        'endLine' => 551,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'filterFiles' => 
      array (
        'name' => 'filterFiles',
        'parameters' => 
        array (
          'files' => 
          array (
            'name' => 'files',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 559,
            'endLine' => 559,
            'startColumn' => 36,
            'endColumn' => 41,
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
 * Filter the given array of files, removing any empty values.
 *
 * @param  mixed  $files
 * @return mixed
 */',
        'startLine' => 559,
        'endLine' => 576,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'hasSession' => 
      array (
        'name' => 'hasSession',
        'parameters' => 
        array (
          'skipIfUninitialized' => 
          array (
            'name' => 'skipIfUninitialized',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 582,
                'endLine' => 582,
                'startTokenPos' => 2397,
                'startFilePos' => 14337,
                'endTokenPos' => 2397,
                'endFilePos' => 14341,
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
            'startLine' => 582,
            'endLine' => 582,
            'startColumn' => 32,
            'endColumn' => 64,
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
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
          0 => 
          array (
            'name' => 'Override',
            'isRepeated' => false,
            'arguments' => 
            array (
            ),
          ),
        ),
        'docComment' => '/**
 * {@inheritdoc}
 */',
        'startLine' => 581,
        'endLine' => 585,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'getSession' => 
      array (
        'name' => 'getSession',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Symfony\\Component\\HttpFoundation\\Session\\SessionInterface',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
          0 => 
          array (
            'name' => 'Override',
            'isRepeated' => false,
            'arguments' => 
            array (
            ),
          ),
        ),
        'docComment' => '/**
 * {@inheritdoc}
 */',
        'startLine' => 590,
        'endLine' => 596,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'session' => 
      array (
        'name' => 'session',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the session associated with the request.
 *
 * @return \\Illuminate\\Contracts\\Session\\Session
 *
 * @throws \\RuntimeException
 */',
        'startLine' => 605,
        'endLine' => 612,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'setLaravelSession' => 
      array (
        'name' => 'setLaravelSession',
        'parameters' => 
        array (
          'session' => 
          array (
            'name' => 'session',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 620,
            'endLine' => 620,
            'startColumn' => 39,
            'endColumn' => 46,
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
 * Set the session instance on the request.
 *
 * @param  \\Illuminate\\Contracts\\Session\\Session  $session
 * @return void
 */',
        'startLine' => 620,
        'endLine' => 623,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'setRequestLocale' => 
      array (
        'name' => 'setRequestLocale',
        'parameters' => 
        array (
          'locale' => 
          array (
            'name' => 'locale',
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
            'startLine' => 631,
            'endLine' => 631,
            'startColumn' => 38,
            'endColumn' => 51,
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
 * Set the locale for the request instance.
 *
 * @param  string  $locale
 * @return void
 */',
        'startLine' => 631,
        'endLine' => 634,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'setDefaultRequestLocale' => 
      array (
        'name' => 'setDefaultRequestLocale',
        'parameters' => 
        array (
          'locale' => 
          array (
            'name' => 'locale',
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
            'startLine' => 642,
            'endLine' => 642,
            'startColumn' => 45,
            'endColumn' => 58,
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
 * Set the default locale for the request instance.
 *
 * @param  string  $locale
 * @return void
 */',
        'startLine' => 642,
        'endLine' => 645,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'user' => 
      array (
        'name' => 'user',
        'parameters' => 
        array (
          'guard' => 
          array (
            'name' => 'guard',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 653,
                'endLine' => 653,
                'startTokenPos' => 2604,
                'startFilePos' => 15931,
                'endTokenPos' => 2604,
                'endFilePos' => 15934,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 653,
            'endLine' => 653,
            'startColumn' => 26,
            'endColumn' => 38,
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
 * Get the user making the request.
 *
 * @param  string|null  $guard
 * @return mixed
 */',
        'startLine' => 653,
        'endLine' => 656,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'route' => 
      array (
        'name' => 'route',
        'parameters' => 
        array (
          'param' => 
          array (
            'name' => 'param',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 665,
                'endLine' => 665,
                'startTokenPos' => 2638,
                'startFilePos' => 16263,
                'endTokenPos' => 2638,
                'endFilePos' => 16266,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 665,
            'endLine' => 665,
            'startColumn' => 27,
            'endColumn' => 39,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 665,
                'endLine' => 665,
                'startTokenPos' => 2645,
                'startFilePos' => 16280,
                'endTokenPos' => 2645,
                'endFilePos' => 16283,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 665,
            'endLine' => 665,
            'startColumn' => 42,
            'endColumn' => 56,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the route handling the request.
 *
 * @param  string|null  $param
 * @param  mixed  $default
 * @return ($param is null ? \\Illuminate\\Routing\\Route : object|string|null)
 */',
        'startLine' => 665,
        'endLine' => 674,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'fingerprint' => 
      array (
        'name' => 'fingerprint',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a unique fingerprint for the request / route / IP address.
 *
 * @return string
 *
 * @throws \\RuntimeException
 */',
        'startLine' => 683,
        'endLine' => 693,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'setJson' => 
      array (
        'name' => 'setJson',
        'parameters' => 
        array (
          'json' => 
          array (
            'name' => 'json',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 701,
            'endLine' => 701,
            'startColumn' => 29,
            'endColumn' => 33,
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
 * Set the JSON payload for the request.
 *
 * @param  \\Symfony\\Component\\HttpFoundation\\InputBag  $json
 * @return $this
 */',
        'startLine' => 701,
        'endLine' => 706,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'getUserResolver' => 
      array (
        'name' => 'getUserResolver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the user resolver callback.
 *
 * @return \\Closure
 */',
        'startLine' => 713,
        'endLine' => 718,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'setUserResolver' => 
      array (
        'name' => 'setUserResolver',
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
            'startLine' => 726,
            'endLine' => 726,
            'startColumn' => 37,
            'endColumn' => 53,
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
 * Set the user resolver callback.
 *
 * @param  \\Closure  $callback
 * @return $this
 */',
        'startLine' => 726,
        'endLine' => 731,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'getRouteResolver' => 
      array (
        'name' => 'getRouteResolver',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the route resolver callback.
 *
 * @return \\Closure
 */',
        'startLine' => 738,
        'endLine' => 743,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'setRouteResolver' => 
      array (
        'name' => 'setRouteResolver',
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
            'startLine' => 751,
            'endLine' => 751,
            'startColumn' => 38,
            'endColumn' => 54,
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
 * Set the route resolver callback.
 *
 * @param  \\Closure  $callback
 * @return $this
 */',
        'startLine' => 751,
        'endLine' => 756,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'toArray' => 
      array (
        'name' => 'toArray',
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
 * Get all of the input and files for the request.
 *
 * @return array
 */',
        'startLine' => 763,
        'endLine' => 766,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'offsetExists' => 
      array (
        'name' => 'offsetExists',
        'parameters' => 
        array (
          'offset' => 
          array (
            'name' => 'offset',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 774,
            'endLine' => 774,
            'startColumn' => 34,
            'endColumn' => 40,
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
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the given offset exists.
 *
 * @param  string  $offset
 * @return bool
 */',
        'startLine' => 774,
        'endLine' => 782,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'offsetGet' => 
      array (
        'name' => 'offsetGet',
        'parameters' => 
        array (
          'offset' => 
          array (
            'name' => 'offset',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 790,
            'endLine' => 790,
            'startColumn' => 31,
            'endColumn' => 37,
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
            'name' => 'mixed',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the value at the given offset.
 *
 * @param  string  $offset
 * @return mixed
 */',
        'startLine' => 790,
        'endLine' => 793,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'offsetSet' => 
      array (
        'name' => 'offsetSet',
        'parameters' => 
        array (
          'offset' => 
          array (
            'name' => 'offset',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 802,
            'endLine' => 802,
            'startColumn' => 31,
            'endColumn' => 37,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 802,
            'endLine' => 802,
            'startColumn' => 40,
            'endColumn' => 45,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the value at the given offset.
 *
 * @param  string  $offset
 * @param  mixed  $value
 * @return void
 */',
        'startLine' => 802,
        'endLine' => 805,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      'offsetUnset' => 
      array (
        'name' => 'offsetUnset',
        'parameters' => 
        array (
          'offset' => 
          array (
            'name' => 'offset',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 813,
            'endLine' => 813,
            'startColumn' => 33,
            'endColumn' => 39,
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
 * Remove the value at the given offset.
 *
 * @param  string  $offset
 * @return void
 */',
        'startLine' => 813,
        'endLine' => 816,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      '__isset' => 
      array (
        'name' => '__isset',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 824,
            'endLine' => 824,
            'startColumn' => 29,
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
        'docComment' => '/**
 * Check if an input element is set on the request.
 *
 * @param  string  $key
 * @return bool
 */',
        'startLine' => 824,
        'endLine' => 827,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
        'aliasName' => NULL,
      ),
      '__get' => 
      array (
        'name' => '__get',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 835,
            'endLine' => 835,
            'startColumn' => 27,
            'endColumn' => 30,
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
 * Get an input element from the request.
 *
 * @param  string  $key
 * @return mixed
 */',
        'startLine' => 835,
        'endLine' => 838,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Http',
        'declaringClassName' => 'Illuminate\\Http\\Request',
        'implementingClassName' => 'Illuminate\\Http\\Request',
        'currentClassName' => 'Illuminate\\Http\\Request',
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