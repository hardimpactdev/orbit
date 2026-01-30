---
name: release-version
description: Release a new version of the project. Use when the user wants to release, tag, or publish a new version.
allowed-tools: Bash(git:*), Bash(gh:*), Bash(ssh:*), Bash(~/.config/composer/vendor/bin/box:*), Bash(cp:*), Bash(curl:*), Bash(chmod:*)
---

# Release New Version

This skill handles releases for **orbit-cli** (the primary use case) or the desktop app.

## Orbit CLI Release

The CLI is a Laravel Zero app on the remote server. Follow these steps:

### 1. Check Current State

```bash
ssh orbit@ai "cd ~/projects/orbit-cli && git status && git tag --sort=-version:refname | head -5"
```

### 2. Commit Changes (if needed)

```bash
ssh orbit@ai "cd ~/projects/orbit-cli && git add -A && git commit -m 'Description of changes' && git push"
```

### 3. Build the Phar

Use Box directly (Laravel Zero's bundled Box has a PHP 8.5 bug):

```bash
ssh orbit@ai "cd ~/projects/orbit-cli && ~/.config/composer/vendor/bin/box compile"
```

This creates `builds/orbit.phar`.

### 4. Determine Version Number

Follow semantic versioning (MAJOR.MINOR.PATCH):

- **MAJOR**: Breaking changes
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes, backwards compatible

### 5. Create GitHub Release

```bash
ssh orbit@ai "cd ~/projects/orbit-cli && gh release create vX.Y.Z builds/orbit.phar --title 'vX.Y.Z' --notes 'Changelog summary'"
```

### 6. Update CLI on Server

```bash
ssh orbit@ai "curl -L -o ~/.local/bin/orbit https://github.com/nckrtl/orbit-cli/releases/latest/download/orbit.phar && chmod +x ~/.local/bin/orbit"
```

### 7. Verify

```bash
ssh orbit@ai "orbit --version"
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
