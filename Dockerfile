FROM node:20-bookworm AS frontend-build
WORKDIR /src/frontend
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
ARG VITE_API_URL=
ENV VITE_API_URL=$VITE_API_URL
RUN npm run build

FROM composer:2 AS php-dependencies
WORKDIR /src/backend
COPY backend/composer.json backend/composer.lock ./
# The runtime image installs GD; the Composer image does not, so ignore only
# that platform check while resolving dependencies in this build stage.
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --ignore-platform-req=ext-gd

FROM php:8.3-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx supervisor default-mysql-client libicu-dev libzip-dev libpng-dev \
    libonig-dev libxml2-dev libcurl4-openssl-dev unzip \
  && docker-php-ext-install -j"$(nproc)" bcmath curl exif gd intl mbstring mysqli pdo_mysql zip \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY backend/ ./backend/
COPY --from=php-dependencies /src/backend/vendor ./backend/vendor
COPY --from=frontend-build /src/frontend/dist ./frontend/dist
COPY deploy/nginx.conf /etc/nginx/sites-available/default
COPY deploy/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY deploy/start.sh /usr/local/bin/start-careerconnect
RUN chmod +x /usr/local/bin/start-careerconnect \
  && mkdir -p backend/storage/framework/{cache,sessions,views} backend/storage/logs \
  && chown -R www-data:www-data backend/storage backend/bootstrap/cache

ENV PORT=10000
EXPOSE 10000
CMD ["/usr/local/bin/start-careerconnect"]
