# package:linked

Lists all linked packages for an application.

## What It Does

1. Validates the app project exists at `~/projects/{app}`
2. Runs `composer linked` to get the list
3. Parses and displays linked packages with their source paths

## Arguments

| Argument | Description |
|----------|-------------|
| `app` | The app project name to check |

## Options

| Option | Description |
|--------|-------------|
| `--json` | Output result as JSON with `linked_packages` array |

## Output Format

Each linked package shows:
- Package name (vendor/package)
- Source path (where it's linked from)

## Example

```bash
# List linked packages for orbit-web
orbit package:linked orbit-web

# Output:
# Linked packages for 'orbit-web':
#   - hardimpactdev/orbit-core -> /home/user/projects/orbit-core
```

## JSON Output

```json
{
  "success": true,
  "data": {
    "app": "orbit-web",
    "linked_packages": [
      {
        "name": "hardimpactdev/orbit-core",
        "path": "/home/user/projects/orbit-core"
      }
    ]
  }
}
```

## Related Commands

- `package:link` - Create a link
- `package:unlink` - Remove a link
