FROM php:8.4-cli-alpine

# Install required extensions
RUN apk add --no-cache git unzip linux-headers $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pcntl \
    && apk del $PHPIZE_DEPS linux-headers

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Create minimal Laravel app structure for Reverb
RUN composer create-project laravel/laravel . --prefer-dist --no-interaction --no-scripts \
    && composer require laravel/reverb --no-interaction \
    && php artisan vendor:publish --provider="Laravel\Reverb\ReverbServiceProvider" --no-interaction \
    && touch database/database.sqlite \
    && rm -rf .git tests storage/logs/* bootstrap/cache/*

# Copy entrypoint
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/entrypoint.sh"]
CMD ["sh", "-c", "php artisan reverb:start --host=0.0.0.0 --port=${REVERB_PORT:-8080} -v"]
