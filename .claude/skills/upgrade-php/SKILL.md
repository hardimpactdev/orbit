---
name: upgrade-php
description: Add support for a new PHP version to Launchpad. Use when user wants to add PHP 8.6, 8.7, etc.
allowed-tools: Bash(ssh:*), Read, Edit
---

# Upgrade PHP Version

Add support for a new PHP version to the Launchpad stack. This involves updating the CLI on the remote server and rebuilding containers.

## Prerequisites

- SSH access to the remote server (`ssh launchpad@ai`)
- The new PHP version must be available in the `dunglas/frankenphp` Docker image

## Parameters

Ask the user for the PHP version to add (e.g., `8.6`, `8.7`).

## Steps

### 1. Verify the FrankenPHP Image Exists

First, check if the Docker image exists for the requested PHP version:

```bash
ssh launchpad@ai "docker pull dunglas/frankenphp:php{VERSION} 2>&1 | head -5"
```

If the image doesn't exist, inform the user and stop.

### 2. Create the Dockerfile

Create a new Dockerfile for the PHP version in the config directory:

```bash
ssh launchpad@ai "cat > ~/.config/launchpad/php/Dockerfile.php{VERSION_NO_DOT} << 'EOF'
FROM dunglas/frankenphp:php{VERSION}

RUN install-php-extensions     redis     pdo_pgsql     pdo_mysql     pcntl     intl     exif     gd     zip     bcmath

# Install Docker CLI for launchpad status checks
RUN apt-get update && apt-get install -y docker.io && rm -rf /var/lib/apt/lists/*
EOF"
```

Replace `{VERSION}` with e.g., `8.6` and `{VERSION_NO_DOT}` with e.g., `86`.

### 3. Update PhpComposeGenerator.php

Read the current file and add the new PHP service. The file is at:
`~/projects/launchpad-cli/app/Services/PhpComposeGenerator.php`

Add a new service block following the existing pattern:

```php
  php-{VERSION_NO_DOT}:
    build:
      context: .
      dockerfile: Dockerfile.php{VERSION_NO_DOT}
    image: launchpad-php:{VERSION}
    container_name: launchpad-php-{VERSION_NO_DOT}
    ports:
      - \"{PORT}:8080\"
    volumes:
{\$volumeMounts}{\$worktreeMount}{\$vibeKanbanMount}      - ./php.ini:/usr/local/etc/php/php.ini:ro
      - ./Caddyfile:/etc/frankenphp/Caddyfile:ro
    restart: unless-stopped
    networks:
      - launchpad
```

Port numbers follow the pattern: 8083 for PHP 8.3, 8084 for PHP 8.4, 8085 for PHP 8.5, 8086 for PHP 8.6, etc.

### 4. Update CaddyfileGenerator.php

Read and update `~/projects/launchpad-cli/app/Services/CaddyfileGenerator.php`.

Find the `reloadPhp()` method and add a reload command for the new container:

```php
$result{VERSION_NO_DOT} = Process::run('docker exec launchpad-php-{VERSION_NO_DOT} frankenphp reload --config /etc/frankenphp/Caddyfile 2>/dev/null');
```

Update the return statement to include the new result.

### 5. Regenerate and Restart

Run the CLI from source to regenerate configs and restart:

```bash
ssh launchpad@ai "cd ~/projects/launchpad-cli && php launchpad restart"
```

### 6. Verify Containers

Confirm all PHP containers are running:

```bash
ssh launchpad@ai "docker ps --format '{{.Names}} {{.Status}}' --filter 'name=launchpad-php-'"
```

Wait for containers to become healthy (may take 30-60 seconds on first build).

### 7. Update Desktop App (Optional)

The desktop app dynamically detects available PHP versions from running Docker containers, so no code changes are needed. The new version will appear automatically in:
- Settings page: Default PHP version dropdown
- Projects page: Per-project PHP version dropdown

## Example

To add PHP 8.6:

1. `VERSION` = `8.6`
2. `VERSION_NO_DOT` = `86`
3. `PORT` = `8086`

## Troubleshooting

### Image Not Found
If `dunglas/frankenphp:php{VERSION}` doesn't exist, the PHP version may not be released yet. Check https://hub.docker.com/r/dunglas/frankenphp/tags for available versions.

### Container Build Fails
Check Docker build logs:
```bash
ssh launchpad@ai "cd ~/.config/launchpad/php && docker compose build php-{VERSION_NO_DOT} 2>&1"
```

### Container Not Starting
Check container logs:
```bash
ssh launchpad@ai "docker logs launchpad-php-{VERSION_NO_DOT}"
```

## Files Modified

| Location | File | Change |
|----------|------|--------|
| Remote config | `~/.config/launchpad/php/Dockerfile.php{XX}` | Created |
| Remote CLI | `~/projects/launchpad-cli/app/Services/PhpComposeGenerator.php` | Add service |
| Remote CLI | `~/projects/launchpad-cli/app/Services/CaddyfileGenerator.php` | Add reload |
