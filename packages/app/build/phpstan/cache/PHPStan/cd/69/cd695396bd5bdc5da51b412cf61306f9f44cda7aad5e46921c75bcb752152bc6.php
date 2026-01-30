<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../laravel/framework/src/Illuminate/Foundation/helpers.php-PHPStan\BetterReflection\Reflection\ReflectionFunction-redirect
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-2d9187bdad3b402f1f4b5303e340e54159c650e23089ccd7154ff6251180fddc',
   'data' => 
  array (
    'name' => 'redirect',
    'parameters' => 
    array (
      'to' => 
      array (
        'name' => 'to',
        'default' => 
        array (
          'code' => '\\null',
          'attributes' => 
          array (
            'startLine' => 708,
            'endLine' => 708,
            'startTokenPos' => 3152,
            'startFilePos' => 19310,
            'endTokenPos' => 3152,
            'endFilePos' => 19313,
          ),
        ),
        'type' => NULL,
        'isVariadic' => false,
        'byRef' => false,
        'isPromoted' => false,
        'attributes' => 
        array (
        ),
        'startLine' => 708,
        'endLine' => 708,
        'startColumn' => 23,
        'endColumn' => 32,
        'parameterIndex' => 0,
        'isOptional' => true,
      ),
      'status' => 
      array (
        'name' => 'status',
        'default' => 
        array (
          'code' => '302',
          'attributes' => 
          array (
            'startLine' => 708,
            'endLine' => 708,
            'startTokenPos' => 3159,
            'startFilePos' => 19326,
            'endTokenPos' => 3159,
            'endFilePos' => 19328,
          ),
        ),
        'type' => NULL,
        'isVariadic' => false,
        'byRef' => false,
        'isPromoted' => false,
        'attributes' => 
        array (
        ),
        'startLine' => 708,
        'endLine' => 708,
        'startColumn' => 35,
        'endColumn' => 47,
        'parameterIndex' => 1,
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
            'startLine' => 708,
            'endLine' => 708,
            'startTokenPos' => 3166,
            'startFilePos' => 19342,
            'endTokenPos' => 3167,
            'endFilePos' => 19343,
          ),
        ),
        'type' => NULL,
        'isVariadic' => false,
        'byRef' => false,
        'isPromoted' => false,
        'attributes' => 
        array (
        ),
        'startLine' => 708,
        'endLine' => 708,
        'startColumn' => 50,
        'endColumn' => 62,
        'parameterIndex' => 2,
        'isOptional' => true,
      ),
      'secure' => 
      array (
        'name' => 'secure',
        'default' => 
        array (
          'code' => '\\null',
          'attributes' => 
          array (
            'startLine' => 708,
            'endLine' => 708,
            'startTokenPos' => 3174,
            'startFilePos' => 19356,
            'endTokenPos' => 3174,
            'endFilePos' => 19359,
          ),
        ),
        'type' => NULL,
        'isVariadic' => false,
        'byRef' => false,
        'isPromoted' => false,
        'attributes' => 
        array (
        ),
        'startLine' => 708,
        'endLine' => 708,
        'startColumn' => 65,
        'endColumn' => 78,
        'parameterIndex' => 3,
        'isOptional' => true,
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
              'name' => 'Illuminate\\Routing\\Redirector',
              'isIdentifier' => false,
            ),
          ),
          1 => 
          array (
            'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
            'data' => 
            array (
              'name' => 'Illuminate\\Http\\RedirectResponse',
              'isIdentifier' => false,
            ),
          ),
        ),
      ),
    ),
    'attributes' => 
    array (
    ),
    'docComment' => '/**
 * Get an instance of the redirector.
 *
 * @param  string|null  $to
 * @param  int  $status
 * @param  array  $headers
 * @param  bool|null  $secure
 * @return ($to is null ? \\Illuminate\\Routing\\Redirector : \\Illuminate\\Http\\RedirectResponse)
 */',
    'startLine' => 708,
    'endLine' => 715,
    'startColumn' => 5,
    'endColumn' => 5,
    'couldThrow' => false,
    'isClosure' => false,
    'isGenerator' => false,
    'isVariadic' => false,
    'isStatic' => false,
    'namespace' => NULL,
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'redirect',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../laravel/framework/src/Illuminate/Foundation/helpers.php',
      ),
    ),
  ),
));