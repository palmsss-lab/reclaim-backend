FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libonig-dev libxml2-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" mbstring xml gd pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

RUN (getent group 1000 >/dev/null || groupadd --gid 1000 render-secrets) \
    && usermod --append --groups "$(getent group 1000 | cut -d: -f1)" www-data

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/render-entrypoint.sh /usr/local/bin/render-entrypoint

RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache storage/app/reclaim-images \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x /usr/local/bin/render-entrypoint

ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
EXPOSE 10000
CMD ["sh", "/usr/local/bin/render-entrypoint"]
