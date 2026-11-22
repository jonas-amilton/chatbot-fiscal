# Imagem do Laravel (chatbot-fiscal). Multi-stage: dependências PHP, assets Vite e runtime enxuto.

FROM composer:2 AS vendor
WORKDIR /app
COPY . .
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY app ./app
COPY routes ./routes
RUN npm run build

FROM php:8.4-cli-alpine AS runtime
RUN apk add --no-cache postgresql-dev sqlite-dev \
    && docker-php-ext-install pdo_pgsql pdo_sqlite \
    && apk del postgresql-dev sqlite-dev \
    && apk add --no-cache libpq sqlite-libs

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

USER www-data

EXPOSE 8100

CMD ["php", "artisan", "serve", "--host=127.0.0.1", "--port=8100"]
