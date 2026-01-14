---
name: release-version
description: Release a new version of the project. Use when the user wants to release, tag, or publish a new version.
allowed-tools: Bash(git:*), Bash(gh:*), Bash(ssh:*), Bash(~/.config/composer/vendor/bin/box:*), Bash(cp:*), Bash(curl:*), Bash(chmod:*)
---

# Release New Version

This skill handles releases for **launchpad-cli** (the primary use case) or the desktop app.

## Launchpad CLI Release

The CLI is a Laravel Zero app on the remote server. Follow these steps:

### 1. Check Current State

```bash
ssh launchpad@ai "cd ~/projects/launchpad-cli && git status && git tag --sort=-version:refname | head -5"
```

### 2. Commit Changes (if needed)

```bash
ssh launchpad@ai "cd ~/projects/launchpad-cli && git add -A && git commit -m 'Description of changes' && git push"
```

### 3. Build the Phar

Use Box directly (Laravel Zero's bundled Box has a PHP 8.5 bug):

```bash
ssh launchpad@ai "cd ~/projects/launchpad-cli && ~/.config/composer/vendor/bin/box compile"
```

This creates `builds/launchpad.phar`.

### 4. Determine Version Number

Follow semantic versioning (MAJOR.MINOR.PATCH):
- **MAJOR**: Breaking changes
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes, backwards compatible

### 5. Create GitHub Release

```bash
ssh launchpad@ai "cd ~/projects/launchpad-cli && gh release create vX.Y.Z builds/launchpad.phar --title 'vX.Y.Z' --notes 'Changelog summary'"
```

### 6. Update CLI on Server

```bash
ssh launchpad@ai "curl -L -o ~/.local/bin/launchpad https://github.com/nckrtl/launchpad-cli/releases/latest/download/launchpad.phar && chmod +x ~/.local/bin/launchpad"
```

### 7. Verify

```bash
ssh launchpad@ai "launchpad --version"
```

---

## Desktop App Release

For releasing the desktop app itself:

### 1. Check State

```bash
git status
git tag --sort=-version:refname | head -5
```

### 2. Create Tag and Release

```bash
git tag -a vX.Y.Z -m "vX.Y.Z - Brief description"
git push && git push --tags
gh release create vX.Y.Z --title "vX.Y.Z" --notes "Release notes"
```
