<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../laravel/framework/src/Illuminate/Foundation/Console/DownCommand.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Foundation\Console\DownCommand
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-a2f27df7c8a4865b823e9516b34a674f27044b33f018feb4c2fe74e13cdfe074-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../laravel/framework/src/Illuminate/Foundation/Console/DownCommand.php',
      ),
    ),
    'namespace' => 'Illuminate\\Foundation\\Console',
    'name' => 'Illuminate\\Foundation\\Console\\DownCommand',
    'shortName' => 'DownCommand',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
      0 => 
      array (
        'name' => 'Symfony\\Component\\Console\\Attribute\\AsCommand',
        'isRepeated' => false,
        'arguments' => 
        array (
          'name' => 
          array (
            'code' => '\'down\'',
            'attributes' => 
            array (
              'startLine' => 16,
              'endLine' => 16,
              'startTokenPos' => 63,
              'startFilePos' => 439,
              'endTokenPos' => 63,
              'endFilePos' => 444,
            ),
          ),
        ),
      ),
    ),
    'startLine' => 16,
    'endLine' => 178,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Console\\Command',
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
      'signature' => 
      array (
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'name' => 'signature',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '\'down {--redirect= : The path that users should be redirected to}
                                 {--render= : The view that should be prerendered for display during maintenance mode}
                                 {--retry= : The number of seconds or the datetime after which the request may be retried}
                                 {--refresh= : The number of seconds after which the browser may refresh}
                                 {--secret= : The secret phrase that may be used to bypass maintenance mode}
                                 {--with-secret : Generate a random secret phrase that may be used to bypass maintenance mode}
                                 {--status=503 : The status code that should be used when returning the maintenance mode response}\'',
          'attributes' => 
          array (
            'startLine' => 24,
            'endLine' => 30,
            'startTokenPos' => 85,
            'startFilePos' => 591,
            'endTokenPos' => 85,
            'endFilePos' => 1371,
          ),
        ),
        'docComment' => '/**
 * The console command signature.
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 24,
        'endLine' => 30,
        'startColumn' => 5,
        'endColumn' => 132,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'description' => 
      array (
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'name' => 'description',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '\'Put the application into maintenance / demo mode\'',
          'attributes' => 
          array (
            'startLine' => 37,
            'endLine' => 37,
            'startTokenPos' => 96,
            'startFilePos' => 1486,
            'endTokenPos' => 96,
            'endFilePos' => 1535,
          ),
        ),
        'docComment' => '/**
 * The console command description.
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 37,
        'endLine' => 37,
        'startColumn' => 5,
        'endColumn' => 80,
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
      'handle' => 
      array (
        'name' => 'handle',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Execute the console command.
 *
 * @return int
 */',
        'startLine' => 44,
        'endLine' => 77,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Foundation\\Console',
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'currentClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'aliasName' => NULL,
      ),
      'getDownFilePayload' => 
      array (
        'name' => 'getDownFilePayload',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the payload to be placed in the "down" file.
 *
 * @return array
 */',
        'startLine' => 84,
        'endLine' => 95,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Console',
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'currentClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'aliasName' => NULL,
      ),
      'excludedPaths' => 
      array (
        'name' => 'excludedPaths',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the paths that should be excluded from maintenance mode.
 *
 * @return array
 */',
        'startLine' => 102,
        'endLine' => 109,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Console',
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'currentClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'aliasName' => NULL,
      ),
      'redirectPath' => 
      array (
        'name' => 'redirectPath',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the path that users should be redirected to.
 *
 * @return string
 */',
        'startLine' => 116,
        'endLine' => 123,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Console',
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'currentClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'aliasName' => NULL,
      ),
      'prerenderView' => 
      array (
        'name' => 'prerenderView',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Prerender the specified view so that it can be rendered even before loading Composer.
 *
 * @return string
 */',
        'startLine' => 130,
        'endLine' => 137,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Console',
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'currentClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'aliasName' => NULL,
      ),
      'getRetryTime' => 
      array (
        'name' => 'getRetryTime',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the number of seconds or date / time the client should wait before retrying their request.
 *
 * @return int|string|null
 */',
        'startLine' => 144,
        'endLine' => 163,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Console',
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'currentClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'aliasName' => NULL,
      ),
      'getSecret' => 
      array (
        'name' => 'getSecret',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the secret phrase that may be used to bypass maintenance mode.
 *
 * @return string|null
 */',
        'startLine' => 170,
        'endLine' => 177,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Foundation\\Console',
        'declaringClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'implementingClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
        'currentClassName' => 'Illuminate\\Foundation\\Console\\DownCommand',
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