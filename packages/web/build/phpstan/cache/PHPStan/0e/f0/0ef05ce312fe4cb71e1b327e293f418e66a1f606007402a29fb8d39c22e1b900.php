<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/web/vendor/composer/../laravel/framework/src/Illuminate/Hashing/BcryptHasher.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Illuminate\Hashing\BcryptHasher
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-3b4723f0824633132c99c19267ab32834f92b5a98212ef2db146e9e2a6f9f937-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Illuminate\\Hashing\\BcryptHasher',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/web/vendor/composer/../laravel/framework/src/Illuminate/Hashing/BcryptHasher.php',
      ),
    ),
    'namespace' => 'Illuminate\\Hashing',
    'name' => 'Illuminate\\Hashing\\BcryptHasher',
    'shortName' => 'BcryptHasher',
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
    'endLine' => 173,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Hashing\\AbstractHasher',
    'implementsClassNames' => 
    array (
      0 => 'Illuminate\\Contracts\\Hashing\\Hasher',
    ),
    'traitClassNames' => 
    array (
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'rounds' => 
      array (
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'name' => 'rounds',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '12',
          'attributes' => 
          array (
            'startLine' => 17,
            'endLine' => 17,
            'startTokenPos' => 53,
            'startFilePos' => 326,
            'endTokenPos' => 53,
            'endFilePos' => 327,
          ),
        ),
        'docComment' => '/**
 * The default cost factor.
 *
 * @var int
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 17,
        'endLine' => 17,
        'startColumn' => 5,
        'endColumn' => 27,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'verifyAlgorithm' => 
      array (
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'name' => 'verifyAlgorithm',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => 'false',
          'attributes' => 
          array (
            'startLine' => 24,
            'endLine' => 24,
            'startTokenPos' => 64,
            'startFilePos' => 460,
            'endTokenPos' => 64,
            'endFilePos' => 464,
          ),
        ),
        'docComment' => '/**
 * Indicates whether to perform an algorithm check.
 *
 * @var bool
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 24,
        'endLine' => 24,
        'startColumn' => 5,
        'endColumn' => 39,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'limit' => 
      array (
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'name' => 'limit',
        'modifiers' => 2,
        'type' => NULL,
        'default' => NULL,
        'docComment' => '/**
 * The maximum allowed length of strings that can be hashed.
 *
 * @var int|null
 */',
        'attributes' => 
        array (
        ),
        'startLine' => 31,
        'endLine' => 31,
        'startColumn' => 5,
        'endColumn' => 21,
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
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 38,
                'endLine' => 38,
                'startTokenPos' => 88,
                'startFilePos' => 740,
                'endTokenPos' => 89,
                'endFilePos' => 741,
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
            'startLine' => 38,
            'endLine' => 38,
            'startColumn' => 33,
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
        'docComment' => '/**
 * Create a new hasher instance.
 *
 * @param  array  $options
 */',
        'startLine' => 38,
        'endLine' => 43,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'make' => 
      array (
        'name' => 'make',
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
              0 => 
              array (
                'name' => 'SensitiveParameter',
                'isRepeated' => false,
                'arguments' => 
                array (
                ),
              ),
            ),
            'startLine' => 54,
            'endLine' => 54,
            'startColumn' => 26,
            'endColumn' => 54,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 54,
                'endLine' => 54,
                'startTokenPos' => 171,
                'startFilePos' => 1203,
                'endTokenPos' => 172,
                'endFilePos' => 1204,
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
            'startLine' => 54,
            'endLine' => 54,
            'startColumn' => 57,
            'endColumn' => 75,
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
 * Hash the given value.
 *
 * @param  string  $value
 * @param  array  $options
 * @return string
 *
 * @throws \\RuntimeException
 */',
        'startLine' => 54,
        'endLine' => 69,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'check' => 
      array (
        'name' => 'check',
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
              0 => 
              array (
                'name' => 'SensitiveParameter',
                'isRepeated' => false,
                'arguments' => 
                array (
                ),
              ),
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 27,
            'endColumn' => 55,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'hashedValue' => 
          array (
            'name' => 'hashedValue',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 58,
            'endColumn' => 69,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 81,
                'endLine' => 81,
                'startTokenPos' => 305,
                'startFilePos' => 2021,
                'endTokenPos' => 306,
                'endFilePos' => 2022,
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
            'startLine' => 81,
            'endLine' => 81,
            'startColumn' => 72,
            'endColumn' => 90,
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
 * Check the given plain value against a hash.
 *
 * @param  string  $value
 * @param  string  $hashedValue
 * @param  array  $options
 * @return bool
 *
 * @throws \\RuntimeException
 */',
        'startLine' => 81,
        'endLine' => 92,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'needsRehash' => 
      array (
        'name' => 'needsRehash',
        'parameters' => 
        array (
          'hashedValue' => 
          array (
            'name' => 'hashedValue',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 101,
            'endLine' => 101,
            'startColumn' => 33,
            'endColumn' => 44,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 101,
                'endLine' => 101,
                'startTokenPos' => 408,
                'startFilePos' => 2640,
                'endTokenPos' => 409,
                'endFilePos' => 2641,
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
            'startLine' => 101,
            'endLine' => 101,
            'startColumn' => 47,
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
 * Check if the given hash has been hashed using the given options.
 *
 * @param  string  $hashedValue
 * @param  array  $options
 * @return bool
 */',
        'startLine' => 101,
        'endLine' => 106,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'verifyConfiguration' => 
      array (
        'name' => 'verifyConfiguration',
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
            'startLine' => 113,
            'endLine' => 113,
            'startColumn' => 41,
            'endColumn' => 46,
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
 * Verifies that the configuration is less than or equal to what is configured.
 *
 * @internal
 */',
        'startLine' => 113,
        'endLine' => 116,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'isUsingCorrectAlgorithm' => 
      array (
        'name' => 'isUsingCorrectAlgorithm',
        'parameters' => 
        array (
          'hashedValue' => 
          array (
            'name' => 'hashedValue',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 124,
            'endLine' => 124,
            'startColumn' => 48,
            'endColumn' => 59,
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
 * Verify the hashed value\'s algorithm.
 *
 * @param  string  $hashedValue
 * @return bool
 */',
        'startLine' => 124,
        'endLine' => 127,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'isUsingValidOptions' => 
      array (
        'name' => 'isUsingValidOptions',
        'parameters' => 
        array (
          'hashedValue' => 
          array (
            'name' => 'hashedValue',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 135,
            'endLine' => 135,
            'startColumn' => 44,
            'endColumn' => 55,
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
 * Verify the hashed value\'s options.
 *
 * @param  string  $hashedValue
 * @return bool
 */',
        'startLine' => 135,
        'endLine' => 148,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'setRounds' => 
      array (
        'name' => 'setRounds',
        'parameters' => 
        array (
          'rounds' => 
          array (
            'name' => 'rounds',
            'default' => NULL,
            'type' => NULL,
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 156,
            'endLine' => 156,
            'startColumn' => 31,
            'endColumn' => 37,
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
 * Set the default password work factor.
 *
 * @param  int  $rounds
 * @return $this
 */',
        'startLine' => 156,
        'endLine' => 161,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'aliasName' => NULL,
      ),
      'cost' => 
      array (
        'name' => 'cost',
        'parameters' => 
        array (
          'options' => 
          array (
            'name' => 'options',
            'default' => 
            array (
              'code' => '[]',
              'attributes' => 
              array (
                'startLine' => 169,
                'endLine' => 169,
                'startTokenPos' => 644,
                'startFilePos' => 4175,
                'endTokenPos' => 645,
                'endFilePos' => 4176,
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
            'startLine' => 169,
            'endLine' => 169,
            'startColumn' => 29,
            'endColumn' => 47,
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
 * Extract the cost value from the options array.
 *
 * @param  array  $options
 * @return int
 */',
        'startLine' => 169,
        'endLine' => 172,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Illuminate\\Hashing',
        'declaringClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'implementingClassName' => 'Illuminate\\Hashing\\BcryptHasher',
        'currentClassName' => 'Illuminate\\Hashing\\BcryptHasher',
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