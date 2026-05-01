<?php

declare(strict_types=1);

namespace OrbitDocsLinter;

enum CommandDocsLintSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
