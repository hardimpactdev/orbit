<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/TemplateAnalyzer/Analyzers/LaravelTemplateAnalyzer.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\TemplateAnalyzer\Analyzers\LaravelTemplateAnalyzer
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-ffe7a32b40a39768e46e0d3d9380d43ddff2ae65a65386b7da2cc9b695bd524d',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/TemplateAnalyzer/Analyzers/LaravelTemplateAnalyzer.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
    'shortName' => 'LaravelTemplateAnalyzer',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 65568,
    'docComment' => '/**
 * Analyzer for Laravel templates.
 *
 * Detects Laravel projects by presence of artisan file and Laravel dependencies.
 * Extracts driver configurations from .env.example.
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 18,
    'endLine' => 255,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
      0 => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
      'ENV_KEY_MAP' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'name' => 'ENV_KEY_MAP',
        'modifiers' => 4,
        'type' => NULL,
        'value' => 
        array (
          'code' => '[
    \'DB_CONNECTION\' => \'db_driver\',
    \'SESSION_DRIVER\' => \'session_driver\',
    \'CACHE_STORE\' => \'cache_driver\',
    \'CACHE_DRIVER\' => \'cache_driver\',
    // Legacy key, CACHE_STORE takes precedence
    \'QUEUE_CONNECTION\' => \'queue_driver\',
]',
          'attributes' => 
          array (
            'startLine' => 23,
            'endLine' => 29,
            'startTokenPos' => 61,
            'startFilePos' => 745,
            'endTokenPos' => 100,
            'endFilePos' => 1010,
          ),
        ),
        'docComment' => '/**
 * Laravel .env keys that map to our driver configuration.
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 23,
        'endLine' => 29,
        'startColumn' => 5,
        'endColumn' => 6,
      ),
    ),
    'immediateProperties' => 
    array (
      'envParser' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'name' => 'envParser',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\EnvParser',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 32,
        'endLine' => 32,
        'startColumn' => 9,
        'endColumn' => 36,
        'isPromoted' => true,
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
          'envParser' => 
          array (
            'name' => 'envParser',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\EnvParser',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 32,
            'endLine' => 32,
            'startColumn' => 9,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 31,
        'endLine' => 33,
        'startColumn' => 5,
        'endColumn' => 8,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'supports' => 
      array (
        'name' => 'supports',
        'parameters' => 
        array (
          'repoContents' => 
          array (
            'name' => 'repoContents',
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
            'startLine' => 35,
            'endLine' => 35,
            'startColumn' => 30,
            'endColumn' => 48,
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
        'docComment' => NULL,
        'startLine' => 35,
        'endLine' => 46,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'getType' => 
      array (
        'name' => 'getType',
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
        'startLine' => 48,
        'endLine' => 51,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'analyze' => 
      array (
        'name' => 'analyze',
        'parameters' => 
        array (
          'repo' => 
          array (
            'name' => 'repo',
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
            'startLine' => 53,
            'endLine' => 53,
            'startColumn' => 29,
            'endColumn' => 40,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'branch' => 
          array (
            'name' => 'branch',
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
            'startLine' => 53,
            'endLine' => 53,
            'startColumn' => 43,
            'endColumn' => 56,
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
        'docComment' => NULL,
        'startLine' => 53,
        'endLine' => 84,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'parseEnvDrivers' => 
      array (
        'name' => 'parseEnvDrivers',
        'parameters' => 
        array (
          'envContent' => 
          array (
            'name' => 'envContent',
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
            'startLine' => 91,
            'endLine' => 91,
            'startColumn' => 38,
            'endColumn' => 55,
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
 * Parse .env content and extract driver configurations.
 *
 * @return array<string, string|null>
 */',
        'startLine' => 91,
        'endLine' => 119,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'normalizeDriverValue' => 
      array (
        'name' => 'normalizeDriverValue',
        'parameters' => 
        array (
          'driverKey' => 
          array (
            'name' => 'driverKey',
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
            'startLine' => 124,
            'endLine' => 124,
            'startColumn' => 43,
            'endColumn' => 59,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
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
            'startLine' => 124,
            'endLine' => 124,
            'startColumn' => 62,
            'endColumn' => 74,
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
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Normalize driver values to our standard format.
 */',
        'startLine' => 124,
        'endLine' => 165,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'extractComposerMetadata' => 
      array (
        'name' => 'extractComposerMetadata',
        'parameters' => 
        array (
          'composer' => 
          array (
            'name' => 'composer',
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
            'startLine' => 173,
            'endLine' => 173,
            'startColumn' => 46,
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
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Extract metadata from composer.json.
 *
 * @param  array<string, mixed>  $composer
 * @return array<string, mixed>
 */',
        'startLine' => 173,
        'endLine' => 193,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'parseVersionConstraint' => 
      array (
        'name' => 'parseVersionConstraint',
        'parameters' => 
        array (
          'constraint' => 
          array (
            'name' => 'constraint',
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
            'startLine' => 198,
            'endLine' => 198,
            'startColumn' => 45,
            'endColumn' => 62,
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
 * Parse a composer version constraint to extract a readable version.
 */',
        'startLine' => 198,
        'endLine' => 209,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'detectCommonPackages' => 
      array (
        'name' => 'detectCommonPackages',
        'parameters' => 
        array (
          'require' => 
          array (
            'name' => 'require',
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
            'startLine' => 217,
            'endLine' => 217,
            'startColumn' => 43,
            'endColumn' => 56,
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
 * Detect common Laravel packages for context.
 *
 * @param  array<string, string>  $require
 * @return array<string>
 */',
        'startLine' => 217,
        'endLine' => 238,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'aliasName' => NULL,
      ),
      'fetchFile' => 
      array (
        'name' => 'fetchFile',
        'parameters' => 
        array (
          'repo' => 
          array (
            'name' => 'repo',
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
            'startLine' => 243,
            'endLine' => 243,
            'startColumn' => 32,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'branch' => 
          array (
            'name' => 'branch',
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
            'startLine' => 243,
            'endLine' => 243,
            'startColumn' => 46,
            'endColumn' => 59,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'path' => 
          array (
            'name' => 'path',
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
            'startLine' => 243,
            'endLine' => 243,
            'startColumn' => 62,
            'endColumn' => 73,
            'parameterIndex' => 2,
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
 * Fetch a file from GitHub.
 */',
        'startLine' => 243,
        'endLine' => 254,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Analyzers\\LaravelTemplateAnalyzer',
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