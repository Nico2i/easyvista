# syntax=docker/dockerfile:1
#
# Calipso : PHP 8.4 + Apache
#
#   Développement : docker compose up            (cible « dev », sources montées)
#   Production    : docker build --target prod -t calipso .
#
FROM php:8.4-apache AS base

# Extensions PHP : intl (normalisation des textes), opcache, zip (Composer)
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" intl opcache zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache : racine web sur public/, toutes les URL vers index.php
RUN a2enmod rewrite headers \
    && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-calipso.ini
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/calipso-entrypoint

WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1

ENTRYPOINT ["calipso-entrypoint"]
CMD ["apache2-foreground"]

# ---------------------------------------------------------------------------
# Développement : les sources sont montées par compose.yaml,
# les dépendances sont installées au premier démarrage.
FROM base AS dev
ENV APP_ENV=dev
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# ---------------------------------------------------------------------------
# Production : image autonome, sans outils de développement.
FROM base AS prod
ENV APP_ENV=prod APP_DEBUG=0
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php-prod.ini /usr/local/etc/php/conf.d/zz-calipso-prod.ini

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
    && composer dump-env prod \
    && php bin/console cache:warmup \
    && chown -R www-data:www-data var
