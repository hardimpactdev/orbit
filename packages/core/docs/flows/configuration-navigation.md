# Configuration Navigation Flow

## Overview
All environment settings are consolidated into a single Configuration page with organized tabs.

## Navigation Path
1. **From Sidebar**: Click "Configuration" link (uses Settings2 icon)
2. **Direct URL**: Navigate to `/configuration` (redirects to `/environments/{id}/configuration`)
3. **Legacy URLs**: `/settings` redirects to `/configuration` for backwards compatibility

## Page Structure

### Tab Navigation
```
Configuration → DNS → Templates → Advanced
```

### Configuration Tab (default)
Contains core environment settings:
- **Environment Name** - Display name for the environment
- **Code Editor** - Select preferred editor (Cursor, VS Code, etc.)
- **SSH Connection** (remote environments only) - Host, user, port
- **Project Paths** - Directories where projects are located
- **TLD** - Top-level domain for local projects
- **Default PHP Version** - Version used for new projects
- **External Access** - Toggle and configure external SSH access
- **SSH Keys** (desktop mode only) - Manage SSH keys for provisioning

### DNS Tab
- **Address Mappings** - Map domains to IP addresses (e.g., *.test → 127.0.0.1)
- **DNS Servers** - Configure fallback DNS servers

### Templates Tab
- **Template Favorites** - Manage favorite project templates
- Shows usage count for each template
- Quick links to GitHub repositories

### Advanced Tab
- **Desktop Settings** (desktop mode only)
  - Desktop Notifications toggle
  - Menu Bar Icon toggle
- **Health Check** - Run diagnostics with fix capabilities
- **Danger Zone** (desktop mode only) - Delete environment

## Mode Differences

### Web Mode
- Hides SSH Keys section
- Hides Desktop Settings
- Hides Danger Zone
- All other settings remain accessible

### Desktop Mode
- Shows all sections
- SSH Keys management available
- Desktop-specific settings visible

## Save Behavior
- Each section has its own save button
- External Access auto-saves when toggled off
- Changes are applied immediately upon save