<?php declare(strict_types = 1);

// osfsl-/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../nativephp/electron/src/Commands/BundleCommand.php-PHPStan\BetterReflection\Reflection\ReflectionClass-Native\Electron\Commands\BundleCommand
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v2-761fd28347e48f0deda327378f1a2a11b485f4287ab3b5e57f2951c067bb1e03-8.5.2-6.65.0.9',
   'data' => 
  array (
    'locatedSource' => 
    array (
      'class' => 'PHPStan\\BetterReflection\\SourceLocator\\Located\\LocatedSource',
      'data' => 
      array (
        'name' => 'Native\\Electron\\Commands\\BundleCommand',
        'filename' => '/home/nckrtl/projects/orbit-dev/packages/desktop/vendor/composer/../nativephp/electron/src/Commands/BundleCommand.php',
      ),
    ),
    'namespace' => 'Native\\Electron\\Commands',
    'name' => 'Native\\Electron\\Commands\\BundleCommand',
    'shortName' => 'BundleCommand',
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
            'code' => '\'native:bundle\'',
            'attributes' => 
            array (
              'startLine' => 27,
              'endLine' => 27,
              'startTokenPos' => 111,
              'startFilePos' => 817,
              'endTokenPos' => 111,
              'endFilePos' => 831,
            ),
          ),
          'description' => 
          array (
            'code' => '\'Bundle your application for distribution.\'',
            'attributes' => 
            array (
              'startLine' => 28,
              'endLine' => 28,
              'startTokenPos' => 117,
              'startFilePos' => 851,
              'endTokenPos' => 117,
              'endFilePos' => 893,
            ),
          ),
        ),
      ),
    ),
    'startLine' => 26,
    'endLine' => 412,
    'startColumn' => 1,
    'endColumn' => 1,
    'parentClassName' => 'Illuminate\\Console\\Command',
    'implementsClassNames' => 
    array (
    ),
    'traitClassNames' => 
    array (
      0 => 'Native\\Electron\\Traits\\CleansEnvFile',
      1 => 'Native\\Electron\\Traits\\CopiesToBuildDirectory',
      2 => 'Native\\Electron\\Traits\\HandlesZephpyr',
      3 => 'Native\\Electron\\Traits\\HasPreAndPostProcessing',
      4 => 'Native\\Electron\\Traits\\InstallsAppIcon',
      5 => 'Native\\Electron\\Traits\\LocatesPhpBinary',
      6 => 'Native\\Electron\\Traits\\PatchesPackagesJson',
      7 => 'Native\\Electron\\Traits\\PrunesVendorDirectory',
    ),
    'immediateConstants' => 
    array (
    ),
    'immediateProperties' => 
    array (
      'signature' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'name' => 'signature',
        'modifiers' => 2,
        'type' => NULL,
        'default' => 
        array (
          'code' => '\'native:bundle {--fetch} {--clear} {--without-cleanup}\'',
          'attributes' => 
          array (
            'startLine' => 41,
            'endLine' => 41,
            'startTokenPos' => 179,
            'startFilePos' => 1188,
            'endTokenPos' => 179,
            'endFilePos' => 1242,
          ),
        ),
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 41,
        'endLine' => 41,
        'startColumn' => 5,
        'endColumn' => 83,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'key' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'name' => 'key',
        'modifiers' => 4,
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
        'default' => NULL,
        'docComment' => NULL,
        'attributes' => 
        array (
        ),
        'startLine' => 43,
        'endLine' => 43,
        'startColumn' => 5,
        'endColumn' => 25,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'zipPath' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'name' => 'zipPath',
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
        'startLine' => 45,
        'endLine' => 45,
        'startColumn' => 5,
        'endColumn' => 28,
        'isPromoted' => false,
        'declaredAtCompileTime' => true,
        'immediateVirtual' => false,
        'immediateHooks' => 
        array (
        ),
      ),
      'zipName' => 
      array (
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'name' => 'zipName',
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
        'startLine' => 47,
        'endLine' => 47,
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
            'name' => 'int',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 49,
        'endLine' => 137,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 1,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'zipApplication' => 
      array (
        'name' => 'zipApplication',
        'parameters' => 
        array (
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
        'startLine' => 139,
        'endLine' => 160,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'checkComposerJson' => 
      array (
        'name' => 'checkComposerJson',
        'parameters' => 
        array (
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
        'startLine' => 162,
        'endLine' => 209,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'addFilesToZip' => 
      array (
        'name' => 'addFilesToZip',
        'parameters' => 
        array (
          'zip' => 
          array (
            'name' => 'zip',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'ZipArchive',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 223,
            'endLine' => 223,
            'startColumn' => 36,
            'endColumn' => 50,
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
        'docComment' => NULL,
        'startLine' => 223,
        'endLine' => 285,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'finderToZip' => 
      array (
        'name' => 'finderToZip',
        'parameters' => 
        array (
          'finder' => 
          array (
            'name' => 'finder',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Symfony\\Component\\Finder\\Finder',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 287,
            'endLine' => 287,
            'startColumn' => 34,
            'endColumn' => 47,
            'parameterIndex' => 0,
            'isOptional' => false,
          ),
          'zip' => 
          array (
            'name' => 'zip',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'ZipArchive',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 287,
            'endLine' => 287,
            'startColumn' => 50,
            'endColumn' => 64,
            'parameterIndex' => 1,
            'isOptional' => false,
          ),
          'path' => 
          array (
            'name' => 'path',
            'default' => 
            array (
              'code' => 'null',
              'attributes' => 
              array (
                'startLine' => 287,
                'endLine' => 287,
                'startTokenPos' => 1425,
                'startFilePos' => 9520,
                'endTokenPos' => 1425,
                'endFilePos' => 9523,
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
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 287,
            'endLine' => 287,
            'startColumn' => 67,
            'endColumn' => 86,
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
            'name' => 'void',
            'isIdentifier' => true,
          ),
        ),
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 287,
        'endLine' => 299,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'sendToZephpyr' => 
      array (
        'name' => 'sendToZephpyr',
        'parameters' => 
        array (
        ),
        'returnsReference' => false,
        'returnType' => NULL,
        'attributes' => 
        array (
        ),
        'docComment' => NULL,
        'startLine' => 301,
        'endLine' => 311,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'fetchLatestBundle' => 
      array (
        'name' => 'fetchLatestBundle',
        'parameters' => 
        array (
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
        'startLine' => 313,
        'endLine' => 350,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'exitWithMessage' => 
      array (
        'name' => 'exitWithMessage',
        'parameters' => 
        array (
          'message' => 
          array (
            'name' => 'message',
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
            'startLine' => 352,
            'endLine' => 352,
            'startColumn' => 40,
            'endColumn' => 54,
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
        'docComment' => NULL,
        'startLine' => 352,
        'endLine' => 358,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'handleApiErrors' => 
      array (
        'name' => 'handleApiErrors',
        'parameters' => 
        array (
          'result' => 
          array (
            'name' => 'result',
            'default' => NULL,
            'type' => 
            array (
              'class' => 'PHPStan\\BetterReflection\\Reflection\\ReflectionNamedType',
              'data' => 
              array (
                'name' => 'Illuminate\\Http\\Client\\Response',
                'isIdentifier' => false,
              ),
            ),
            'isVariadic' => false,
            'byRef' => false,
            'isPromoted' => false,
            'attributes' => 
            array (
            ),
            'startLine' => 360,
            'endLine' => 360,
            'startColumn' => 38,
            'endColumn' => 53,
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
        'docComment' => NULL,
        'startLine' => 360,
        'endLine' => 372,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 4,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'cleanUp' => 
      array (
        'name' => 'cleanUp',
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
        'startLine' => 374,
        'endLine' => 396,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
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
                'startLine' => 398,
                'endLine' => 398,
                'startTokenPos' => 2289,
                'startFilePos' => 13395,
                'endTokenPos' => 2289,
                'endFilePos' => 13396,
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
            'startLine' => 398,
            'endLine' => 398,
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
        'startLine' => 398,
        'endLine' => 401,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'aliasName' => NULL,
      ),
      'zipPath' => 
      array (
        'name' => 'zipPath',
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
                'startLine' => 403,
                'endLine' => 403,
                'startTokenPos' => 2321,
                'startFilePos' => 13512,
                'endTokenPos' => 2321,
                'endFilePos' => 13513,
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
            'startLine' => 403,
            'endLine' => 403,
            'startColumn' => 32,
            'endColumn' => 48,
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
        'startLine' => 403,
        'endLine' => 406,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
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
                'startLine' => 408,
                'endLine' => 408,
                'startTokenPos' => 2353,
                'startFilePos' => 13632,
                'endTokenPos' => 2353,
                'endFilePos' => 13633,
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
            'startLine' => 408,
            'endLine' => 408,
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
        'startLine' => 408,
        'endLine' => 411,
        'startColumn' => 5,
        'endColumn' => 5,
        'couldThrow' => false,
        'isClosure' => false,
        'isGenerator' => false,
        'isVariadic' => false,
        'modifiers' => 2,
        'namespace' => 'Native\\Electron\\Commands',
        'declaringClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'implementingClassName' => 'Native\\Electron\\Commands\\BundleCommand',
        'currentClassName' => 'Native\\Electron\\Commands\\BundleCommand',
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