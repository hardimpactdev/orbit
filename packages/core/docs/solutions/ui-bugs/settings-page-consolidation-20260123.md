---
date: 2026-01-23
problem_type: ui-organization
component: orbit-core/settings
severity: minor
symptoms:
  - "Multiple settings pages with overlapping functionality"
  - "Settings.vue and environments/Settings.vue both existed"
  - "Confusing navigation structure"
root_cause: Settings functionality was split across multiple pages
tags: [ui, settings, navigation, consolidation]
---

# Settings Page Consolidation to Configuration

## Symptom
The orbit-core project had multiple settings pages:
- Global `Settings.vue` - Code editor, terminal, SSH keys, external access, templates
- Environment-specific `environments/Settings.vue` - 9 tabs including Environment, Configuration, DNS, External Access, Templates, SSH Keys, Desktop, Health Check, Danger Zone

This created confusion about where to find specific settings.

## Investigation
1. Attempted: Initially tried to merge everything into Configuration tab
   Result: DNS settings were included in the main Configuration tab, making it too crowded

## Root Cause
Settings functionality evolved organically over time, leading to duplication and unclear organization. The same settings (external access, templates, SSH keys) appeared in multiple places.

## Solution
Consolidated into a single Configuration page with 4 minimal tabs:

```vue
// environments/Configuration.vue
type TabId = "configuration" | "dns" | "templates" | "advanced";

const tabs = computed(() => {
    const allTabs = [
        { id: "configuration" as TabId, label: "Configuration", icon: Settings2 },
        { id: "dns" as TabId, label: "DNS", icon: Globe },
        { id: "templates" as TabId, label: "Templates", icon: FileCode2 },
        { id: "advanced" as TabId, label: "Advanced", icon: Wrench },
    ];
    return allTabs;
});
```

### Tab Organization
| Tab | Contents |
|-----|----------|
| **Configuration** | Environment name, code editor, SSH connection (remote), project paths, TLD, PHP version, external access, SSH keys |
| **DNS** | DNS mappings (separate tab as requested) |
| **Templates** | Template favorites |
| **Advanced** | Desktop settings, health check, danger zone |

### Route Updates
```php
// routes/environment.php
Route::get('configuration', [EnvironmentController::class, 'settings'])->name('environments.configuration');
Route::post('configuration', [EnvironmentController::class, 'updateSettings'])->name('environments.configuration.update');
Route::post('configuration/external-access', [EnvironmentController::class, 'updateExternalAccess'])->name('environments.configuration.external-access');
// Backwards compatibility
Route::redirect('settings', 'configuration');
```

### Navigation Update
```php
// HandleInertiaRequests.php
[
    'title' => 'Configuration',
    'href' => "{$urlPrefix}/configuration",
    'icon' => 'Settings2',
    'isActive' => str_starts_with($currentPath, "{$pathPrefix}configuration"),
],
```

## Prevention
- When adding new settings, consider which tab they belong in
- Keep DNS as a separate tab for clarity
- Group related settings (external access with other configuration items)
- Use clear tab names that indicate content

## Related
- Removed files: `resources/js/pages/Settings.vue`, `resources/js/pages/environments/Settings.vue`
- New file: `resources/js/pages/environments/Configuration.vue`