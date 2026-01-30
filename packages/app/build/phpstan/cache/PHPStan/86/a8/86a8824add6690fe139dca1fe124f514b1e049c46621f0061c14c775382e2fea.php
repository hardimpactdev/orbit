<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/app/src/Http/Middleware/HandleInertiaRequests.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Ui\Http\Middleware\HandleInertiaRequests
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-7aa1c810feca7ef58ef96d529807233a29dfe8a3d0787b1d9187482c6debc8f8',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/src/Http/Middleware/HandleInertiaRequests.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware',
    'name' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
    'shortName' => 'HandleInertiaRequests',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 10,
    'endLine' => 211,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Inertia\\Middleware',
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
      'rootView' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'name' => 'rootView',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The root template that\'s loaded on the first page visit.
 *
 * @see https://inertiajs.com/server-side-setup#root-template
 *
 * @var string
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 19,
        'endLine' => 19,
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
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 21,
        'endLine' => 24,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'aliasName' => NULL,
      ),
      'getReverbClientHost' => 
      array (
        'name' => 'getReverbClientHost',
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
        'docComment' => '/**
 * Get the client-side Reverb host based on APP_URL\'s TLD.
 * Server connects directly to 127.0.0.1:8080, but browsers need reverb.<tld> via Caddy.
 */',
        'startLine' => 30,
        'endLine' => 40,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'aliasName' => NULL,
      ),
      'version' => 
      array (
        'name' => 'version',
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
                'name' => 'Illuminate\\Http\\Request',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 47,
            'endLine' => 47,
            'startColumn' => 29,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => false,
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
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determines the current asset version.
 *
 * @see https://inertiajs.com/asset-versioning
 */',
        'startLine' => 47,
        'endLine' => 56,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'aliasName' => NULL,
      ),
      'getOrbitVersion' => 
      array (
        'name' => 'getOrbitVersion',
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
        'docComment' => '/**
 * Get the orbit-core version from git tags or commit hash.
 */',
        'startLine' => 61,
        'endLine' => 90,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'aliasName' => NULL,
      ),
      'share' => 
      array (
        'name' => 'share',
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
                'name' => 'Illuminate\\Http\\Request',
                'isIdentifier' => false,
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
            'startColumn' => 27,
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
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Define the props that are shared by default.
 *
 * @see https://inertiajs.com/shared-data
 *
 * @return array<string, mixed>
 */',
        'startLine' => 99,
        'endLine' => 210,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware',
        'declaringClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'implementingClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
        'currentClassName' => 'HardImpact\\Orbit\\Ui\\Http\\Middleware\\HandleInertiaRequests',
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