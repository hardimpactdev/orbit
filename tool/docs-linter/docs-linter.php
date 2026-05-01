#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace OrbitDocsLinterCli;

use Composer\Autoload\ClassLoader;
use OrbitDocsLinter\CommandDocsLintCli;

/** @var ClassLoader $loader */
$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
$loader->addPsr4('OrbitDocsLinter\\', __DIR__.'/src');

exit((new CommandDocsLintCli)->run($_SERVER['argv'] ?? []));
