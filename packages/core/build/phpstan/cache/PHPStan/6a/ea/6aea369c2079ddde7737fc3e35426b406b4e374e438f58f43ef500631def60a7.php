<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/TemplateAnalyzer/TemplateAnalyzerService.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\TemplateAnalyzer\TemplateAnalyzerService
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-dd6d4b133ea6a447fdef3992c72e690c0c2aef45918bcafc1b08c9ae2a3554e1',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/TemplateAnalyzer/TemplateAnalyzerService.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
    'shortName' => 'TemplateAnalyzerService',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 32,
    'docComment' => '/**
 * Service for analyzing GitHub templates and detecting their configuration.
 *
 * Supports multiple project types through pluggable analyzers.
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 15,
    'endLine' => 182,
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
      'analyzers' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'name' => 'analyzers',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'default' => 
        array (
          'code' => '[]',
          'attributes' => 
          array (
            'startLine' => 20,
            'endLine' => 20,
            'startTokenPos' => 45,
            'startFilePos' => 502,
            'endTokenPos' => 46,
            'endFilePos' => 503,
          ),
        ),
        'docComment' => '/**
 * @var array<TemplateAnalyzerInterface>
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 20,
        'endLine' => 20,
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
      'registerAnalyzer' => 
      array (
        'name' => 'registerAnalyzer',
        'parameters' => 
        array (
          'analyzer' => 
          array (
            'name' => 'analyzer',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 25,
            'endLine' => 25,
            'startColumn' => 38,
            'endColumn' => 72,
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
 * Register an analyzer.
 */',
        'startLine' => 25,
        'endLine' => 30,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'aliasName' => NULL,
      ),
      'analyze' => 
      array (
        'name' => 'analyze',
        'parameters' => 
        array (
          'template' => 
          array (
            'name' => 'template',
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
            'startLine' => 38,
            'endLine' => 38,
            'startColumn' => 29,
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
            'name' => 'array',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Analyze a GitHub template repository.
 *
 * @param  string  $template  Repository in owner/repo format or full GitHub URL
 * @return array{success: bool, data?: array<string, mixed>, error?: string}
 */',
        'startLine' => 38,
        'endLine' => 83,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'aliasName' => NULL,
      ),
      'extractRepo' => 
      array (
        'name' => 'extractRepo',
        'parameters' => 
        array (
          'template' => 
          array (
            'name' => 'template',
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
            'startLine' => 88,
            'endLine' => 88,
            'startColumn' => 33,
            'endColumn' => 48,
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
 * Extract owner/repo from template string.
 */',
        'startLine' => 88,
        'endLine' => 114,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'aliasName' => NULL,
      ),
      'detectDefaultBranch' => 
      array (
        'name' => 'detectDefaultBranch',
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
            'startLine' => 119,
            'endLine' => 119,
            'startColumn' => 42,
            'endColumn' => 53,
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
 * Detect the default branch of a repository.
 */',
        'startLine' => 119,
        'endLine' => 142,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'aliasName' => NULL,
      ),
      'getRepoContents' => 
      array (
        'name' => 'getRepoContents',
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
            'startLine' => 149,
            'endLine' => 149,
            'startColumn' => 38,
            'endColumn' => 49,
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
            'startLine' => 149,
            'endLine' => 149,
            'startColumn' => 52,
            'endColumn' => 65,
            'parameterIndex' => 1,
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
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the list of files in the repository root.
 *
 * @return array<string>|null
 */',
        'startLine' => 149,
        'endLine' => 167,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'aliasName' => NULL,
      ),
      'findAnalyzer' => 
      array (
        'name' => 'findAnalyzer',
        'parameters' => 
        array (
          'contents' => 
          array (
            'name' => 'contents',
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
            'startLine' => 172,
            'endLine' => 172,
            'startColumn' => 35,
            'endColumn' => 49,
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
                  'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
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
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Find an analyzer that supports this project type.
 */',
        'startLine' => 172,
        'endLine' => 181,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\TemplateAnalyzerService',
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