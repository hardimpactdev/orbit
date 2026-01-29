<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/HorizonService.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\HorizonService
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-255cd9971fcc69b6f9a80db3b725d63e6ce0141b16b52f9971f4cac07d51ce19',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/HorizonService.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
    'shortName' => 'HorizonService',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * Service for managing Horizon queue workers.
 * Handles both production (orbit-horizon) and dev (orbit-horizon-dev) instances.
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 12,
    'endLine' => 168,
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
      'isDevInstance' => 
      array (
        'name' => 'isDevInstance',
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
 * Determine which horizon instance to use based on the app context.
 */',
        'startLine' => 17,
        'endLine' => 20,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'getServiceName' => 
      array (
        'name' => 'getServiceName',
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
 * Get the systemd service name for the current context.
 */',
        'startLine' => 25,
        'endLine' => 28,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'getServiceKey' => 
      array (
        'name' => 'getServiceKey',
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
 * Get the service key used in the UI.
 */',
        'startLine' => 33,
        'endLine' => 36,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'isRunning' => 
      array (
        'name' => 'isRunning',
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
 * Check if horizon is running.
 */',
        'startLine' => 41,
        'endLine' => 59,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'start' => 
      array (
        'name' => 'start',
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
 * Start horizon service.
 */',
        'startLine' => 64,
        'endLine' => 81,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'stop' => 
      array (
        'name' => 'stop',
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
 * Stop horizon service.
 */',
        'startLine' => 86,
        'endLine' => 103,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'restart' => 
      array (
        'name' => 'restart',
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
 * Restart horizon service.
 */',
        'startLine' => 108,
        'endLine' => 124,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'getLogs' => 
      array (
        'name' => 'getLogs',
        'parameters' => 
        array (
          'lines' => 
          array (
            'name' => 'lines',
            'default' => 
            array (
              'code' => '100',
              'attributes' => 
              array (
                'startLine' => 129,
                'endLine' => 129,
                'startTokenPos' => 642,
                'startFilePos' => 3312,
                'endTokenPos' => 642,
                'endFilePos' => 3314,
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
            'startLine' => 129,
            'endLine' => 129,
            'startColumn' => 29,
            'endColumn' => 44,
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
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get horizon logs.
 */',
        'startLine' => 129,
        'endLine' => 152,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'aliasName' => NULL,
      ),
      'getStatusInfo' => 
      array (
        'name' => 'getStatusInfo',
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
 * Get status info for the services list.
 */',
        'startLine' => 157,
        'endLine' => 167,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\HorizonService',
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