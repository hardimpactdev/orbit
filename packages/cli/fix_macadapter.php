<?php

$file = file_get_contents($argv[1]);

// Add helper method to get brew formula name for PHP version
$helperMethod = '
    /**
     * Get the Homebrew formula name for a PHP version.
     * PHP 8.5 is the current default and uses just \'php\', older versions use \'php@X.Y\'.
     */
    protected function getBrewPhpFormula(string $version): string
    {
        $normalized = $this->normalizePhpVersion($version);

        // PHP 8.5 is the current Homebrew default (no version suffix)
        if ($normalized === \'8.5\') {
            return \'php\';
        }

        return \'php@\' . $normalized;
    }
';

// Insert before the last closing brace
$file = preg_replace('/\n}\s*$/', $helperMethod."\n}\n", $file);

// isPhpInstalled - use helper
$file = str_replace(
    'Process::run("brew list php@{$normalizedVersion}")',
    'Process::run("brew list ".$this->getBrewPhpFormula($normalizedVersion))',
    $file
);

// startPhpFpm - use helper
$file = str_replace(
    'Process::run("brew services start php@{$normalizedVersion}")',
    'Process::run("brew services start ".$this->getBrewPhpFormula($normalizedVersion))',
    $file
);

// stopPhpFpm - use helper
$file = str_replace(
    'Process::run("brew services stop php@{$normalizedVersion}")',
    'Process::run("brew services stop ".$this->getBrewPhpFormula($normalizedVersion))',
    $file
);

// restartPhpFpm - use helper
$file = str_replace(
    'Process::run("brew services restart php@{$normalizedVersion}")',
    'Process::run("brew services restart ".$this->getBrewPhpFormula($normalizedVersion))',
    $file
);

// isPhpFpmRunning - use pgrep instead (more reliable, handles all states)
$file = str_replace(
    'Process::run("brew services list | grep php@{$normalizedVersion} | grep -q started")',
    'Process::run("pgrep -f \'php-fpm.*".str_replace(".", "", $normalizedVersion)."\' > /dev/null")',
    $file
);

// getPhpBinaryPath - use helper
$file = str_replace(
    'return "/opt/homebrew/opt/php@{$normalizedVersion}/bin/php"',
    'return "/opt/homebrew/opt/".$this->getBrewPhpFormula($normalizedVersion)."/bin/php"',
    $file
);

// getPoolConfigDir - use helper
$file = str_replace(
    'return "/opt/homebrew/etc/php/{$normalizedVersion}/php-fpm.d"',
    'return "/opt/homebrew/etc/php/".$normalizedVersion."/php-fpm.d"',
    $file
);

file_put_contents($argv[1], $file);
echo "Done\n";
