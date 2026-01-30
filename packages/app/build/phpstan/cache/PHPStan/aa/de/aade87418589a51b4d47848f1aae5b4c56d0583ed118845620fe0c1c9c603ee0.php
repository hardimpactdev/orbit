<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../laravel/framework/src/Illuminate/Collections/Collection.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Support\Collection
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-830f9de0b581224c1a2e5f202b89a83e26fe77b6c7f3524e56e83a404e1e6d58-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Support\\Collection',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/app/vendor/composer/../laravel/framework/src/Illuminate/Collections/Collection.php',
      ),
    ),
    'namespace' => 'Illuminate\\Support',
    'name' => 'Illuminate\\Support\\Collection',
    'shortName' => 'Collection',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => '/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @implements \\ArrayAccess<TKey, TValue>
 * @implements \\Illuminate\\Support\\Enumerable<TKey, TValue>
 */',
    'attributes' => 
    array (
    ),
    'startLine' => 23,
    'endLine' => 2015,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => NULL,
    'implementsClassNames' => 
    array (
      0 => 'ArrayAccess',
      1 => 'Illuminate\\Contracts\\Support\\CanBeEscapedWhenCastToString',
      2 => 'Illuminate\\Support\\Enumerable',
    ),
    'traitClassNames' => 
    array (
      0 => 'Illuminate\\Support\\Traits\\EnumeratesValues',
      1 => 'Illuminate\\Support\\Traits\\Macroable',
      2 => 'Illuminate\\Support\\Traits\\TransformsToResourceCollection',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'items' => 
      array (
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'name' => 'items',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '[]',
          'attributes' => 
          array (
            'startLine' => 35,
            'endLine' => 35,
            'startTokenPos' => 91,
            'startFilePos' => 891,
            'endTokenPos' => 92,
            'endFilePos' => 892,
          ),
        ),
        'docComment' => '/**
 * The items contained in the collection.
 *
 * @var array<TKey, TValue>
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 35,
        'endLine' => 35,
        'startColumn' => 5,
        'endColumn' => 26,
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
          'items' => 
          array (
            'name' => 'items',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 42,
                'endLine' => 42,
                'startTokenPos' => 107,
                'startFilePos' => 1097,
                'endTokenPos' => 108,
                'endFilePos' => 1098,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 42,
            'endLine' => 42,
            'startColumn' => 33,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new collection.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
 */',
        'startLine' => 42,
        'endLine' => 45,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'range' => 
      array (
        'name' => 'range',
        'parameters' => 
        array (
          'from' => 
          array (
            'name' => 'from',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 55,
            'endLine' => 55,
            'startColumn' => 34,
            'endColumn' => 38,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'to' => 
          array (
            'name' => 'to',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 55,
            'endLine' => 55,
            'startColumn' => 41,
            'endColumn' => 43,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'step' => 
          array (
            'name' => 'step',
            'default' => 
            array (
              'code' => '1',
              'attributes' => 
              array (
                'startLine' => 55,
                'endLine' => 55,
                'startTokenPos' => 149,
                'startFilePos' => 1404,
                'endTokenPos' => 149,
                'endFilePos' => 1404,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 55,
            'endLine' => 55,
            'startColumn' => 46,
            'endColumn' => 54,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a collection with the given range.
 *
 * @param  int  $from
 * @param  int  $to
 * @param  int  $step
 * @return static<int, int>
 */',
        'startLine' => 55,
        'endLine' => 58,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 17,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'all' => 
      array (
        'name' => 'all',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get all of the items in the collection.
 *
 * @return array<TKey, TValue>
 */',
        'startLine' => 65,
        'endLine' => 68,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'lazy' => 
      array (
        'name' => 'lazy',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a lazy collection for the items in this collection.
 *
 * @return \\Illuminate\\Support\\LazyCollection<TKey, TValue>
 */',
        'startLine' => 75,
        'endLine' => 78,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'median' => 
      array (
        'name' => 'median',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 86,
                'endLine' => 86,
                'startTokenPos' => 234,
                'startFilePos' => 2068,
                'endTokenPos' => 234,
                'endFilePos' => 2071,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 86,
            'endLine' => 86,
            'startColumn' => 28,
            'endColumn' => 38,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the median of a given key.
 *
 * @param  string|array<array-key, string>|null  $key
 * @return float|int|null
 */',
        'startLine' => 86,
        'endLine' => 107,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'mode' => 
      array (
        'name' => 'mode',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 115,
                'endLine' => 115,
                'startTokenPos' => 407,
                'startFilePos' => 2750,
                'endTokenPos' => 407,
                'endFilePos' => 2753,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 115,
            'endLine' => 115,
            'startColumn' => 26,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the mode of a given key.
 *
 * @param  string|array<array-key, string>|null  $key
 * @return array<int, float|int>|null
 */',
        'startLine' => 115,
        'endLine' => 133,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'collapse' => 
      array (
        'name' => 'collapse',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Collapse the collection of items into a single array.
 *
 * @return static<int, mixed>
 */',
        'startLine' => 140,
        'endLine' => 143,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'collapseWithKeys' => 
      array (
        'name' => 'collapseWithKeys',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Collapse the collection of items into a single array while preserving its keys.
 *
 * @return static<mixed, mixed>
 */',
        'startLine' => 150,
        'endLine' => 173,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'contains' => 
      array (
        'name' => 'contains',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 183,
            'endLine' => 183,
            'startColumn' => 30,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'operator' => 
          array (
            'name' => 'operator',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 183,
                'endLine' => 183,
                'startTokenPos' => 763,
                'startFilePos' => 4413,
                'endTokenPos' => 763,
                'endFilePos' => 4416,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 183,
            'endLine' => 183,
            'startColumn' => 36,
            'endColumn' => 51,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 183,
                'endLine' => 183,
                'startTokenPos' => 770,
                'startFilePos' => 4428,
                'endTokenPos' => 770,
                'endFilePos' => 4431,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 183,
            'endLine' => 183,
            'startColumn' => 54,
            'endColumn' => 66,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if an item exists in the collection.
 *
 * @param  (callable(TValue, TKey): bool)|TValue|string  $key
 * @param  mixed  $operator
 * @param  mixed  $value
 * @return bool
 */',
        'startLine' => 183,
        'endLine' => 194,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'containsStrict' => 
      array (
        'name' => 'containsStrict',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 203,
            'endLine' => 203,
            'startColumn' => 36,
            'endColumn' => 39,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 203,
                'endLine' => 203,
                'startTokenPos' => 867,
                'startFilePos' => 4988,
                'endTokenPos' => 867,
                'endFilePos' => 4991,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 203,
            'endLine' => 203,
            'startColumn' => 42,
            'endColumn' => 54,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if an item exists, using strict comparison.
 *
 * @param  (callable(TValue): bool)|TValue|array-key  $key
 * @param  TValue|null  $value
 * @return bool
 */',
        'startLine' => 203,
        'endLine' => 214,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'doesntContain' => 
      array (
        'name' => 'doesntContain',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 224,
            'endLine' => 224,
            'startColumn' => 35,
            'endColumn' => 38,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'operator' => 
          array (
            'name' => 'operator',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 224,
                'endLine' => 224,
                'startTokenPos' => 979,
                'startFilePos' => 5540,
                'endTokenPos' => 979,
                'endFilePos' => 5543,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 224,
            'endLine' => 224,
            'startColumn' => 41,
            'endColumn' => 56,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 224,
                'endLine' => 224,
                'startTokenPos' => 986,
                'startFilePos' => 5555,
                'endTokenPos' => 986,
                'endFilePos' => 5558,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 224,
            'endLine' => 224,
            'startColumn' => 59,
            'endColumn' => 71,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if an item is not contained in the collection.
 *
 * @param  mixed  $key
 * @param  mixed  $operator
 * @param  mixed  $value
 * @return bool
 */',
        'startLine' => 224,
        'endLine' => 227,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'doesntContainStrict' => 
      array (
        'name' => 'doesntContainStrict',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 237,
            'endLine' => 237,
            'startColumn' => 41,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'operator' => 
          array (
            'name' => 'operator',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 237,
                'endLine' => 237,
                'startTokenPos' => 1023,
                'startFilePos' => 5906,
                'endTokenPos' => 1023,
                'endFilePos' => 5909,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 237,
            'endLine' => 237,
            'startColumn' => 47,
            'endColumn' => 62,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 237,
                'endLine' => 237,
                'startTokenPos' => 1030,
                'startFilePos' => 5921,
                'endTokenPos' => 1030,
                'endFilePos' => 5924,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 237,
            'endLine' => 237,
            'startColumn' => 65,
            'endColumn' => 77,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if an item is not contained in the enumerable, using strict comparison.
 *
 * @param  mixed  $key
 * @param  mixed  $operator
 * @param  mixed  $value
 * @return bool
 */',
        'startLine' => 237,
        'endLine' => 240,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'crossJoin' => 
      array (
        'name' => 'crossJoin',
        'parameters' => 
        array (
          'lists' => 
          array (
            'name' => 'lists',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => true,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 251,
            'endLine' => 251,
            'startColumn' => 31,
            'endColumn' => 39,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Cross join with the given lists, returning all possible permutations.
 *
 * @template TCrossJoinKey
 * @template TCrossJoinValue
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TCrossJoinKey, TCrossJoinValue>|iterable<TCrossJoinKey, TCrossJoinValue>  ...$lists
 * @return static<int, array<int, TValue|TCrossJoinValue>>
 */',
        'startLine' => 251,
        'endLine' => 256,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'diff' => 
      array (
        'name' => 'diff',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 264,
            'endLine' => 264,
            'startColumn' => 26,
            'endColumn' => 31,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the items in the collection that are not present in the given items.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<array-key, TValue>|iterable<array-key, TValue>  $items
 * @return static
 */',
        'startLine' => 264,
        'endLine' => 267,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'diffUsing' => 
      array (
        'name' => 'diffUsing',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 276,
            'endLine' => 276,
            'startColumn' => 31,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 276,
            'endLine' => 276,
            'startColumn' => 39,
            'endColumn' => 56,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the items in the collection that are not present in the given items, using the callback.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<array-key, TValue>|iterable<array-key, TValue>  $items
 * @param  callable(TValue, TValue): int  $callback
 * @return static
 */',
        'startLine' => 276,
        'endLine' => 279,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'diffAssoc' => 
      array (
        'name' => 'diffAssoc',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 287,
            'endLine' => 287,
            'startColumn' => 31,
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
        'docComment' => '/**
 * Get the items in the collection whose keys and values are not present in the given items.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
 * @return static
 */',
        'startLine' => 287,
        'endLine' => 290,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'diffAssocUsing' => 
      array (
        'name' => 'diffAssocUsing',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 299,
            'endLine' => 299,
            'startColumn' => 36,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 299,
            'endLine' => 299,
            'startColumn' => 44,
            'endColumn' => 61,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the items in the collection whose keys and values are not present in the given items, using the callback.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
 * @param  callable(TKey, TKey): int  $callback
 * @return static
 */',
        'startLine' => 299,
        'endLine' => 302,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'diffKeys' => 
      array (
        'name' => 'diffKeys',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 310,
            'endLine' => 310,
            'startColumn' => 30,
            'endColumn' => 35,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the items in the collection whose keys are not present in the given items.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, mixed>|iterable<TKey, mixed>  $items
 * @return static
 */',
        'startLine' => 310,
        'endLine' => 313,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'diffKeysUsing' => 
      array (
        'name' => 'diffKeysUsing',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 322,
            'endLine' => 322,
            'startColumn' => 35,
            'endColumn' => 40,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 322,
            'endLine' => 322,
            'startColumn' => 43,
            'endColumn' => 60,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the items in the collection whose keys are not present in the given items, using the callback.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, mixed>|iterable<TKey, mixed>  $items
 * @param  callable(TKey, TKey): int  $callback
 * @return static
 */',
        'startLine' => 322,
        'endLine' => 325,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'duplicates' => 
      array (
        'name' => 'duplicates',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 336,
                'endLine' => 336,
                'startTokenPos' => 1366,
                'startFilePos' => 9410,
                'endTokenPos' => 1366,
                'endFilePos' => 9413,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 336,
            'endLine' => 336,
            'startColumn' => 32,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'strict' => 
          array (
            'name' => 'strict',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 336,
                'endLine' => 336,
                'startTokenPos' => 1373,
                'startFilePos' => 9426,
                'endTokenPos' => 1373,
                'endFilePos' => 9430,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 336,
            'endLine' => 336,
            'startColumn' => 50,
            'endColumn' => 64,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Retrieve duplicate items from the collection.
 *
 * @template TMapValue
 *
 * @param  (callable(TValue): TMapValue)|string|null  $callback
 * @param  bool  $strict
 * @return static
 */',
        'startLine' => 336,
        'endLine' => 355,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'duplicatesStrict' => 
      array (
        'name' => 'duplicatesStrict',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 365,
                'endLine' => 365,
                'startTokenPos' => 1519,
                'startFilePos' => 10221,
                'endTokenPos' => 1519,
                'endFilePos' => 10224,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 365,
            'endLine' => 365,
            'startColumn' => 38,
            'endColumn' => 53,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Retrieve duplicate items from the collection using strict comparison.
 *
 * @template TMapValue
 *
 * @param  (callable(TValue): TMapValue)|string|null  $callback
 * @return static
 */',
        'startLine' => 365,
        'endLine' => 368,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'duplicateComparator' => 
      array (
        'name' => 'duplicateComparator',
        'parameters' => 
        array (
          'strict' => 
          array (
            'name' => 'strict',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 376,
            'endLine' => 376,
            'startColumn' => 44,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the comparison function to detect duplicates.
 *
 * @param  bool  $strict
 * @return callable(TValue, TValue): bool
 */',
        'startLine' => 376,
        'endLine' => 383,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'except' => 
      array (
        'name' => 'except',
        'parameters' => 
        array (
          'keys' => 
          array (
            'name' => 'keys',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 391,
            'endLine' => 391,
            'startColumn' => 28,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get all items except for those with the specified keys.
 *
 * @param  \\Illuminate\\Support\\Enumerable<array-key, TKey>|array<array-key, TKey>|string  $keys
 * @return static
 */',
        'startLine' => 391,
        'endLine' => 404,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'filter' => 
      array (
        'name' => 'filter',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 412,
                'endLine' => 412,
                'startTokenPos' => 1728,
                'startFilePos' => 11396,
                'endTokenPos' => 1728,
                'endFilePos' => 11399,
              ),
            ),
            'type' => 
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
                      'name' => 'callable',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 412,
            'endLine' => 412,
            'startColumn' => 28,
            'endColumn' => 53,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Run a filter over each of the items.
 *
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @return static
 */',
        'startLine' => 412,
        'endLine' => 419,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'first' => 
      array (
        'name' => 'first',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 430,
                'endLine' => 430,
                'startTokenPos' => 1795,
                'startFilePos' => 11924,
                'endTokenPos' => 1795,
                'endFilePos' => 11927,
              ),
            ),
            'type' => 
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
                      'name' => 'callable',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 430,
            'endLine' => 430,
            'startColumn' => 27,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 430,
                'endLine' => 430,
                'startTokenPos' => 1802,
                'startFilePos' => 11941,
                'endTokenPos' => 1802,
                'endFilePos' => 11944,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 430,
            'endLine' => 430,
            'startColumn' => 55,
            'endColumn' => 69,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the first item from the collection passing the given truth test.
 *
 * @template TFirstDefault
 *
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @param  TFirstDefault|(\\Closure(): TFirstDefault)  $default
 * @return TValue|TFirstDefault
 */',
        'startLine' => 430,
        'endLine' => 433,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'flatten' => 
      array (
        'name' => 'flatten',
        'parameters' => 
        array (
          'depth' => 
          array (
            'name' => 'depth',
            'default' => 
            array (
              'code' => 'INF',
              'attributes' => 
              array (
                'startLine' => 441,
                'endLine' => 441,
                'startTokenPos' => 1839,
                'startFilePos' => 12204,
                'endTokenPos' => 1839,
                'endFilePos' => 12206,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 441,
            'endLine' => 441,
            'startColumn' => 29,
            'endColumn' => 40,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a flattened array of the items in the collection.
 *
 * @param  int  $depth
 * @return static<int, mixed>
 */',
        'startLine' => 441,
        'endLine' => 444,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'flip' => 
      array (
        'name' => 'flip',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Flip the items in the collection.
 *
 * @return static<TValue, TKey>
 */',
        'startLine' => 451,
        'endLine' => 454,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'forget' => 
      array (
        'name' => 'forget',
        'parameters' => 
        array (
          'keys' => 
          array (
            'name' => 'keys',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 462,
            'endLine' => 462,
            'startColumn' => 28,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Remove an item from the collection by key.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<array-key, TValue>|iterable<array-key, TKey>|TKey  $keys
 * @return $this
 */',
        'startLine' => 462,
        'endLine' => 469,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'get' => 
      array (
        'name' => 'get',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 480,
            'endLine' => 480,
            'startColumn' => 25,
            'endColumn' => 28,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 480,
                'endLine' => 480,
                'startTokenPos' => 1957,
                'startFilePos' => 13137,
                'endTokenPos' => 1957,
                'endFilePos' => 13140,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 480,
            'endLine' => 480,
            'startColumn' => 31,
            'endColumn' => 45,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get an item from the collection by key.
 *
 * @template TGetDefault
 *
 * @param  TKey|null  $key
 * @param  TGetDefault|(\\Closure(): TGetDefault)  $default
 * @return TValue|TGetDefault
 */',
        'startLine' => 480,
        'endLine' => 489,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'getOrPut' => 
      array (
        'name' => 'getOrPut',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 500,
            'endLine' => 500,
            'startColumn' => 30,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 500,
            'endLine' => 500,
            'startColumn' => 36,
            'endColumn' => 41,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get an item from the collection by key or add it to collection if it does not exist.
 *
 * @template TGetOrPutValue
 *
 * @param  mixed  $key
 * @param  TGetOrPutValue|(\\Closure(): TGetOrPutValue)  $value
 * @return TValue|TGetOrPutValue
 */',
        'startLine' => 500,
        'endLine' => 509,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'groupBy' => 
      array (
        'name' => 'groupBy',
        'parameters' => 
        array (
          'groupBy' => 
          array (
            'name' => 'groupBy',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 525,
            'endLine' => 525,
            'startColumn' => 29,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'preserveKeys' => 
          array (
            'name' => 'preserveKeys',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 525,
                'endLine' => 525,
                'startTokenPos' => 2099,
                'startFilePos' => 14454,
                'endTokenPos' => 2099,
                'endFilePos' => 14458,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 525,
            'endLine' => 525,
            'startColumn' => 39,
            'endColumn' => 59,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Group an associative array by a field or using a callback.
 *
 * @template TGroupKey of array-key|\\UnitEnum|\\Stringable
 *
 * @param  (callable(TValue, TKey): TGroupKey)|array|string  $groupBy
 * @param  bool  $preserveKeys
 * @return static<
 *  ($groupBy is (array|string)
 *      ? array-key
 *      : (TGroupKey is \\UnitEnum ? array-key : (TGroupKey is \\Stringable ? string : TGroupKey))),
 *  static<($preserveKeys is true ? TKey : int), ($groupBy is array ? mixed : TValue)>
 * >
 */',
        'startLine' => 525,
        'endLine' => 568,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'keyBy' => 
      array (
        'name' => 'keyBy',
        'parameters' => 
        array (
          'keyBy' => 
          array (
            'name' => 'keyBy',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 578,
            'endLine' => 578,
            'startColumn' => 27,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Key an associative array by a field or using a callback.
 *
 * @template TNewKey of array-key|\\UnitEnum
 *
 * @param  (callable(TValue, TKey): TNewKey)|array|string  $keyBy
 * @return static<($keyBy is (array|string) ? array-key : (TNewKey is \\UnitEnum ? array-key : TNewKey)), TValue>
 */',
        'startLine' => 578,
        'endLine' => 599,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'has' => 
      array (
        'name' => 'has',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 607,
            'endLine' => 607,
            'startColumn' => 25,
            'endColumn' => 28,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if an item exists in the collection by key.
 *
 * @param  TKey|array<array-key, TKey>  $key
 * @return bool
 */',
        'startLine' => 607,
        'endLine' => 612,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'hasAny' => 
      array (
        'name' => 'hasAny',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 620,
            'endLine' => 620,
            'startColumn' => 28,
            'endColumn' => 31,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if any of the keys exist in the collection.
 *
 * @param  TKey|array<array-key, TKey>  $key
 * @return bool
 */',
        'startLine' => 620,
        'endLine' => 629,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'implode' => 
      array (
        'name' => 'implode',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 638,
            'endLine' => 638,
            'startColumn' => 29,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'glue' => 
          array (
            'name' => 'glue',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 638,
                'endLine' => 638,
                'startTokenPos' => 2710,
                'startFilePos' => 17655,
                'endTokenPos' => 2710,
                'endFilePos' => 17658,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 638,
            'endLine' => 638,
            'startColumn' => 37,
            'endColumn' => 48,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Concatenate values of a given key as a string.
 *
 * @param  (callable(TValue, TKey): mixed)|string|null  $value
 * @param  string|null  $glue
 * @return string
 */',
        'startLine' => 638,
        'endLine' => 651,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'intersect' => 
      array (
        'name' => 'intersect',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 659,
            'endLine' => 659,
            'startColumn' => 31,
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
        'docComment' => '/**
 * Intersect the collection with the given items.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
 * @return static
 */',
        'startLine' => 659,
        'endLine' => 662,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'intersectUsing' => 
      array (
        'name' => 'intersectUsing',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 671,
            'endLine' => 671,
            'startColumn' => 36,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 671,
            'endLine' => 671,
            'startColumn' => 44,
            'endColumn' => 61,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Intersect the collection with the given items, using the callback.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<array-key, TValue>|iterable<array-key, TValue>  $items
 * @param  callable(TValue, TValue): int  $callback
 * @return static
 */',
        'startLine' => 671,
        'endLine' => 674,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'intersectAssoc' => 
      array (
        'name' => 'intersectAssoc',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 682,
            'endLine' => 682,
            'startColumn' => 36,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Intersect the collection with the given items with additional index check.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
 * @return static
 */',
        'startLine' => 682,
        'endLine' => 685,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'intersectAssocUsing' => 
      array (
        'name' => 'intersectAssocUsing',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 694,
            'endLine' => 694,
            'startColumn' => 41,
            'endColumn' => 46,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 694,
            'endLine' => 694,
            'startColumn' => 49,
            'endColumn' => 66,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Intersect the collection with the given items with additional index check, using the callback.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<array-key, TValue>|iterable<array-key, TValue>  $items
 * @param  callable(TValue, TValue): int  $callback
 * @return static
 */',
        'startLine' => 694,
        'endLine' => 697,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'intersectByKeys' => 
      array (
        'name' => 'intersectByKeys',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 705,
            'endLine' => 705,
            'startColumn' => 37,
            'endColumn' => 42,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Intersect the collection with the given items by key.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, mixed>|iterable<TKey, mixed>  $items
 * @return static
 */',
        'startLine' => 705,
        'endLine' => 710,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'isEmpty' => 
      array (
        'name' => 'isEmpty',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the collection is empty or not.
 *
 * @phpstan-assert-if-true null $this->first()
 * @phpstan-assert-if-true null $this->last()
 *
 * @phpstan-assert-if-false TValue $this->first()
 * @phpstan-assert-if-false TValue $this->last()
 *
 * @return bool
 */',
        'startLine' => 723,
        'endLine' => 726,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'containsOneItem' => 
      array (
        'name' => 'containsOneItem',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 736,
                'endLine' => 736,
                'startTokenPos' => 3087,
                'startFilePos' => 20895,
                'endTokenPos' => 3087,
                'endFilePos' => 20898,
              ),
            ),
            'type' => 
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
                      'name' => 'callable',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 736,
            'endLine' => 736,
            'startColumn' => 37,
            'endColumn' => 62,
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
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the collection contains exactly one item. If a callback is provided, determine if exactly one item matches the condition.
 *
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @return bool
 *
 * @deprecated 12.49.0 Use the `hasSole()` method instead.
 */',
        'startLine' => 736,
        'endLine' => 739,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'containsManyItems' => 
      array (
        'name' => 'containsManyItems',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 747,
                'endLine' => 747,
                'startTokenPos' => 3122,
                'startFilePos' => 21263,
                'endTokenPos' => 3122,
                'endFilePos' => 21266,
              ),
            ),
            'type' => 
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
                      'name' => 'callable',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 747,
            'endLine' => 747,
            'startColumn' => 39,
            'endColumn' => 64,
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
            'name' => 'bool',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Determine if the collection contains multiple items. If a callback is provided, determine if multiple items match the condition.
 *
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @return bool
 */',
        'startLine' => 747,
        'endLine' => 766,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'join' => 
      array (
        'name' => 'join',
        'parameters' => 
        array (
          'glue' => 
          array (
            'name' => 'glue',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 775,
            'endLine' => 775,
            'startColumn' => 26,
            'endColumn' => 30,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'finalGlue' => 
          array (
            'name' => 'finalGlue',
            'default' => 
            array (
              'code' => '\'\'',
              'attributes' => 
              array (
                'startLine' => 775,
                'endLine' => 775,
                'startTokenPos' => 3241,
                'startFilePos' => 21884,
                'endTokenPos' => 3241,
                'endFilePos' => 21885,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 775,
            'endLine' => 775,
            'startColumn' => 33,
            'endColumn' => 47,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Join all items from the collection using a string. The final items can use a separate glue string.
 *
 * @param  string  $glue
 * @param  string  $finalGlue
 * @return TValue|string
 */',
        'startLine' => 775,
        'endLine' => 796,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'keys' => 
      array (
        'name' => 'keys',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the keys of the collection items.
 *
 * @return static<int, TKey>
 */',
        'startLine' => 803,
        'endLine' => 806,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'last' => 
      array (
        'name' => 'last',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 817,
                'endLine' => 817,
                'startTokenPos' => 3408,
                'startFilePos' => 22822,
                'endTokenPos' => 3408,
                'endFilePos' => 22825,
              ),
            ),
            'type' => 
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
                      'name' => 'callable',
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 817,
            'endLine' => 817,
            'startColumn' => 26,
            'endColumn' => 51,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 817,
                'endLine' => 817,
                'startTokenPos' => 3415,
                'startFilePos' => 22839,
                'endTokenPos' => 3415,
                'endFilePos' => 22842,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 817,
            'endLine' => 817,
            'startColumn' => 54,
            'endColumn' => 68,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the last item from the collection.
 *
 * @template TLastDefault
 *
 * @param  (callable(TValue, TKey): bool)|null  $callback
 * @param  TLastDefault|(\\Closure(): TLastDefault)  $default
 * @return TValue|TLastDefault
 */',
        'startLine' => 817,
        'endLine' => 820,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'pluck' => 
      array (
        'name' => 'pluck',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 829,
            'endLine' => 829,
            'startColumn' => 27,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 829,
                'endLine' => 829,
                'startTokenPos' => 3455,
                'startFilePos' => 23176,
                'endTokenPos' => 3455,
                'endFilePos' => 23179,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 829,
            'endLine' => 829,
            'startColumn' => 35,
            'endColumn' => 45,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the values of a given key.
 *
 * @param  \\Closure|string|int|array<array-key, string>|null  $value
 * @param  \\Closure|string|null  $key
 * @return static<array-key, mixed>
 */',
        'startLine' => 829,
        'endLine' => 832,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'map' => 
      array (
        'name' => 'map',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 842,
            'endLine' => 842,
            'startColumn' => 25,
            'endColumn' => 42,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Run a map over each of the items.
 *
 * @template TMapValue
 *
 * @param  callable(TValue, TKey): TMapValue  $callback
 * @return static<TKey, TMapValue>
 */',
        'startLine' => 842,
        'endLine' => 845,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'mapToDictionary' => 
      array (
        'name' => 'mapToDictionary',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 858,
            'endLine' => 858,
            'startColumn' => 37,
            'endColumn' => 54,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Run a dictionary map over the items.
 *
 * The callback should return an associative array with a single key/value pair.
 *
 * @template TMapToDictionaryKey of array-key
 * @template TMapToDictionaryValue
 *
 * @param  callable(TValue, TKey): array<TMapToDictionaryKey, TMapToDictionaryValue>  $callback
 * @return static<TMapToDictionaryKey, array<int, TMapToDictionaryValue>>
 */',
        'startLine' => 858,
        'endLine' => 877,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'mapWithKeys' => 
      array (
        'name' => 'mapWithKeys',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 890,
            'endLine' => 890,
            'startColumn' => 33,
            'endColumn' => 50,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Run an associative map over each of the items.
 *
 * The callback should return an associative array with a single key/value pair.
 *
 * @template TMapWithKeysKey of array-key
 * @template TMapWithKeysValue
 *
 * @param  callable(TValue, TKey): array<TMapWithKeysKey, TMapWithKeysValue>  $callback
 * @return static<TMapWithKeysKey, TMapWithKeysValue>
 */',
        'startLine' => 890,
        'endLine' => 893,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'merge' => 
      array (
        'name' => 'merge',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 903,
            'endLine' => 903,
            'startColumn' => 27,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Merge the collection with the given items.
 *
 * @template TMergeValue
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TMergeValue>|iterable<TKey, TMergeValue>  $items
 * @return static<TKey, TValue|TMergeValue>
 */',
        'startLine' => 903,
        'endLine' => 906,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'mergeRecursive' => 
      array (
        'name' => 'mergeRecursive',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 916,
            'endLine' => 916,
            'startColumn' => 36,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Recursively merge the collection with the given items.
 *
 * @template TMergeRecursiveValue
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TMergeRecursiveValue>|iterable<TKey, TMergeRecursiveValue>  $items
 * @return static<TKey, TValue|TMergeRecursiveValue>
 */',
        'startLine' => 916,
        'endLine' => 919,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'multiply' => 
      array (
        'name' => 'multiply',
        'parameters' => 
        array (
          'multiplier' => 
          array (
            'name' => 'multiplier',
            'default' => NULL,
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
            'startLine' => 927,
            'endLine' => 927,
            'startColumn' => 30,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Multiply the items in the collection by the multiplier.
 *
 * @param  int  $multiplier
 * @return static
 */',
        'startLine' => 927,
        'endLine' => 936,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'combine' => 
      array (
        'name' => 'combine',
        'parameters' => 
        array (
          'values' => 
          array (
            'name' => 'values',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 946,
            'endLine' => 946,
            'startColumn' => 29,
            'endColumn' => 35,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a collection by using this collection for keys and another for its values.
 *
 * @template TCombineValue
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<array-key, TCombineValue>|iterable<array-key, TCombineValue>  $values
 * @return static<TValue, TCombineValue>
 */',
        'startLine' => 946,
        'endLine' => 949,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'union' => 
      array (
        'name' => 'union',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 957,
            'endLine' => 957,
            'startColumn' => 27,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Union the collection with the given items.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
 * @return static
 */',
        'startLine' => 957,
        'endLine' => 960,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'nth' => 
      array (
        'name' => 'nth',
        'parameters' => 
        array (
          'step' => 
          array (
            'name' => 'step',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 971,
            'endLine' => 971,
            'startColumn' => 25,
            'endColumn' => 29,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'offset' => 
          array (
            'name' => 'offset',
            'default' => 
            array (
              'code' => '0',
              'attributes' => 
              array (
                'startLine' => 971,
                'endLine' => 971,
                'startTokenPos' => 3922,
                'startFilePos' => 27256,
                'endTokenPos' => 3922,
                'endFilePos' => 27256,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 971,
            'endLine' => 971,
            'startColumn' => 32,
            'endColumn' => 42,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create a new collection consisting of every n-th element.
 *
 * @param  int  $step
 * @param  int  $offset
 * @return static
 *
 * @throws \\InvalidArgumentException
 */',
        'startLine' => 971,
        'endLine' => 990,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'only' => 
      array (
        'name' => 'only',
        'parameters' => 
        array (
          'keys' => 
          array (
            'name' => 'keys',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 998,
            'endLine' => 998,
            'startColumn' => 26,
            'endColumn' => 30,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the items with the specified keys.
 *
 * @param  \\Illuminate\\Support\\Enumerable<array-key, TKey>|array<array-key, TKey>|string|null  $keys
 * @return static
 */',
        'startLine' => 998,
        'endLine' => 1011,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'select' => 
      array (
        'name' => 'select',
        'parameters' => 
        array (
          'keys' => 
          array (
            'name' => 'keys',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1019,
            'endLine' => 1019,
            'startColumn' => 28,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Select specific values from the items within the collection.
 *
 * @param  \\Illuminate\\Support\\Enumerable<array-key, TKey>|array<array-key, TKey>|string|null  $keys
 * @return static
 */',
        'startLine' => 1019,
        'endLine' => 1032,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'pop' => 
      array (
        'name' => 'pop',
        'parameters' => 
        array (
          'count' => 
          array (
            'name' => 'count',
            'default' => 
            array (
              'code' => '1',
              'attributes' => 
              array (
                'startLine' => 1040,
                'endLine' => 1040,
                'startTokenPos' => 4252,
                'startFilePos' => 28953,
                'endTokenPos' => 4252,
                'endFilePos' => 28953,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1040,
            'endLine' => 1040,
            'startColumn' => 25,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get and remove the last N items from the collection.
 *
 * @param  int  $count
 * @return ($count is 1 ? TValue|null : static<int, TValue>)
 */',
        'startLine' => 1040,
        'endLine' => 1063,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'prepend' => 
      array (
        'name' => 'prepend',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1072,
            'endLine' => 1072,
            'startColumn' => 29,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1072,
                'endLine' => 1072,
                'startTokenPos' => 4409,
                'startFilePos' => 29630,
                'endTokenPos' => 4409,
                'endFilePos' => 29633,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1072,
            'endLine' => 1072,
            'startColumn' => 37,
            'endColumn' => 47,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Push an item onto the beginning of the collection.
 *
 * @param  TValue  $value
 * @param  TKey  $key
 * @return $this
 */',
        'startLine' => 1072,
        'endLine' => 1077,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'push' => 
      array (
        'name' => 'push',
        'parameters' => 
        array (
          'values' => 
          array (
            'name' => 'values',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => true,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1085,
            'endLine' => 1085,
            'startColumn' => 26,
            'endColumn' => 35,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Push one or more items onto the end of the collection.
 *
 * @param  TValue  ...$values
 * @return $this
 */',
        'startLine' => 1085,
        'endLine' => 1092,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'unshift' => 
      array (
        'name' => 'unshift',
        'parameters' => 
        array (
          'values' => 
          array (
            'name' => 'values',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => true,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1100,
            'endLine' => 1100,
            'startColumn' => 29,
            'endColumn' => 38,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Prepend one or more items to the beginning of the collection.
 *
 * @param  TValue  ...$values
 * @return $this
 */',
        'startLine' => 1100,
        'endLine' => 1105,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'concat' => 
      array (
        'name' => 'concat',
        'parameters' => 
        array (
          'source' => 
          array (
            'name' => 'source',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1116,
            'endLine' => 1116,
            'startColumn' => 28,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Push all of the given items onto the collection.
 *
 * @template TConcatKey of array-key
 * @template TConcatValue
 *
 * @param  iterable<TConcatKey, TConcatValue>  $source
 * @return static<TKey|TConcatKey, TValue|TConcatValue>
 */',
        'startLine' => 1116,
        'endLine' => 1125,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'pull' => 
      array (
        'name' => 'pull',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1136,
            'endLine' => 1136,
            'startColumn' => 26,
            'endColumn' => 29,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'default' => 
          array (
            'name' => 'default',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1136,
                'endLine' => 1136,
                'startTokenPos' => 4609,
                'startFilePos' => 31096,
                'endTokenPos' => 4609,
                'endFilePos' => 31099,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1136,
            'endLine' => 1136,
            'startColumn' => 32,
            'endColumn' => 46,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get and remove an item from the collection.
 *
 * @template TPullDefault
 *
 * @param  TKey  $key
 * @param  TPullDefault|(\\Closure(): TPullDefault)  $default
 * @return TValue|TPullDefault
 */',
        'startLine' => 1136,
        'endLine' => 1139,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'put' => 
      array (
        'name' => 'put',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1148,
            'endLine' => 1148,
            'startColumn' => 25,
            'endColumn' => 28,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1148,
            'endLine' => 1148,
            'startColumn' => 31,
            'endColumn' => 36,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Put an item in the collection by key.
 *
 * @param  TKey  $key
 * @param  TValue  $value
 * @return $this
 */',
        'startLine' => 1148,
        'endLine' => 1153,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'random' => 
      array (
        'name' => 'random',
        'parameters' => 
        array (
          'number' => 
          array (
            'name' => 'number',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1164,
                'endLine' => 1164,
                'startTokenPos' => 4680,
                'startFilePos' => 31782,
                'endTokenPos' => 4680,
                'endFilePos' => 31785,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1164,
            'endLine' => 1164,
            'startColumn' => 28,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'preserveKeys' => 
          array (
            'name' => 'preserveKeys',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 1164,
                'endLine' => 1164,
                'startTokenPos' => 4687,
                'startFilePos' => 31804,
                'endTokenPos' => 4687,
                'endFilePos' => 31808,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1164,
            'endLine' => 1164,
            'startColumn' => 44,
            'endColumn' => 64,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get one or a specified number of items randomly from the collection.
 *
 * @param  (callable(self<TKey, TValue>): int)|int|null  $number
 * @param  bool  $preserveKeys
 * @return ($number is null ? TValue : static<int, TValue>)
 *
 * @throws \\InvalidArgumentException
 */',
        'startLine' => 1164,
        'endLine' => 1175,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'replace' => 
      array (
        'name' => 'replace',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1183,
            'endLine' => 1183,
            'startColumn' => 29,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Replace the collection items with the given items.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
 * @return static
 */',
        'startLine' => 1183,
        'endLine' => 1186,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'replaceRecursive' => 
      array (
        'name' => 'replaceRecursive',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1194,
            'endLine' => 1194,
            'startColumn' => 38,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Recursively replace the collection items with the given items.
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
 * @return static
 */',
        'startLine' => 1194,
        'endLine' => 1197,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'reverse' => 
      array (
        'name' => 'reverse',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Reverse items order.
 *
 * @return static
 */',
        'startLine' => 1204,
        'endLine' => 1207,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'search' => 
      array (
        'name' => 'search',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1216,
            'endLine' => 1216,
            'startColumn' => 28,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'strict' => 
          array (
            'name' => 'strict',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 1216,
                'endLine' => 1216,
                'startTokenPos' => 4904,
                'startFilePos' => 33298,
                'endTokenPos' => 4904,
                'endFilePos' => 33302,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1216,
            'endLine' => 1216,
            'startColumn' => 36,
            'endColumn' => 50,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Search the collection for a given value and return the corresponding key if successful.
 *
 * @param  TValue|(callable(TValue,TKey): bool)  $value
 * @param  bool  $strict
 * @return TKey|false
 */',
        'startLine' => 1216,
        'endLine' => 1223,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'before' => 
      array (
        'name' => 'before',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1232,
            'endLine' => 1232,
            'startColumn' => 28,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'strict' => 
          array (
            'name' => 'strict',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 1232,
                'endLine' => 1232,
                'startTokenPos' => 4976,
                'startFilePos' => 33728,
                'endTokenPos' => 4976,
                'endFilePos' => 33732,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1232,
            'endLine' => 1232,
            'startColumn' => 36,
            'endColumn' => 50,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the item before the given item.
 *
 * @param  TValue|(callable(TValue,TKey): bool)  $value
 * @param  bool  $strict
 * @return TValue|null
 */',
        'startLine' => 1232,
        'endLine' => 1247,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'after' => 
      array (
        'name' => 'after',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1256,
            'endLine' => 1256,
            'startColumn' => 27,
            'endColumn' => 32,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'strict' => 
          array (
            'name' => 'strict',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 1256,
                'endLine' => 1256,
                'startTokenPos' => 5092,
                'startFilePos' => 34268,
                'endTokenPos' => 5092,
                'endFilePos' => 34272,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1256,
            'endLine' => 1256,
            'startColumn' => 35,
            'endColumn' => 49,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the item after the given item.
 *
 * @param  TValue|(callable(TValue,TKey): bool)  $value
 * @param  bool  $strict
 * @return TValue|null
 */',
        'startLine' => 1256,
        'endLine' => 1271,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'shift' => 
      array (
        'name' => 'shift',
        'parameters' => 
        array (
          'count' => 
          array (
            'name' => 'count',
            'default' => 
            array (
              'code' => '1',
              'attributes' => 
              array (
                'startLine' => 1281,
                'endLine' => 1281,
                'startTokenPos' => 5213,
                'startFilePos' => 34867,
                'endTokenPos' => 5213,
                'endFilePos' => 34867,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1281,
            'endLine' => 1281,
            'startColumn' => 27,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get and remove the first N items from the collection.
 *
 * @param  int<0, max>  $count
 * @return ($count is 1 ? TValue|null : static<int, TValue>)
 *
 * @throws \\InvalidArgumentException
 */',
        'startLine' => 1281,
        'endLine' => 1308,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'shuffle' => 
      array (
        'name' => 'shuffle',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Shuffle the items in the collection.
 *
 * @return static
 */',
        'startLine' => 1315,
        'endLine' => 1318,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sliding' => 
      array (
        'name' => 'sliding',
        'parameters' => 
        array (
          'size' => 
          array (
            'name' => 'size',
            'default' => 
            array (
              'code' => '2',
              'attributes' => 
              array (
                'startLine' => 1329,
                'endLine' => 1329,
                'startTokenPos' => 5420,
                'startFilePos' => 35971,
                'endTokenPos' => 5420,
                'endFilePos' => 35971,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1329,
            'endLine' => 1329,
            'startColumn' => 29,
            'endColumn' => 37,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'step' => 
          array (
            'name' => 'step',
            'default' => 
            array (
              'code' => '1',
              'attributes' => 
              array (
                'startLine' => 1329,
                'endLine' => 1329,
                'startTokenPos' => 5427,
                'startFilePos' => 35982,
                'endTokenPos' => 5427,
                'endFilePos' => 35982,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1329,
            'endLine' => 1329,
            'startColumn' => 40,
            'endColumn' => 48,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Create chunks representing a "sliding window" view of the items in the collection.
 *
 * @param  positive-int  $size
 * @param  positive-int  $step
 * @return static<int, static>
 *
 * @throws \\InvalidArgumentException
 */',
        'startLine' => 1329,
        'endLine' => 1340,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'skip' => 
      array (
        'name' => 'skip',
        'parameters' => 
        array (
          'count' => 
          array (
            'name' => 'count',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1348,
            'endLine' => 1348,
            'startColumn' => 26,
            'endColumn' => 31,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Skip the first {$count} items.
 *
 * @param  int  $count
 * @return static
 */',
        'startLine' => 1348,
        'endLine' => 1351,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'skipUntil' => 
      array (
        'name' => 'skipUntil',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1359,
            'endLine' => 1359,
            'startColumn' => 31,
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
        'docComment' => '/**
 * Skip items in the collection until the given condition is met.
 *
 * @param  TValue|callable(TValue,TKey): bool  $value
 * @return static
 */',
        'startLine' => 1359,
        'endLine' => 1362,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'skipWhile' => 
      array (
        'name' => 'skipWhile',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1370,
            'endLine' => 1370,
            'startColumn' => 31,
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
        'docComment' => '/**
 * Skip items in the collection while the given condition is met.
 *
 * @param  TValue|callable(TValue,TKey): bool  $value
 * @return static
 */',
        'startLine' => 1370,
        'endLine' => 1373,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'slice' => 
      array (
        'name' => 'slice',
        'parameters' => 
        array (
          'offset' => 
          array (
            'name' => 'offset',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1382,
            'endLine' => 1382,
            'startColumn' => 27,
            'endColumn' => 33,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'length' => 
          array (
            'name' => 'length',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1382,
                'endLine' => 1382,
                'startTokenPos' => 5665,
                'startFilePos' => 37366,
                'endTokenPos' => 5665,
                'endFilePos' => 37369,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1382,
            'endLine' => 1382,
            'startColumn' => 36,
            'endColumn' => 49,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Slice the underlying collection array.
 *
 * @param  int  $offset
 * @param  int|null  $length
 * @return static
 */',
        'startLine' => 1382,
        'endLine' => 1385,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'split' => 
      array (
        'name' => 'split',
        'parameters' => 
        array (
          'numberOfGroups' => 
          array (
            'name' => 'numberOfGroups',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1395,
            'endLine' => 1395,
            'startColumn' => 27,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Split a collection into a certain number of groups.
 *
 * @param  int  $numberOfGroups
 * @return static<int, static>
 *
 * @throws \\InvalidArgumentException
 */',
        'startLine' => 1395,
        'endLine' => 1428,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'splitIn' => 
      array (
        'name' => 'splitIn',
        'parameters' => 
        array (
          'numberOfGroups' => 
          array (
            'name' => 'numberOfGroups',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1438,
            'endLine' => 1438,
            'startColumn' => 29,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Split a collection into a certain number of groups, and fill the first groups completely.
 *
 * @param  int  $numberOfGroups
 * @return static<int, static>
 *
 * @throws \\InvalidArgumentException
 */',
        'startLine' => 1438,
        'endLine' => 1445,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sole' => 
      array (
        'name' => 'sole',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1458,
                'endLine' => 1458,
                'startTokenPos' => 5975,
                'startFilePos' => 39393,
                'endTokenPos' => 5975,
                'endFilePos' => 39396,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1458,
            'endLine' => 1458,
            'startColumn' => 26,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'operator' => 
          array (
            'name' => 'operator',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1458,
                'endLine' => 1458,
                'startTokenPos' => 5982,
                'startFilePos' => 39411,
                'endTokenPos' => 5982,
                'endFilePos' => 39414,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1458,
            'endLine' => 1458,
            'startColumn' => 39,
            'endColumn' => 54,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1458,
                'endLine' => 1458,
                'startTokenPos' => 5989,
                'startFilePos' => 39426,
                'endTokenPos' => 5989,
                'endFilePos' => 39429,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1458,
            'endLine' => 1458,
            'startColumn' => 57,
            'endColumn' => 69,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the first item in the collection, but only if exactly one item exists. Otherwise, throw an exception.
 *
 * @param  (callable(TValue, TKey): bool)|string|null  $key
 * @param  mixed  $operator
 * @param  mixed  $value
 * @return TValue
 *
 * @throws \\Illuminate\\Support\\ItemNotFoundException
 * @throws \\Illuminate\\Support\\MultipleItemsFoundException
 */',
        'startLine' => 1458,
        'endLine' => 1477,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'hasSole' => 
      array (
        'name' => 'hasSole',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1487,
                'endLine' => 1487,
                'startTokenPos' => 6123,
                'startFilePos' => 40178,
                'endTokenPos' => 6123,
                'endFilePos' => 40181,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1487,
            'endLine' => 1487,
            'startColumn' => 29,
            'endColumn' => 39,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'operator' => 
          array (
            'name' => 'operator',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1487,
                'endLine' => 1487,
                'startTokenPos' => 6130,
                'startFilePos' => 40196,
                'endTokenPos' => 6130,
                'endFilePos' => 40199,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1487,
            'endLine' => 1487,
            'startColumn' => 42,
            'endColumn' => 57,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1487,
                'endLine' => 1487,
                'startTokenPos' => 6137,
                'startFilePos' => 40211,
                'endTokenPos' => 6137,
                'endFilePos' => 40214,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1487,
            'endLine' => 1487,
            'startColumn' => 60,
            'endColumn' => 72,
            'parameterIndex' => 2,
            'isOptional' => true,
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
 * Determine if the collection contains a single item, optionally matching the given criteria.
 *
 * @param  (callable(TValue, TKey): bool)|string|null  $key
 * @param  mixed  $operator
 * @param  mixed  $value
 * @return bool
 */',
        'startLine' => 1487,
        'endLine' => 1497,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'firstOrFail' => 
      array (
        'name' => 'firstOrFail',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1509,
                'endLine' => 1509,
                'startTokenPos' => 6218,
                'startFilePos' => 40834,
                'endTokenPos' => 6218,
                'endFilePos' => 40837,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1509,
            'endLine' => 1509,
            'startColumn' => 33,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'operator' => 
          array (
            'name' => 'operator',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1509,
                'endLine' => 1509,
                'startTokenPos' => 6225,
                'startFilePos' => 40852,
                'endTokenPos' => 6225,
                'endFilePos' => 40855,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1509,
            'endLine' => 1509,
            'startColumn' => 46,
            'endColumn' => 61,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1509,
                'endLine' => 1509,
                'startTokenPos' => 6232,
                'startFilePos' => 40867,
                'endTokenPos' => 6232,
                'endFilePos' => 40870,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1509,
            'endLine' => 1509,
            'startColumn' => 64,
            'endColumn' => 76,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get the first item in the collection but throw an exception if no matching items exist.
 *
 * @param  (callable(TValue, TKey): bool)|string  $key
 * @param  mixed  $operator
 * @param  mixed  $value
 * @return TValue
 *
 * @throws \\Illuminate\\Support\\ItemNotFoundException
 */',
        'startLine' => 1509,
        'endLine' => 1524,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'chunk' => 
      array (
        'name' => 'chunk',
        'parameters' => 
        array (
          'size' => 
          array (
            'name' => 'size',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1533,
            'endLine' => 1533,
            'startColumn' => 27,
            'endColumn' => 31,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'preserveKeys' => 
          array (
            'name' => 'preserveKeys',
            'default' => 
            array (
              'code' => 'true',
              'attributes' => 
              array (
                'startLine' => 1533,
                'endLine' => 1533,
                'startTokenPos' => 6335,
                'startFilePos' => 41501,
                'endTokenPos' => 6335,
                'endFilePos' => 41504,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1533,
            'endLine' => 1533,
            'startColumn' => 34,
            'endColumn' => 53,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Chunk the collection into chunks of the given size.
 *
 * @param  int  $size
 * @param  bool  $preserveKeys
 * @return ($preserveKeys is true ? static<int, static> : static<int, static<int, TValue>>)
 */',
        'startLine' => 1533,
        'endLine' => 1546,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'chunkWhile' => 
      array (
        'name' => 'chunkWhile',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1554,
            'endLine' => 1554,
            'startColumn' => 32,
            'endColumn' => 49,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Chunk the collection into chunks with a callback.
 *
 * @param  callable(TValue, TKey, static<TKey, TValue>): bool  $callback
 * @return static<int, static<TKey, TValue>>
 */',
        'startLine' => 1554,
        'endLine' => 1559,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sort' => 
      array (
        'name' => 'sort',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1567,
                'endLine' => 1567,
                'startTokenPos' => 6477,
                'startFilePos' => 42357,
                'endTokenPos' => 6477,
                'endFilePos' => 42360,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1567,
            'endLine' => 1567,
            'startColumn' => 26,
            'endColumn' => 41,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort through each item with a callback.
 *
 * @param  (callable(TValue, TValue): int)|null|int  $callback
 * @return static
 */',
        'startLine' => 1567,
        'endLine' => 1576,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sortDesc' => 
      array (
        'name' => 'sortDesc',
        'parameters' => 
        array (
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => 'SORT_REGULAR',
              'attributes' => 
              array (
                'startLine' => 1584,
                'endLine' => 1584,
                'startTokenPos' => 6549,
                'startFilePos' => 42737,
                'endTokenPos' => 6549,
                'endFilePos' => 42748,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1584,
            'endLine' => 1584,
            'startColumn' => 30,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort items in descending order.
 *
 * @param  int  $options
 * @return static
 */',
        'startLine' => 1584,
        'endLine' => 1591,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sortBy' => 
      array (
        'name' => 'sortBy',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1601,
            'endLine' => 1601,
            'startColumn' => 28,
            'endColumn' => 36,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => 'SORT_REGULAR',
              'attributes' => 
              array (
                'startLine' => 1601,
                'endLine' => 1601,
                'startTokenPos' => 6599,
                'startFilePos' => 43253,
                'endTokenPos' => 6599,
                'endFilePos' => 43264,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1601,
            'endLine' => 1601,
            'startColumn' => 39,
            'endColumn' => 61,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'descending' => 
          array (
            'name' => 'descending',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 1601,
                'endLine' => 1601,
                'startTokenPos' => 6606,
                'startFilePos' => 43281,
                'endTokenPos' => 6606,
                'endFilePos' => 43285,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1601,
            'endLine' => 1601,
            'startColumn' => 64,
            'endColumn' => 82,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort the collection using the given callback.
 *
 * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string  $callback
 * @param  int  $options
 * @param  bool  $descending
 * @return static
 */',
        'startLine' => 1601,
        'endLine' => 1629,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sortByMany' => 
      array (
        'name' => 'sortByMany',
        'parameters' => 
        array (
          'comparisons' => 
          array (
            'name' => 'comparisons',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 1638,
                'endLine' => 1638,
                'startTokenPos' => 6795,
                'startFilePos' => 44698,
                'endTokenPos' => 6796,
                'endFilePos' => 44699,
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
            'startLine' => 1638,
            'endLine' => 1638,
            'startColumn' => 35,
            'endColumn' => 57,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => 'SORT_REGULAR',
              'attributes' => 
              array (
                'startLine' => 1638,
                'endLine' => 1638,
                'startTokenPos' => 6805,
                'startFilePos' => 44717,
                'endTokenPos' => 6805,
                'endFilePos' => 44728,
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
            'startLine' => 1638,
            'endLine' => 1638,
            'startColumn' => 60,
            'endColumn' => 86,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort the collection using multiple comparisons.
 *
 * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>  $comparisons
 * @param  int  $options
 * @return static
 */',
        'startLine' => 1638,
        'endLine' => 1686,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sortByDesc' => 
      array (
        'name' => 'sortByDesc',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1695,
            'endLine' => 1695,
            'startColumn' => 32,
            'endColumn' => 40,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => 'SORT_REGULAR',
              'attributes' => 
              array (
                'startLine' => 1695,
                'endLine' => 1695,
                'startTokenPos' => 7265,
                'startFilePos' => 46940,
                'endTokenPos' => 7265,
                'endFilePos' => 46951,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1695,
            'endLine' => 1695,
            'startColumn' => 43,
            'endColumn' => 65,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort the collection in descending order using the given callback.
 *
 * @param  array<array-key, (callable(TValue, TValue): mixed)|(callable(TValue, TKey): mixed)|string|array{string, string}>|(callable(TValue, TKey): mixed)|string  $callback
 * @param  int  $options
 * @return static
 */',
        'startLine' => 1695,
        'endLine' => 1708,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sortKeys' => 
      array (
        'name' => 'sortKeys',
        'parameters' => 
        array (
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => 'SORT_REGULAR',
              'attributes' => 
              array (
                'startLine' => 1717,
                'endLine' => 1717,
                'startTokenPos' => 7372,
                'startFilePos' => 47483,
                'endTokenPos' => 7372,
                'endFilePos' => 47494,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1717,
            'endLine' => 1717,
            'startColumn' => 30,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'descending' => 
          array (
            'name' => 'descending',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 1717,
                'endLine' => 1717,
                'startTokenPos' => 7379,
                'startFilePos' => 47511,
                'endTokenPos' => 7379,
                'endFilePos' => 47515,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1717,
            'endLine' => 1717,
            'startColumn' => 55,
            'endColumn' => 73,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort the collection keys.
 *
 * @param  int  $options
 * @param  bool  $descending
 * @return static
 */',
        'startLine' => 1717,
        'endLine' => 1724,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sortKeysDesc' => 
      array (
        'name' => 'sortKeysDesc',
        'parameters' => 
        array (
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => 'SORT_REGULAR',
              'attributes' => 
              array (
                'startLine' => 1732,
                'endLine' => 1732,
                'startTokenPos' => 7440,
                'startFilePos' => 47844,
                'endTokenPos' => 7440,
                'endFilePos' => 47855,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1732,
            'endLine' => 1732,
            'startColumn' => 34,
            'endColumn' => 56,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort the collection keys in descending order.
 *
 * @param  int  $options
 * @return static
 */',
        'startLine' => 1732,
        'endLine' => 1735,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'sortKeysUsing' => 
      array (
        'name' => 'sortKeysUsing',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1743,
            'endLine' => 1743,
            'startColumn' => 35,
            'endColumn' => 52,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Sort the collection keys using a callback.
 *
 * @param  callable(TKey, TKey): int  $callback
 * @return static
 */',
        'startLine' => 1743,
        'endLine' => 1750,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'splice' => 
      array (
        'name' => 'splice',
        'parameters' => 
        array (
          'offset' => 
          array (
            'name' => 'offset',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1760,
            'endLine' => 1760,
            'startColumn' => 28,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'length' => 
          array (
            'name' => 'length',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1760,
                'endLine' => 1760,
                'startTokenPos' => 7520,
                'startFilePos' => 48502,
                'endTokenPos' => 7520,
                'endFilePos' => 48505,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1760,
            'endLine' => 1760,
            'startColumn' => 37,
            'endColumn' => 50,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
          'replacement' => 
          array (
            'name' => 'replacement',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 1760,
                'endLine' => 1760,
                'startTokenPos' => 7527,
                'startFilePos' => 48523,
                'endTokenPos' => 7528,
                'endFilePos' => 48524,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1760,
            'endLine' => 1760,
            'startColumn' => 53,
            'endColumn' => 69,
            'parameterIndex' => 2,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Splice a portion of the underlying collection array.
 *
 * @param  int  $offset
 * @param  int|null  $length
 * @param  array<array-key, TValue>  $replacement
 * @return static
 */',
        'startLine' => 1760,
        'endLine' => 1767,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'take' => 
      array (
        'name' => 'take',
        'parameters' => 
        array (
          'limit' => 
          array (
            'name' => 'limit',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1775,
            'endLine' => 1775,
            'startColumn' => 26,
            'endColumn' => 31,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Take the first or last {$limit} items.
 *
 * @param  int  $limit
 * @return static
 */',
        'startLine' => 1775,
        'endLine' => 1782,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'takeUntil' => 
      array (
        'name' => 'takeUntil',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1790,
            'endLine' => 1790,
            'startColumn' => 31,
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
        'docComment' => '/**
 * Take items in the collection until the given condition is met.
 *
 * @param  TValue|callable(TValue,TKey): bool  $value
 * @return static
 */',
        'startLine' => 1790,
        'endLine' => 1793,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'takeWhile' => 
      array (
        'name' => 'takeWhile',
        'parameters' => 
        array (
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1801,
            'endLine' => 1801,
            'startColumn' => 31,
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
        'docComment' => '/**
 * Take items in the collection while the given condition is met.
 *
 * @param  TValue|callable(TValue,TKey): bool  $value
 * @return static
 */',
        'startLine' => 1801,
        'endLine' => 1804,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'transform' => 
      array (
        'name' => 'transform',
        'parameters' => 
        array (
          'callback' => 
          array (
            'name' => 'callback',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'callable',
                'isIdentifier' => true,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1816,
            'endLine' => 1816,
            'startColumn' => 31,
            'endColumn' => 48,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Transform each item in the collection using a callback.
 *
 * @template TMapValue
 *
 * @param  callable(TValue, TKey): TMapValue  $callback
 * @return $this
 *
 * @phpstan-this-out static<TKey, TMapValue>
 */',
        'startLine' => 1816,
        'endLine' => 1821,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'dot' => 
      array (
        'name' => 'dot',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Flatten a multi-dimensional associative array with dots.
 *
 * @return static
 */',
        'startLine' => 1828,
        'endLine' => 1831,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'undot' => 
      array (
        'name' => 'undot',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Convert a flatten "dot" notation array into an expanded array.
 *
 * @return static
 */',
        'startLine' => 1838,
        'endLine' => 1841,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'unique' => 
      array (
        'name' => 'unique',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1850,
                'endLine' => 1850,
                'startTokenPos' => 7850,
                'startFilePos' => 50684,
                'endTokenPos' => 7850,
                'endFilePos' => 50687,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1850,
            'endLine' => 1850,
            'startColumn' => 28,
            'endColumn' => 38,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
          'strict' => 
          array (
            'name' => 'strict',
            'default' => 
            array (
              'code' => 'false',
              'attributes' => 
              array (
                'startLine' => 1850,
                'endLine' => 1850,
                'startTokenPos' => 7857,
                'startFilePos' => 50700,
                'endTokenPos' => 7857,
                'endFilePos' => 50704,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1850,
            'endLine' => 1850,
            'startColumn' => 41,
            'endColumn' => 55,
            'parameterIndex' => 1,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Return only unique items from the collection array.
 *
 * @param  (callable(TValue, TKey): mixed)|string|null  $key
 * @param  bool  $strict
 * @return static
 */',
        'startLine' => 1850,
        'endLine' => 1867,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'values' => 
      array (
        'name' => 'values',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Reset the keys on the underlying array.
 *
 * @return static<int, TValue>
 */',
        'startLine' => 1874,
        'endLine' => 1877,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'zip' => 
      array (
        'name' => 'zip',
        'parameters' => 
        array (
          'items' => 
          array (
            'name' => 'items',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1890,
            'endLine' => 1890,
            'startColumn' => 25,
            'endColumn' => 30,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Zip the collection together with one or more arrays.
 *
 * e.g. new Collection([1, 2, 3])->zip([4, 5, 6]);
 *      => [[1, 4], [2, 5], [3, 6]]
 *
 * @template TZipValue
 *
 * @param  \\Illuminate\\Contracts\\Support\\Arrayable<array-key, TZipValue>|iterable<array-key, TZipValue>  ...$items
 * @return static<int, static<int, TValue|TZipValue>>
 */',
        'startLine' => 1890,
        'endLine' => 1897,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => true,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'pad' => 
      array (
        'name' => 'pad',
        'parameters' => 
        array (
          'size' => 
          array (
            'name' => 'size',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1908,
            'endLine' => 1908,
            'startColumn' => 25,
            'endColumn' => 29,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1908,
            'endLine' => 1908,
            'startColumn' => 32,
            'endColumn' => 37,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Pad collection to the specified length with a value.
 *
 * @template TPadValue
 *
 * @param  int  $size
 * @param  TPadValue  $value
 * @return static<int, TValue|TPadValue>
 */',
        'startLine' => 1908,
        'endLine' => 1911,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'getIterator' => 
      array (
        'name' => 'getIterator',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'Traversable',
            'isIdentifier' => false,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get an iterator for the items.
 *
 * @return \\ArrayIterator<TKey, TValue>
 */',
        'startLine' => 1918,
        'endLine' => 1921,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'count' => 
      array (
        'name' => 'count',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Count the number of items in the collection.
 *
 * @return int<0, max>
 */',
        'startLine' => 1928,
        'endLine' => 1931,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'countBy' => 
      array (
        'name' => 'countBy',
        'parameters' => 
        array (
          'countBy' => 
          array (
            'name' => 'countBy',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 1939,
                'endLine' => 1939,
                'startTokenPos' => 8227,
                'startFilePos' => 53082,
                'endTokenPos' => 8227,
                'endFilePos' => 53085,
              ),
            ),
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1939,
            'endLine' => 1939,
            'startColumn' => 29,
            'endColumn' => 43,
            'parameterIndex' => 0,
            'isOptional' => true,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Count the number of items in the collection by a field or using a callback.
 *
 * @param  (callable(TValue, TKey): (array-key|\\UnitEnum))|string|null  $countBy
 * @return static<array-key, int>
 */',
        'startLine' => 1939,
        'endLine' => 1942,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'add' => 
      array (
        'name' => 'add',
        'parameters' => 
        array (
          'item' => 
          array (
            'name' => 'item',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1950,
            'endLine' => 1950,
            'startColumn' => 25,
            'endColumn' => 29,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Add an item to the collection.
 *
 * @param  TValue  $item
 * @return $this
 */',
        'startLine' => 1950,
        'endLine' => 1955,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'toBase' => 
      array (
        'name' => 'toBase',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get a base Support collection instance from this collection.
 *
 * @return \\Illuminate\\Support\\Collection<TKey, TValue>
 */',
        'startLine' => 1962,
        'endLine' => 1965,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'offsetExists' => 
      array (
        'name' => 'offsetExists',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1973,
            'endLine' => 1973,
            'startColumn' => 34,
            'endColumn' => 37,
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
 * Determine if an item exists at an offset.
 *
 * @param  TKey  $key
 * @return bool
 */',
        'startLine' => 1973,
        'endLine' => 1976,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'offsetGet' => 
      array (
        'name' => 'offsetGet',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1984,
            'endLine' => 1984,
            'startColumn' => 31,
            'endColumn' => 34,
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
            'name' => 'mixed',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Get an item at a given offset.
 *
 * @param  TKey  $key
 * @return TValue
 */',
        'startLine' => 1984,
        'endLine' => 1987,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'offsetSet' => 
      array (
        'name' => 'offsetSet',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1996,
            'endLine' => 1996,
            'startColumn' => 31,
            'endColumn' => 34,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'value' => 
          array (
            'name' => 'value',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 1996,
            'endLine' => 1996,
            'startColumn' => 37,
            'endColumn' => 42,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Set the item at a given offset.
 *
 * @param  TKey|null  $key
 * @param  TValue  $value
 * @return void
 */',
        'startLine' => 1996,
        'endLine' => 2003,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
        'aliasName' => NULL,
      ),
      'offsetUnset' => 
      array (
        'name' => 'offsetUnset',
        'parameters' => 
        array (
          'key' => 
          array (
            'name' => 'key',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 2011,
            'endLine' => 2011,
            'startColumn' => 33,
            'endColumn' => 36,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => '/**
 * Unset the item at a given offset.
 *
 * @param  TKey  $key
 * @return void
 */',
        'startLine' => 2011,
        'endLine' => 2014,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Support',
        'declaringClassName' => 'Illuminate\\Support\\Collection',
        'implementingClassName' => 'Illuminate\\Support\\Collection',
        'currentClassName' => 'Illuminate\\Support\\Collection',
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