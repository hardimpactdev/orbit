# Orbit Branding Guidelines

## Color Palette

### Backgrounds
- **Primary background**: `bg-zinc-950` - Used for main content, sidebar, top bar
- **Card background**: `bg-zinc-900/50` - Semi-transparent for cards on top of primary
- **Table header**: `bg-zinc-800/30` - Subtle differentiation for headers
- **Hover states**: `bg-zinc-800/20` or `bg-zinc-800/40` - Subtle hover feedback

### Text Colors
- **Primary text**: `text-zinc-100` - Headings, important content
- **Secondary text**: `text-zinc-300` - Body text, descriptions
- **Muted text**: `text-zinc-400` or `text-zinc-500` - Labels, placeholders
- **Disabled text**: `text-zinc-600` - Disabled states

### Accent Colors
- **Primary accent**: `lime-400` / `lime-500` - Status indicators, primary buttons, success states
- **Primary accent bg**: `bg-lime-500/15` - Icon backgrounds, badges
- **Error**: `text-red-400` / `bg-red-400` - Error states, destructive actions
- **Warning**: `text-amber-400` / `bg-amber-400` - Warning states
- **Info**: `text-blue-400` / `bg-blue-500/15` - Informational, worktrees

### Borders
- **Primary border**: `border-zinc-800` - Card borders, dividers
- **Subtle border**: `border-zinc-800/50` - Lighter dividers, sidebar borders
- **Input border**: `border-zinc-700` - Form inputs, buttons

## Typography

### Headings
```html
<!-- Page title -->
<h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Title</h1>

<!-- Card/section title -->
<h2 class="text-sm font-medium text-zinc-100">Section</h2>
```

### Labels
```html
<!-- Table headers, stat labels -->
<span class="text-xs text-zinc-500 uppercase tracking-wide">Label</span>

<!-- Form labels -->
<label class="text-xs text-zinc-500">Label</label>
```

### Monospace
Use `font-mono` for:
- File paths
- Code/commands
- TLD badges
- Version numbers
- Service IDs

## Components

### Cards
```html
<div class="rounded-lg border border-zinc-800 bg-zinc-900/50">
    <!-- Card header -->
    <div class="flex items-center justify-between p-4 border-b border-zinc-800">
        <h2 class="text-sm font-medium text-zinc-100">Title</h2>
        <!-- Actions -->
    </div>
    <!-- Card content -->
    <div class="p-4">
        <!-- Content -->
    </div>
</div>
```

### Stat Cards
```html
<div class="flex flex-col gap-1 p-4 rounded-lg bg-zinc-800/30 border border-zinc-800">
    <span class="text-xs text-zinc-500 uppercase tracking-wide">Label</span>
    <span class="text-2xl font-semibold text-zinc-100 tabular-nums">Value</span>
    <span class="text-xs text-zinc-500">Subtext</span>
</div>
```

### Status Indicators
```html
<!-- Animated ping for connected state -->
<span class="relative flex h-2.5 w-2.5">
    <span class="absolute inline-flex h-full w-full rounded-full bg-lime-400 opacity-75 animate-ping" />
    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-lime-400" />
</span>

<!-- Static dot -->
<span class="h-2 w-2 rounded-full bg-lime-400" />  <!-- running -->
<span class="h-2 w-2 rounded-full bg-zinc-600" />  <!-- stopped -->
```

### Status Pills
```html
<!-- Running -->
<span class="px-2.5 py-1 text-xs font-medium rounded-full bg-lime-500/15 text-lime-400 ring-1 ring-inset ring-lime-500/20">
    Running
</span>

<!-- Stopped -->
<span class="px-2.5 py-1 text-xs font-medium rounded-full bg-zinc-800 text-zinc-400 ring-1 ring-inset ring-zinc-700">
    Stopped
</span>
```

### Icon Containers
```html
<!-- Lime accent (default, success) -->
<div class="flex h-8 w-8 items-center justify-center rounded-md bg-lime-500/15">
    <Icon class="w-4 h-4 text-lime-400" />
</div>

<!-- Blue accent (info, worktrees) -->
<div class="flex h-8 w-8 items-center justify-center rounded-md bg-blue-500/15">
    <Icon class="w-4 h-4 text-blue-400" />
</div>

<!-- Larger variant -->
<div class="flex h-10 w-10 items-center justify-center rounded-lg bg-lime-500/15">
    <Icon class="h-5 w-5 text-lime-400" />
</div>
```

### Buttons

#### Primary (lime)
```html
<Button class="bg-lime-500 hover:bg-lime-600 text-zinc-950">
    Action
</Button>
```

