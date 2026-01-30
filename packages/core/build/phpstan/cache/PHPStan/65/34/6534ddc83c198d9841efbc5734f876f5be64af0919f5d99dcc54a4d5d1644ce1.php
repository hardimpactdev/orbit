<?php declare(strict_types = 1);

// odsl-/home/nckrtl/projects/orbit-dev/packages/core/src/Models/TemplateFavorite.php-PHPStan\BetterReflection\Reflection\ReflectionClass-HardImpact\Orbit\Core\Models\TemplateFavorite
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-6.65.0.9-8.5.2-ec71706ed38f24cda5ee2838d1ee2cf445521be00988f6ccd09b76cbe7007198',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/core/src/Models/TemplateFavorite.php',
      ),
    ),
    'namespace' => 'HardImpact\\Orbit\\Core\\Models',
    'name' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
    'shortName' => 'TemplateFavorite',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @property int $id
 * @property string $repo_url
 * @property string $display_name
 * @property int $usage_count
 * @property \\Carbon\\Carbon|null $last_used_at
 * @property string|null $db_driver
 * @property string|null $session_driver
 * @property string|null $cache_driver
 * @property string|null $queue_driver
 * @property \\Carbon\\Carbon $created_at
 * @property \\Carbon\\Carbon $updated_at
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 22,
    'endLine' => 67,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Database\\Eloquent\\Model',
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
      'fillable' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'name' => 'fillable',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'repo_url\', \'display_name\', \'usage_count\', \'last_used_at\', \'db_driver\', \'session_driver\', \'cache_driver\', \'queue_driver\']',
          'attributes' => 
          array (
            'startLine' => 24,
            'endLine' => 33,
            'startTokenPos' => 38,
            'startFilePos' => 585,
            'endTokenPos' => 64,
            'endFilePos' => 777,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 24,
        'endLine' => 33,
        'startColumn' => 5,
        'endColumn' => 6,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'casts' => 
      array (
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'name' => 'casts',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[\'last_used_at\' => \'datetime\']',
          'attributes' => 
          array (
            'startLine' => 35,
            'endLine' => 37,
            'startTokenPos' => 73,
            'startFilePos' => 804,
            'endTokenPos' => 82,
            'endFilePos' => 848,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 35,
        'endLine' => 37,
        'startColumn' => 5,
        'endColumn' => 6,
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
      'recordUsage' => 
      array (
        'name' => 'recordUsage',
        'parameters' => 
        array (
          'drivers' => 
          array (
            'name' => 'drivers',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 39,
                'endLine' => 39,
                'startTokenPos' => 97,
                'startFilePos' => 901,
                'endTokenPos' => 98,
                'endFilePos' => 902,
              ),
            ),
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
            'startLine' => 39,
            'endLine' => 39,
            'startColumn' => 33,
            'endColumn' => 51,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 39,
        'endLine' => 52,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'aliasName' => NULL,
      ),
      'recentlyUsed' => 
      array (
        'name' => 'recentlyUsed',
        'parameters' => 
        array (
          'limit' => 
          array (
            'name' => 'limit',
            'default' => 
            array (
              'code' => '10',
              'attributes' => 
              array (
                'startLine' => 54,
                'endLine' => 54,
                'startTokenPos' => 218,
                'startFilePos' => 1324,
                'endTokenPos' => 218,
                'endFilePos' => 1325,
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
            'startLine' => 54,
            'endLine' => 54,
            'startColumn' => 41,
            'endColumn' => 55,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 54,
        'endLine' => 59,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'aliasName' => NULL,
      ),
      'mostUsed' => 
      array (
        'name' => 'mostUsed',
        'parameters' => 
        array (
          'limit' => 
          array (
            'name' => 'limit',
            'default' => 
            array (
              'code' => '10',
              'attributes' => 
              array (
                'startLine' => 61,
                'endLine' => 61,
                'startTokenPos' => 260,
                'startFilePos' => 1490,
                'endTokenPos' => 260,
                'endFilePos' => 1491,
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
            'startLine' => 61,
            'endLine' => 61,
            'startColumn' => 37,
            'endColumn' => 51,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 61,
        'endLine' => 66,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'HardImpact\\Orbit\\Core\\Models',
        'declaringClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'implementingClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
        'currentClassName' => 'HardImpact\\Orbit\\Core\\Models\\TemplateFavorite',
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