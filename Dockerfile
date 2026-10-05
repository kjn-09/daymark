FROM node:22-alpine AS frontend-build

WORKDIR /workspace
COPY frontend/package.json frontend/package-lock.json ./frontend/
RUN cd frontend && npm ci
COPY frontend ./frontend
COPY shared ./shared
RUN mkdir -p backend/public/ui && cd frontend && npm run build

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libonig-dev libpq-dev libzip-dev \
    && docker-php-ext-install bcmath intl mbstring opcache pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY backend/ ./
COPY shared/ /var/www/shared/
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/laravel-public.conf \
    && a2enconf laravel-public \
    && chown -R www-data:www-data storage bootstrap/cache

COPY --from=frontend-build /workspace/backend/public/ui/ ./public/ui/
COPY docker/start.sh /usr/local/bin/daymark-start
RUN chmod +x /usr/local/bin/daymark-start

EXPOSE 10000
CMD ["/usr/local/bin/daymark-start"]
