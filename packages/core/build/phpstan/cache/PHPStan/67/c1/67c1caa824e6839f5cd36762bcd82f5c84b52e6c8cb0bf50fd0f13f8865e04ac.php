<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/DnsResolverService.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\DnsResolverService
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-09eebf267bd23abb0d306685fd8ca62db528c6b3b0caabb24f2ab0859e770aed',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/DnsResolverService.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
    'shortName' => 'DnsResolverService',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 11,
    'endLine' => 222,
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
      'resolverDir' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'name' => 'resolverDir',
        'modifiers' => 2,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '\'/etc/resolver\'',
          'attributes' => 
          array (
            'startLine' => 13,
            'endLine' => 13,
            'startTokenPos' => 44,
            'startFilePos' => 262,
            'endTokenPos' => 44,
            'endFilePos' => 276,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 13,
        'endLine' => 13,
        'startColumn' => 5,
        'endColumn' => 52,
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
      'updateResolver' => 
      array (
        'name' => 'updateResolver',
        'parameters' => 
        array (
          'environment' => 
          array (
            'name' => 'environment',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 19,
            'endLine' => 19,
            'startColumn' => 36,
            'endColumn' => 59,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'tld' => 
          array (
            'name' => 'tld',
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
            'startLine' => 19,
            'endLine' => 19,
            'startColumn' => 62,
            'endColumn' => 72,
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
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Update the DNS resolver configuration for an environment\'s TLD.
 * Creates/updates /etc/resolver/{tld} to point to the correct DNS server.
 */',
        'startLine' => 19,
        'endLine' => 88,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'aliasName' => NULL,
      ),
      'removeResolver' => 
      array (
        'name' => 'removeResolver',
        'parameters' => 
        array (
          'tld' => 
          array (
            'name' => 'tld',
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
            'startLine' => 93,
            'endLine' => 93,
            'startColumn' => 36,
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
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Remove a DNS resolver file for a TLD.
 */',
        'startLine' => 93,
        'endLine' => 149,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'aliasName' => NULL,
      ),
      'getDnsServer' => 
      array (
        'name' => 'getDnsServer',
        'parameters' => 
        array (
          'environment' => 
          array (
            'name' => 'environment',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Models\\Environment',
                'isIdentifier' => false,
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
            'startColumn' => 37,
            'endColumn' => 60,
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
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the DNS server address for an environment.
 * Local environments use 127.0.0.1, remote environments use their host IP.
 */',
        'startLine' => 155,
        'endLine' => 178,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'aliasName' => NULL,
      ),
      'getManagedResolvers' => 
      array (
        'name' => 'getManagedResolvers',
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
 * Get all currently configured resolvers managed by Orbit.
 */',
        'startLine' => 183,
        'endLine' => 202,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'aliasName' => NULL,
      ),
      'syncAllResolvers' => 
      array (
        'name' => 'syncAllResolvers',
        'parameters' => 
        array (
          'configService' => 
          array (
            'name' => 'configService',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'App\\Services\\OrbitCli\\ConfigurationService',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 207,
            'endLine' => 207,
            'startColumn' => 38,
            'endColumn' => 95,
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
 * Sync all resolver files with current environment configurations.
 */',
        'startLine' => 207,
        'endLine' => 221,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\DnsResolverService',
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