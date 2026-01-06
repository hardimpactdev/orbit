---
name: release-version
description: Release a new version of the project. Use when the user wants to release, tag, or publish a new version.
allowed-tools: Bash(git:*), Bash(gh:*)
---

# Release New Version

## Instructions

When releasing a new version, follow these steps:

### 1. Check Current State

```bash
# Check for uncommitted changes
git status

# Get current version tags
git tag --sort=-version:refname | head -5

# View recent commits since last tag
git log $(git describe --tags --abbrev=0)..HEAD --oneline
```

### 2. Determine Version Number

Follow semantic versioning (MAJOR.MINOR.PATCH):
- **MAJOR**: Breaking changes
- **MINOR**: New features, backwards compatible
- **PATCH**: Bug fixes, backwards compatible

### 3. Commit Changes (if needed)

If there are uncommitted changes, commit them first:

```bash
git add -A
git commit -m "Your commit message"
```

### 4. Create and Push Tag

```bash
# Create annotated tag
git tag -a vX.Y.Z -m "vX.Y.Z - Brief description"

# Push commits and tags
git push && git push --tags
```

### 5. Create GitHub Release

Always use the gh CLI to create the release:

```bash
gh release create vX.Y.Z --title "vX.Y.Z" --notes "$(cat <<'EOF'
## What's New

- Feature 1
- Feature 2

## Bug Fixes

- Fix 1
EOF
)"
```

### 6. Verify Release

```bash
# Confirm release was created
gh release view vX.Y.Z
```

## Notes

- Always use `gh release create` for GitHub releases
- Include meaningful release notes summarizing changes
- Link to related issues/PRs when applicable
