FROM serversideup/php:8.3-fpm-nginx AS production

USER root

# pdo_pgsql/pgsql for Postgres, redis for cache/queue/session drivers.
RUN install-php-extensions pgsql pdo_pgsql redis

WORKDIR /var/www/html

COPY --chown=www-data:www-data . .

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
    && rm -rf /var/www/html/.git \
    && php artisan config:clear

USER www-data

# Runs migrations on boot, then starts php-fpm + nginx via the base image's
# s6 supervisor. Isolation is off: it needs the cache_locks table to acquire
# a lock, but that table doesn't exist until the migration that creates it
# runs — a deadlock on a brand-new database. Fine to leave off at single-
# instance scale; revisit if/when this runs as multiple replicas.
ENV AUTORUN_ENABLED=true \
    AUTORUN_LARAVEL_MIGRATION=true \
    AUTORUN_LARAVEL_MIGRATION_ISOLATION=false \
    AUTORUN_LARAVEL_STORAGE_LINK=false \
    PHP_OPCACHE_ENABLE=1 \
    SSL_MODE=off

EXPOSE 8080
