<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Deletion/DeletionPipeline.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Services\Deletion\DeletionPipeline
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-7f9c9f47b16e5a2447f3f6886eb3d3aa1bfed6a6149f9d4b5a086ae5c63bb18c',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Services/Deletion/DeletionPipeline.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Deletion',
    'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
    'shortName' => 'DeletionPipeline',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 65536,
    'docComment' => '/**
 * Pipeline for running deletion actions in sequence.
 *
 * Orchestrates the complete project deletion process including:
 * - Database cleanup
 * - Project file deletion
 * - Caddy configuration regeneration
 *
 * Note: Project model deletion is handled by the caller (CLI or Job)
 * to allow for transaction control.
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 25,
    'endLine' => 76,
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
      'dropPostgresDatabase' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'name' => 'dropPostgresDatabase',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DropPostgresDatabase',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 28,
        'endLine' => 28,
        'startColumn' => 9,
        'endColumn' => 58,
        'isPromoted' => true,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'deleteProjectFiles' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'name' => 'deleteProjectFiles',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DeleteProjectFiles',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 29,
        'endLine' => 29,
        'startColumn' => 9,
        'endColumn' => 54,
        'isPromoted' => true,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'regenerateCaddyConfig' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'name' => 'regenerateCaddyConfig',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\RegenerateCaddyConfig',
            'isIdentifier' => false,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 30,
        'endLine' => 30,
        'startColumn' => 9,
        'endColumn' => 60,
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
          'dropPostgresDatabase' => 
          array (
            'name' => 'dropPostgresDatabase',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DropPostgresDatabase',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 28,
            'endLine' => 28,
            'startColumn' => 9,
            'endColumn' => 58,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'deleteProjectFiles' => 
          array (
            'name' => 'deleteProjectFiles',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\DeleteProjectFiles',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 29,
            'endLine' => 29,
            'startColumn' => 9,
            'endColumn' => 54,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'regenerateCaddyConfig' => 
          array (
            'name' => 'regenerateCaddyConfig',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\Actions\\RegenerateCaddyConfig',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => true,
            'attributes' => 
            array (
            ),
            'startLine' => 30,
            'endLine' => 30,
            'startColumn' => 9,
            'endColumn' => 60,
            'parameterIndex' => 2,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 27,
        'endLine' => 31,
        'startColumn' => 5,
        'endColumn' => 8,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Deletion',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'aliasName' => NULL,
      ),
      'run' => 
      array (
        'name' => 'run',
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
            'startLine' => 36,
            'endLine' => 36,
            'startColumn' => 25,
            'endColumn' => 48,
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
            'startLine' => 36,
            'endLine' => 36,
            'startColumn' => 51,
            'endColumn' => 81,
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
        'docComment' => '/**
 * Run the full deletion pipeline.
 */',
        'startLine' => 36,
        'endLine' => 75,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Services\\Deletion',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Services\\Deletion\\DeletionPipeline',
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