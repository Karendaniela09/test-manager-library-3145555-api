FROM php:8.4-cli

ARG APP_ENVIRONMENT=production

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libpq-dev libxml2-dev \
    && docker-php-ext-install dom mbstring pdo_pgsql xml xmlwriter \
    && rm -rf /var/lib/apt/lists/*

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN if [ "$APP_ENVIRONMENT" = "development" ]; then \
        composer install --no-interaction --prefer-dist --no-scripts; \
    else \
        composer install --no-dev --no-interaction --prefer-dist --no-scripts; \
    fi
COPY . .
RUN if [ "$APP_ENVIRONMENT" = "development" ]; then \
        composer dump-autoload --optimize; \
    else \
        composer dump-autoload --no-dev --optimize; \
    fi

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
