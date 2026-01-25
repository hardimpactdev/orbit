---
date: 2026-01-21
problem_type: ui_bug
component: orbit-core/resources/js
severity: moderate
symptoms:
  - "Tables not matching dark theme design"
  - "Craft UI components not fully styled"
  - "Background colors inconsistent between sidebar and main content"
  - "Missing hover states and action patterns"
root_cause: Craft UI components require explicit styling classes beyond variant props
tags: [craft-ui, styling, dark-theme, tables]
---

# Craft UI Components Require Explicit Styling in Dark Theme

## Symptom
After implementing a dark theme redesign, Craft UI components (especially Table, Button, Select) were not properly styled. Tables appeared with default light theme styling, buttons lacked proper hover states, and background colors were inconsistent (zinc-900 vs zinc-950).

## Investigation
1. Attempted: Relying on Craft UI variant props alone
   Result: Components had incorrect colors and minimal styling

2. Attempted: Using Table component from Craft UI
   Result: Limited control over column widths, hover patterns, and nested rows

## Root Cause
Craft UI components are minimally styled by default and expect explicit Tailwind classes for complete theming. The `variant` prop alone is insufficient for dark theme implementations.

## Solution
Replace Table components with CSS grid layouts and add explicit styling to all Craft UI components:

```vue
// Before (broken)
<Button variant="outline">Open</Button>

<Table>
  <TableHeader>
    <TableRow>
      <TableHead>Site</TableHead>
    </TableRow>
  </TableHeader>
</Table>

// After (fixed)
<Button 
  variant="outline" 
  class="bg-transparent border-zinc-700 text-zinc-300 hover:bg-zinc-800"
>
  Open
</Button>

<!-- Grid-based table -->
<div class="rounded-lg border border-zinc-800 bg-zinc-900/50">
  <div class="grid grid-cols-[1fr_100px_140px] gap-4 px-4 py-3 bg-zinc-800/30 border-b border-zinc-800">
    <div class="text-xs font-medium text-zinc-500 uppercase tracking-wide">Site</div>
  </div>
  <div class="grid grid-cols-[1fr_100px_140px] gap-4 px-4 py-3 hover:bg-zinc-800/20 group">
    <!-- Row content -->
    <div class="opacity-40 group-hover:opacity-100 transition-opacity">
      <!-- Actions -->
    </div>
  </div>
</div>
```

Also ensure consistent background colors:
```vue
// Layout.vue
<main class="flex-1 bg-zinc-950 overflow-auto"> <!-- Was bg-zinc-900 -->
```

## Prevention
- Always add explicit Tailwind classes to Craft UI components
- Use CSS grid instead of Table component for complex tables
- Maintain a branding guidelines document with component patterns
- Test UI changes with both dev server and production build
- Remember to publish assets: `php artisan vendor:publish --tag=orbit-assets --force`

## Related
- `/home/nckrtl/workspaces/orbit/orbit-core/docs/branding-guidelines.md`
- Craft UI documentation limitations
- Dark theme color palette standards