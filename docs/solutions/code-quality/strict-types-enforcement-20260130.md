---
date: 2026-01-30
problem_type: code-quality
component: all-packages
severity: moderate
symptoms:
  - "Missing declare(strict_types=1) in PHP files"
  - "Mixed typing mode in codebase"
  - "Weak type enforcement"
root_cause: Not all PHP files had strict typing enabled
tags: [strict-types, code-quality, type-safety, php]
---

# Adding declare(strict_types=1) to All PHP Files

## Symptom
Codebase had inconsistent strict typing:
- Some files: `<?php declare(strict_types=1);`
- Most files: `<?php` only
- Weak type coercion occurring

## Impact
Without strict types:
```php
function add(int $a, int $b): int {
    return $a + $b;
}

add("5", "10"); // Returns 15 (string coerced to int)
```

With strict types:
```php
<?php declare(strict_types=1);

function add(int $a, int $b): int {
    return $a + $b;
}

add("5", "10"); // TypeError: must be of type int, string given
```

## Solution

### Batch Add strict_types to All Files

**For Core Package:**
```bash
cd packages/core
find src -name "*.php" -exec grep -L "declare(strict_types=1)" {} \; | while read file; do
  if head -3 "$file" | grep -q "^<?php$"; then
    sed -i '1a\\ndeclare(strict_types=1);' "$file"
  fi
done
```

**For App Package:**
```bash
cd packages/app
find src -name "*.php" -exec grep -L "declare(strict_types=1)" {} \; | while read file; do
  sed -i '1a\\ndeclare(strict_types=1);' "$file"
done
```

**For CLI Package:**
```bash
cd packages/cli
find app -name "*.php" -exec grep -L "declare(strict_types=1)" {} \; | while read file; do
  sed -i '1a\\ndeclare(strict_types=1);' "$file"
done
```

## Stats

| Package | Files Updated |
|---------|---------------|
| Core | 77 files |
| App | 13 files |
| CLI | 69 files |
| Desktop | 30+ files |
| Web | 13 files |
| **Total** | **200+ files** |

## Prevention

### IDE Configuration

**PHPStorm:**
1. Settings → Editor → File and Code Templates
2. PHP File template:
```
<?php
declare(strict_types=1);

namespace ${NAMESPACE};

${CLASS_declaration}
```

**VS Code:**
Install "PHP Strict Types" extension for auto-insertion.

### CI/CD Check

Add to pre-commit hook or CI:
```bash
#!/bin/bash
files=$(find packages -name "*.php" -exec grep -L "declare(strict_types=1)" {} \;)
if [ -n "$files" ]; then
  echo "Files missing strict_types:"
  echo "$files"
  exit 1
fi
```

### Code Review Checklist:
- [ ] All new PHP files have `declare(strict_types=1);`
- [ ] Declaration on line 2 (after `<?php`)
- [ ] No space before colon: `declare(strict_types:1);`

## Related
- service-locator-app-helper.md
- monorepo-coding-standards.md
