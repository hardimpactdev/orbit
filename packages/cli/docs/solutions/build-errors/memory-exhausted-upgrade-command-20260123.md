---
date: 2026-01-23
problem_type: runtime-error
component: UpgradeCommand
severity: critical
symptoms:
  - "PHP Fatal error: Allowed memory size of 134217728 bytes exhausted"
  - "orbit upgrade failing when downloading PHAR"
root_cause: file_get_contents loading entire 60MB PHAR into memory
tags: [memory, upgrade, phar]
---

# Memory Exhaustion During orbit upgrade

## Symptom
```
PHP Fatal error:  Allowed memory size of 134217728 bytes exhausted (tried to allocate 49610784 bytes) 
in phar:///Users/nckrtl/.local/bin/orbit/app/Commands/UpgradeCommand.php on line 260
```

## Investigation
1. Attempted: Using file_get_contents to download PHAR
   Result: Loads entire 60MB file into memory, exhausting PHP's 128MB limit

## Root Cause
The `downloadFile` method used `file_get_contents` with stream context, which loads the entire file into memory before writing to disk. With a ~60MB PHAR file, this exhausts PHP's default memory limit.

## Solution
Replace file_get_contents with curl to stream directly to file:

```php
// Before (broken)
private function downloadFile(string $url, string $destination): bool
{
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: orbit-cli\r\n",
            'timeout' => 120,
            'follow_location' => true,
        ],
    ]);

    $content = @file_get_contents($url, false, $context);
    if ($content === false) {
        return false;
    }

    return file_put_contents($destination, $content) !== false;
}

// After (fixed)
private function downloadFile(string $url, string $destination): bool
{
    // Use curl to stream directly to file (avoids memory exhaustion with large PHARs)
    $command = sprintf(
        'curl -fSL --max-time 300 -o %s %s 2>/dev/null',
        escapeshellarg($destination),
        escapeshellarg($url)
    );

    $result = null;
    $output = null;
    exec($command, $output, $result);

    return $result === 0 && file_exists($destination) && filesize($destination) > 0;
}
```

## Prevention
- Always use streaming methods (curl, fopen with stream_copy_to_stream) for large file downloads
- Never use file_get_contents for files over a few MB
- Consider adding memory_limit check before operations that could exhaust memory
- Add test case that downloads a large file to ensure streaming works

## Related
- PHP memory management: https://www.php.net/manual/en/features.gc.php
- Stream context options: https://www.php.net/manual/en/context.php