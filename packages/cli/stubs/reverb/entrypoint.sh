#!/bin/sh
set -e

# Generate APP_KEY if not set
if [ -z "$APP_KEY" ]; then
    export APP_KEY=$(php artisan key:generate --show --no-ansi 2>/dev/null)
fi

# Create/update .env file with Reverb settings
cat > /app/.env << EOF
APP_NAME=OrbitReverb
APP_ENV=local
APP_KEY=${APP_KEY}
APP_DEBUG=true

# Use Redis for cache (connects to orbit-redis container)
DB_CONNECTION=sqlite
CACHE_STORE=redis
SESSION_DRIVER=array
QUEUE_CONNECTION=sync
REDIS_HOST=${REDIS_HOST:-orbit-redis}
REDIS_PORT=${REDIS_PORT:-6379}

REVERB_APP_ID=${REVERB_APP_ID:-orbit}
REVERB_APP_KEY=${REVERB_APP_KEY:-orbit-key}
REVERB_APP_SECRET=${REVERB_APP_SECRET:-orbit-secret}
REVERB_HOST=${REVERB_HOST:-0.0.0.0}
REVERB_PORT=${REVERB_PORT:-8080}
REVERB_SCHEME=http

BROADCAST_CONNECTION=reverb
EOF

# Clear config cache
php artisan config:clear 2>/dev/null || true

exec "$@"
