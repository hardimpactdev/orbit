#!/usr/bin/env php
<?php

function ask(string $question, string $default = ''): string
{
    $answer = readline($question.($default ? " ({$default})" : null).': ');

    if (! $answer) {
        return $default;
    }

    return $answer;
}

function confirm(string $question, bool $default = false): bool
{
    $answer = ask($question.' ('.($default ? 'Y/n' : 'y/N').')');

    if (strtolower($answer) === 'y' || strtolower($answer) === 'yes') {
        return true;
    }

    if (strtolower($answer) === 'n' || strtolower($answer) === 'no') {
        return false;
    }

    return $default;
}

function writeln(string $line): void
{
    echo $line.PHP_EOL;
}

function run(string $command): string
{
    return trim((string) shell_exec($command));
}

function str_after(string $subject, string $search): string
{
    $pos = strrpos($subject, $search);

    if ($pos === false) {
        return $subject;
    }

    return substr($subject, $pos + strlen($search));
}

function slugify(string $subject): string
{
    return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $subject), '-'));
}

function title_case(string $subject): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $subject)));
}

function title_snake(string $subject, string $replace = '_'): string
{
    return str_replace(['-', '_'], $replace, $subject);
}

function replace_in_file(string $file, array $replacements): void
{
    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        str_replace(
            array_keys($replacements),
            array_values($replacements),
            $contents
        )
    );
}

function remove_prefix(string $prefix, string $content): string
{
    if (str_starts_with($content, $prefix)) {
        return substr($content, strlen($prefix));
    }

    return $content;
}

function remove_composer_deps(array $names)
{
    $data = json_decode(file_get_contents(__DIR__.'/composer.json'), true);

    foreach ($data['require-dev'] as $name => $version) {
        if (in_array($name, $names, true)) {
            unset($data['require-dev'][$name]);
        }
    }

    file_put_contents(__DIR__.'/composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function remove_composer_script($scriptName)
{
    $data = json_decode(file_get_contents(__DIR__.'/composer.json'), true);

    if (isset($data['scripts'][$scriptName])) {
        unset($data['scripts'][$scriptName]);
    }

    file_put_contents(__DIR__.'/composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function remove_readme_paragraphs(string $file): void
{
    $contents = file_get_contents($file);

    file_put_contents(
        $file,
        preg_replace('/<!--delete-->.*<!--\/delete-->/s', '', $contents) ?: $contents
    );
}

function safeUnlink(string $filename): void
{
    if (file_exists($filename) && is_file($filename)) {
        unlink($filename);
    }
}

function determineSeparator(string $path): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $path);
}

function replaceForWindows(): array
{
    return preg_split('/\\r\\n|\\r|\\n/', run('dir /S /B * | findstr /v /i .git\ | findstr /v /i vendor | findstr /v /i '.basename(__FILE__).' | findstr /r /i /M /F:/ ":author :vendor :package VendorName skeleton migration_table_name vendor_name vendor_slug author@domain.com"'));
}

function replaceForAllOtherOSes(): array
{
    return explode(PHP_EOL, run('grep -E -r -l -i ":author|:vendor|:package|VendorName|skeleton|migration_table_name|vendor_name|vendor_slug|author@domain.com" --exclude-dir=vendor ./* ./.github/* | grep -v '.basename(__FILE__)));
}

$gitName = run('git config user.name');
$gitEmail = run('git config user.email');

$usernameGuess = explode(':', run('git config remote.origin.url'))[1];
$usernameGuess = dirname($usernameGuess);
$usernameGuess = basename($usernameGuess);
$authorGuess = $gitName ?: $usernameGuess;
$vendorGuess = $usernameGuess;
$packageGuess = remove_prefix('laravel-', basename(getcwd()));
$packageGuess = remove_prefix('php-', $packageGuess);

$authorName = ask('Author name', $authorGuess);
$authorEmail = ask('Author email', $gitEmail);
$usernameVendor = ask('Vendor name', $vendorGuess);
$packageName = ask('Package name', $packageGuess);
$packageDescription = ask('Package description', 'Web UI for Orbit');

writeln('------');
writeln("Author     : {$authorName} ({$authorEmail})");
writeln("Vendor     : {$usernameVendor}");
writeln("Package    : {$packageName}");
writeln('Namespace  : '.title_case($usernameVendor).'\\'.title_case($packageName));
writeln('Class name : '.title_case($packageName));
writeln("Description: {$packageDescription}");
writeln('------');

writeln('This script will replace the above values in all relevant files in the project directory.');

if (! confirm('Modify files?', true)) {
    exit(1);
}

$files = (str_starts_with(strtoupper(PHP_OS), 'WIN') ? replaceForWindows() : replaceForAllOtherOSes());

foreach ($files as $file) {
    replace_in_file($file, [
        ':author_name' => $authorName,
        ':author_username' => $usernameVendor,
        ':author_website' => "https://github.com/{$usernameVendor}",
        ':author_email' => $authorEmail,
        ':vendor_name' => $usernameVendor,
        ':vendor_slug' => slugify($usernameVendor),
        ':package_name' => $packageName,
        ':package_slug' => slugify($packageName),
        ':package_slug_without_prefix' => remove_prefix('laravel-', slugify($packageName)),
        ':package_description' => $packageDescription,
        'VendorName' => title_case($usernameVendor),
        'skeleton' => title_case($packageName),
        'migration_table_name' => title_snake($packageName),
        'vendor_name' => $usernameVendor,
        'vendor_slug' => slugify($usernameVendor),
        'author@domain.com' => $authorEmail,
        ':Author Name' => $authorName,
        ':Package' => title_case($packageName),
    ]);

    match (true) {
        str_contains($file, determineSeparator('src/Skeleton.php')) => rename($file, determineSeparator('./src/'.title_case($packageName).'.php')),
        str_contains($file, determineSeparator('src/SkeletonServiceProvider.php')) => rename($file, determineSeparator('./src/'.title_case($packageName).'ServiceProvider.php')),
        str_contains($file, determineSeparator('src/Facades/Skeleton.php')) => rename($file, determineSeparator('./src/Facades/'.title_case($packageName).'.php')),
        str_contains($file, determineSeparator('src/Commands/SkeletonCommand.php')) => rename($file, determineSeparator('./src/Commands/'.title_case($packageName).'Command.php')),
        str_contains($file, determineSeparator('database/migrations/create_skeleton_table.php.stub')) => rename($file, determineSeparator('./database/migrations/create_'.title_snake($packageName).'_table.php.stub')),
        str_contains($file, determineSeparator('config/skeleton.php')) => rename($file, determineSeparator('./config/'.slugify($packageName).'.php')),
        default => [],
    };
}

confirm('Execute `composer install` and run tests?') && run('composer install && composer test');

confirm('Let this script delete itself?', true) && unlink(__FILE__);
