<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/TemplateAnalyzer/Contracts/TemplateAnalyzerInterface.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\TemplateAnalyzer\Contracts\TemplateAnalyzerInterface
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-b50ef524ee2feaa117a30515f3916a194a55f7e70a25def8cebcc1e5ac4ecb7b',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/TemplateAnalyzer/Contracts/TemplateAnalyzerInterface.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
    'shortName' => 'TemplateAnalyzerInterface',
    'isInterface' => true,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
    ),
    'startLine' => 7,
    'endLine' => 29,
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
            'startLine' => 14,
            'endLine' => 14,
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
        'docComment' => '/**
 * Check if this analyzer can handle the given template.
 *
 * @param  array<string, mixed>  $repoContents  List of files/directories in the repo root
 */',
        'startLine' => 14,
        'endLine' => 14,
        'startColumn' => 5,
        'endColumn' => 56,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
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
        'docComment' => '/**
 * Get the project type identifier.
 */',
        'startLine' => 19,
        'endLine' => 19,
        'startColumn' => 5,
        'endColumn' => 38,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
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
            'startLine' => 28,
            'endLine' => 28,
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
            'startLine' => 28,
            'endLine' => 28,
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
        'docComment' => '/**
 * Analyze the template and extract configuration defaults.
 *
 * @param  string  $repo  The repository in owner/repo format
 * @param  string  $branch  The branch to analyze
 * @return array<string, mixed> The extracted configuration
 */',
        'startLine' => 28,
        'endLine' => 28,
        'startColumn' => 5,
        'endColumn' => 65,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\TemplateAnalyzer\\Contracts\\TemplateAnalyzerInterface',
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