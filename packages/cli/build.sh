#!/bin/bash
set -e

echo "Installing production dependencies only..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "Building phar..."
~/.config/composer/vendor/bin/box compile

echo "Restoring dev dependencies..."
composer install --no-interaction

echo "Done! Phar size:"
ls -lh builds/orbit.phar
