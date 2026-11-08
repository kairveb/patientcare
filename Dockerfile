FROM node:22-alpine AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.3-apache AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libxml2-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install bcmath gd intl opcache pdo_mysql zip \
    && for extension in bcmath curl dom gd intl mbstring opcache pdo_mysql xml zip; do \
        php -m | grep -iqE "^(Zend )?${extension}$" || { echo "Missing required PHP extension: ${extension}" >&2; exit 1; }; \
    done \
    && rm -rf /var/lib/apt/lists/* \
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
