FROM node:22-alpine AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.3-apache AS php-base
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p storage/framework/cache/data storage/framework/views \
    storage/framework/sessions storage/logs storage/app/private \
    storage/app/public bootstrap/cache \
    && composer install --no-dev --optimize-autoloader \
        --no-interaction --prefer-dist --no-progress

FROM php-base AS runtime
RUN apt-get update \
    && apt-get install -y --no-install-recommends supervisor curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY --from=vendor /var/www/html /var/www/html
COPY --from=assets /src/public/build /var/www/html/public/build
COPY docker/apache-vhost.conf.template /etc/apache2/sites-available/pacc.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/pacc.conf
COPY docker/entrypoint.sh /usr/local/bin/hostforge-entrypoint
RUN mkdir -p storage/framework/cache/data storage/framework/views \
    storage/framework/sessions storage/logs storage/app/private \
    storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && a2dissite 000-default

ENV PORT=8080
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD ["sh", "-c", "curl -fsS \"http://127.0.0.1:${PORT:-8080}/up\" >/dev/null"]
ENTRYPOINT ["sh", "/usr/local/bin/hostforge-entrypoint"]
