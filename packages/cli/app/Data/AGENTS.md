# Data Directory

DTOs and data structures for passing state between components.

## Structure

```
Data/
└── ServiceTemplate.php        # Docker service config
```

## Provisioning Data Classes

**Note:** Provisioning data classes have been moved to `orbit-core`:

| Class | Location | Purpose |
|-------|----------|---------|
| `ProvisionContext` | `orbit-core/src/Data/` | Context for provisioning actions |
| `StepResult` | `orbit-core/src/Data/` | Action result wrapper |
| `RepoIntent` | `orbit-core/src/Enums/` | Repository operation type enum |

### Using orbit-core Data Classes

```php
use HardImpact\Orbit\Core\Data\ProvisionContext;
use HardImpact\Orbit\Core\Data\StepResult;
use HardImpact\Orbit\Core\Enums\RepoIntent;
```

## StepResult (from orbit-core)

Result object returned by all provision and install actions:

```php
use HardImpact\Orbit\Core\Data\StepResult;

// Success
return StepResult::success();
return StepResult::success(['key' => 'value']);

// Failure
return StepResult::failed('Something went wrong');

// Check result
if ($result->isFailed()) {
    echo $result->error;
}
if ($result->isSuccess()) {
    $data = $result->data;
}
```

## DTO Conventions

- Use `final readonly` classes
- Constructor property promotion for all properties
- Static factory methods for common patterns
- Immutable - use `withX()` methods for updates
- Enums for fixed sets of values
