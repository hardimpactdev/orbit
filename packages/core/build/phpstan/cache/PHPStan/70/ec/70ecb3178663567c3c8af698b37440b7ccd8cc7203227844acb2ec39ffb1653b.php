<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Deletion/Actions/DeleteProjectFiles.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\Deletion\Actions\DeleteProjectFiles
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-1e9722784a42510531090e26ef253efd9e1d6550d5a839b0744b3ecf260a5caf',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DeleteProjectFiles',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Deletion/Actions/DeleteProjectFiles.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DeleteProjectFiles',
    'shortName' => 'DeleteProjectFiles',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 65568,
    'docComment' => '/**
 * Action to delete project files from the filesystem.
 *
 * Attempts normal deletion first, then falls back to sudo
 * if permission errors occur (e.g., files created by PHP container).
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 18,
    'endLine' => 68,
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
      'handle' => 
      array (
        'name' => 'handle',
        'parameters' => 
        array (
          'context' => 
          array (
            'name' => 'context',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Data\\DeletionContext',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 20,
            'endLine' => 20,
            'startColumn' => 28,
            'endColumn' => 51,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'logger' => 
          array (
            'name' => 'logger',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Contracts\\ProvisionLoggerContract',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 20,
            'endLine' => 20,
            'startColumn' => 54,
            'endColumn' => 84,
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
            'name' => 'HardImpact\\Orbit\\Core\\Data\\StepResult',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 20,
        'endLine' => 67,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DeleteProjectFiles',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DeleteProjectFiles',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DeleteProjectFiles',
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