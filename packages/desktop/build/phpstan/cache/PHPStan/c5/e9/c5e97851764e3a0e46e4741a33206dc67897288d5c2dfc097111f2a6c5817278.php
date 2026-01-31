<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../nativephp/electron/src/Commands/BuildCommand.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Native\Electron\Commands\BuildCommand
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-4db0044a377097e54ea74cb2d984c8df6f885f77732b7789e8869999418b4ed5-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Native\\Electron\\Commands\\BuildCommand',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../nativephp/electron/src/Commands/BuildCommand.php',
      ),
    ),
    'namespace' => 'Native\\Electron\\Commands',
    'name' => 'Native\\Electron\\Commands\\BuildCommand',
    'shortName' => 'BuildCommand',
    'isInterface' => false,
    'isTrait' => false,
    'isEnum' => false,
    'isBackedEnum' => false,
    'modifiers' => 0,
    'docComment' => NULL,
    'attributes' => 
    array (
      0 => 
      array (
        'name' => 'Symfony\\Component\\Console\\Attribute\\AsCommand',
        'isRepeated' => false,
        'arguments' => 
        array (
          'name' => 
          array (
            'code' => '\'native:build\'',
            'attributes' => 
            array (
              'startLine' => 24,
              'endLine' => 24,
              'startTokenPos' => 100,
              'startFilePos' => 781,
              'endTokenPos' => 100,
              'endFilePos' => 794,
            ),
          ),
          'description' => 
          array (
            'code' => '\'Build the NativePHP application for the specified operating system and architecture.\'',
            'attributes' => 
            array (
              'startLine' => 25,
              'endLine' => 25,
              'startTokenPos' => 106,
              'startFilePos' => 814,
              'endTokenPos' => 106,
              'endFilePos' => 899,
            ),
          ),
        ),
      ),
    ),
    'startLine' => 23,
    'endLine' => 194,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Console\\Command',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Native\\Electron\\Traits\\CleansEnvFile',
      1 => 'Native\\Electron\\Traits\\CopiesBundleToBuildDirectory',
      2 => 'Native\\Electron\\Traits\\CopiesCertificateAuthority',
      3 => 'Native\\Electron\\Traits\\HasPreAndPostProcessing',
      4 => 'Native\\Electron\\Traits\\InstallsAppIcon',
      5 => 'Native\\Electron\\Traits\\LocatesPhpBinary',
      6 => 'Native\\Electron\\Traits\\OsAndArch',
      7 => 'Native\\Electron\\Traits\\PatchesPackagesJson',
      8 => 'Native\\Electron\\Traits\\PrunesVendorDirectory',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'signature' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'name' => 'signature',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '\'native:build
        {os? : The operating system to build for (all, linux, mac, win)}
        {arch? : The Processor Architecture to build for (x64, x86, arm64)}
        {--publish : to publish the app}\'',
          'attributes' => 
          array (
            'startLine' => 39,
            'endLine' => 42,
            'startTokenPos' => 173,
            'startFilePos' => 1230,
            'endTokenPos' => 173,
            'endFilePos' => 1433,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 39,
        'endLine' => 42,
        'startColumn' => 5,
        'endColumn' => 42,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'availableOs' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'name' => 'availableOs',
        'modifiers' => 2,
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
          'code' => '[\'win\', \'linux\', \'mac\', \'all\']',
          'attributes' => 
          array (
            'startLine' => 44,
            'endLine' => 44,
            'startTokenPos' => 184,
            'startFilePos' => 1472,
            'endTokenPos' => 195,
            'endFilePos' => 1501,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 44,
        'endLine' => 44,
        'startColumn' => 5,
        'endColumn' => 66,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'buildCommand' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'name' => 'buildCommand',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 46,
        'endLine' => 46,
        'startColumn' => 5,
        'endColumn' => 33,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'buildOS' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'name' => 'buildOS',
        'modifiers' => 4,
        'type' => 
        array (
          'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
          'data' => 
          array (
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 48,
        'endLine' => 48,
        'startColumn' => 5,
        'endColumn' => 28,
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
      'buildPath' => 
      array (
        'name' => 'buildPath',
        'parameters' => 
        array (
          'path' => 
          array (
            'name' => 'path',
            'default' => 
            array (
              'code' => '\'\'',
              'attributes' => 
              array (
                'startLine' => 50,
                'endLine' => 50,
                'startTokenPos' => 224,
                'startFilePos' => 1618,
                'endTokenPos' => 224,
                'endFilePos' => 1619,
              ),
            ),
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
            'startLine' => 50,
            'endLine' => 50,
            'startColumn' => 34,
            'endColumn' => 50,
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
        'docComment' => NULL,
        'startLine' => 50,
        'endLine' => 53,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'aliasName' => NULL,
      ),
      'sourcePath' => 
      array (
        'name' => 'sourcePath',
        'parameters' => 
        array (
          'path' => 
          array (
            'name' => 'path',
            'default' => 
            array (
              'code' => '\'\'',
              'attributes' => 
              array (
                'startLine' => 55,
                'endLine' => 55,
                'startTokenPos' => 255,
                'startFilePos' => 1759,
                'endTokenPos' => 255,
                'endFilePos' => 1760,
              ),
            ),
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
            'startLine' => 55,
            'endLine' => 55,
            'startColumn' => 35,
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
            'name' => 'string',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 55,
        'endLine' => 58,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'aliasName' => NULL,
      ),
      'handle' => 
      array (
        'name' => 'handle',
        'parameters' => 
        array (
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
        'startLine' => 60,
        'endLine' => 81,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'aliasName' => NULL,
      ),
      'buildBundle' => 
      array (
        'name' => 'buildBundle',
        'parameters' => 
        array (
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
        'startLine' => 83,
        'endLine' => 102,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'aliasName' => NULL,
      ),
      'buildUnsecure' => 
      array (
        'name' => 'buildUnsecure',
        'parameters' => 
        array (
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
        'startLine' => 104,
        'endLine' => 134,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'aliasName' => NULL,
      ),
      'getEnvironmentVariables' => 
      array (
        'name' => 'getEnvironmentVariables',
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
        'docComment' => NULL,
        'startLine' => 136,
        'endLine' => 168,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'aliasName' => NULL,
      ),
      'updateElectronDependencies' => 
      array (
        'name' => 'updateElectronDependencies',
        'parameters' => 
        array (
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
        'startLine' => 170,
        'endLine' => 180,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'aliasName' => NULL,
      ),
      'buildOrPublish' => 
      array (
        'name' => 'buildOrPublish',
        'parameters' => 
        array (
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
        'startLine' => 182,
        'endLine' => 193,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BuildCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BuildCommand',
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