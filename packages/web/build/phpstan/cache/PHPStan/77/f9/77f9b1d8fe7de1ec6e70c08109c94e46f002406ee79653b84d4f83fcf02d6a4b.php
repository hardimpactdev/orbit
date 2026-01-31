<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/web/vendor/composer/../laravel/framework/src/Illuminate/Support/helpers.php-PHPStan\BetterReflection\Reflection\ReflectionFunction-optional
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-29447347198c1ea5939b1c969459d8620c2827cf7db9230fbb00a059816cc97f',
   'data' => 
  array (
    'name' => 'optional',
    'parameters' => 
    array (
      'value' => 
      array (
        'name' => 'value',
        'default' => 
        array (
          'code' => '\\null',
          'attributes' => 
          array (
            'startLine' => 270,
            'endLine' => 270,
            'startTokenPos' => 1185,
            'startFilePos' => 6668,
            'endTokenPos' => 1185,
            'endFilePos' => 6671,
          ),
        ),
        'type' => NULL,
        'isVariadic' => false,
        'byRef' => false,
        'isPromoted' => false,
        'attributes' => 
        array (
        ),
        'startLine' => 270,
        'endLine' => 270,
        'startColumn' => 23,
        'endColumn' => 35,
        'parameterIndex' => 0,
        'isOptional' => true,
      ),
      'callback' => 
      array (
        'name' => 'callback',
        'default' => 
        array (
          'code' => '\\null',
          'attributes' => 
          array (
            'startLine' => 270,
            'endLine' => 270,
            'startTokenPos' => 1195,
            'startFilePos' => 6696,
            'endTokenPos' => 1195,
            'endFilePos' => 6699,
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
        'startLine' => 270,
        'endLine' => 270,
        'startColumn' => 38,
        'endColumn' => 63,
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
 * Provide access to optional objects.
 *
 * @template TValue
 * @template TReturn
 *
 * @param  TValue  $value
 * @param  (callable(TValue): TReturn)|null  $callback
 * @return ($callback is null ? \\Illuminate\\Support\\Optional : ($value is null ? null : TReturn))
 */',
    'startLine' => 270,
    'endLine' => 277,
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
        'name' => 'optional',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/web/vendor/composer/../laravel/framework/src/Illuminate/Support/helpers.php',
      ),
    ),
  ),
));