#### Outline
```html
<Button variant="outline" class="bg-transparent border-zinc-700 text-zinc-300 hover:bg-zinc-800">
    Action
</Button>
```

#### Ghost
```html
<Button variant="ghost" class="text-zinc-400 hover:text-zinc-100">
    Action
</Button>
```

#### Small ghost with icon
```html
<Button variant="ghost" size="sm" class="h-7 px-2 text-xs text-zinc-400 hover:text-zinc-100">
    <Icon class="h-3.5 w-3.5 mr-1" />
    Label
</Button>
```

### Tables (Grid-based)

Prefer CSS grid over `<Table>` component for better control:

```html
<!-- Container -->
<div class="rounded-lg border border-zinc-800 bg-zinc-900/50">
    <!-- Header -->
    <div class="grid grid-cols-[1fr_100px_1fr_140px] gap-4 px-4 py-3 bg-zinc-800/30 border-b border-zinc-800 text-xs font-medium text-zinc-500 uppercase tracking-wide">
        <div>Column 1</div>
        <div>Column 2</div>
        <div>Column 3</div>
        <div class="text-right">Actions</div>
    </div>
    
    <!-- Rows -->
    <div class="grid grid-cols-[1fr_100px_1fr_140px] gap-4 px-4 py-3 items-center border-b border-zinc-800/50 hover:bg-zinc-800/20 transition-colors group">
        <div>Cell 1</div>
        <div>Cell 2</div>
        <div class="text-zinc-500 text-sm font-mono truncate">Cell 3</div>
        <div class="flex items-center justify-end gap-1 opacity-40 group-hover:opacity-100 transition-opacity">
            <!-- Action buttons -->
        </div>
    </div>
</div>
```

### Hover Actions Pattern
Actions that appear on hover:
```html
<div class="group">
    <!-- Row content -->
    <div class="opacity-40 group-hover:opacity-100 transition-opacity">
        <!-- Actions -->
    </div>
</div>
```

## Craft UI Gotchas

### Button Variants
Craft UI Button doesn't apply all styling automatically. Always add explicit classes:

```html
<!-- BAD: relies on variant alone -->
<Button variant="outline">Click</Button>

<!-- GOOD: explicit styling -->
<Button variant="outline" class="bg-transparent border-zinc-700 text-zinc-300 hover:bg-zinc-800">
    Click
</Button>
```

### Select Component
SelectTrigger needs explicit sizing and colors:

```html
<Select v-model="value">
    <SelectTrigger class="h-7 w-[80px] text-xs bg-zinc-800/50 border-zinc-700 text-zinc-100" size="sm">
        <SelectValue />
    </SelectTrigger>
    <SelectContent>
        <SelectItem value="option">Option</SelectItem>
    </SelectContent>
</Select>
```

### Table Component
The built-in Table component has limited styling control. Prefer CSS grid for:
- Custom column widths
- Hover action patterns
- Nested/expandable rows

### Badge Component
Works well but may need color overrides:

```html
<Badge variant="secondary" class="text-xs">Label</Badge>
```

### Input Component
Add explicit background and border:

```html
<Input class="bg-zinc-800/50 border-zinc-700 text-zinc-100" />
```

## Layout

### Main Content Container
```html
<main class="flex-1 bg-zinc-950 overflow-auto">
    <div class="p-6 lg:p-8 max-w-6xl mx-auto">
        <!-- Content -->
    </div>
</main>
```

### Page Header
```html
<header class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-8">
    <div class="flex items-center gap-3">
        <h1 class="text-2xl font-semibold tracking-tight text-zinc-100">Title</h1>
        <span class="px-2 py-0.5 text-xs font-mono bg-zinc-800 text-zinc-400 rounded-full">.tld</span>
    </div>
    <!-- Actions -->
</header>
```

### Card Grid
```html
<!-- Stats grid -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <!-- Stat cards -->
</div>

<!-- Two-column layout -->
<div class="grid lg:grid-cols-2 gap-6">
    <!-- Cards -->
</div>
```

## Icons

Use `lucide-vue-next` icons consistently:
- Size in nav/buttons: `w-4 h-4`
- Size in icon containers: `w-4 h-4` (small) or `h-5 w-5` (large)
- Size in empty states: `w-16 h-16`

Common icons:
- `HardDrive` - Local environment
- `Server` - Remote environment
- `Lock` / `LockOpen` - HTTPS status
- `ExternalLink` - Open in browser
- `Code` - Open in editor
- `RefreshCw` - Restart/refresh
- `Plus` - Add/create
- `ChevronRight` - Expand/navigate
- `Check` - Success/verified
- `AlertTriangle` - Warning
- `Loader2` - Loading (with `animate-spin`)
