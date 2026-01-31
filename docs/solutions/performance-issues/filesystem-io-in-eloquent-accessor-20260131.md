---
date: 2026-01-31
problem_type: performance
component: Eloquent Models
severity: moderate
symptoms:
  - "Slow page loads when listing collections"
  - "N filesystem calls for N records"
root_cause: Accessor performs file_exists() on every access
tags: [eloquent, accessor, filesystem, n+1]
---

# Filesystem I/O in Eloquent Accessor Causes N+1 Problem

## Symptom

When loading a collection of Workspaces, page load was slow. Each workspace triggered a `file_exists()` call during serialization.

## Investigation

1. Attempted: Eager loading relationships
   Result: No improvement - issue wasn't database related

2. Identified: Accessor `getHasWorkspaceFileAttribute()` performed filesystem I/O

## Root Cause

Laravel accessors are called automatically during:
- `toArray()` / `toJson()` serialization
- Inertia prop passing
- API responses

When iterating over a collection, each record triggers the accessor, causing N filesystem calls.

## Solution

Change from accessor to explicit method. Call only when needed.

```php
// Before (broken) - accessor causes N file_exists() calls
public function getHasWorkspaceFileAttribute(): bool
{
    if (! $this->path) {
        return false;
    }
    $workspaceFile = rtrim($this->path, '/').'/'.$this->name.'.code-workspace';
    return file_exists($workspaceFile);
}

// After (fixed) - explicit method called when needed
/**
 * NOTE: Explicit method (not accessor) because it performs
 * filesystem I/O. Call only when needed to avoid N file_exists()
 * calls when iterating over collections.
 */
public function hasWorkspaceFile(): bool
{
    if (! $this->path) {
        return false;
    }
    $workspaceFile = rtrim($this->path, '/').'/'.$this->name.'.code-workspace';
    return file_exists($workspaceFile);
}

// Update toFrontendArray() to call method explicitly
'has_workspace_file' => $this->hasWorkspaceFile(),
```

## Prevention

- **Rule**: Never perform I/O (filesystem, HTTP, database) in accessors
- **Accessors should be**: Pure transformations of existing model data
- **For I/O**: Use explicit methods with clear naming (`hasWorkspaceFile()` vs attribute)
- **Comment**: Add NOTE explaining why it's a method not accessor

## Related

- Similar pattern for: API calls, cache lookups, external service checks
- Alternative: Cache the result in a database column if frequently needed
