# Multi-stage Dockerfile for Symfony 8.1 with PHP 8.5-FPM (Alpine)
# Stage 1: Build dependencies
FROM composer:2.8 AS vendor

WORKDIR /app

# Copy composer files
COPY composer.json composer.lock symfony.lock ./

# Install dependencies without scripts
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --ignore-platform-req=ext-pgsql \
        --ignore-platform-req=ext-pdo_pgsql

# Stage 2: Runtime — PHP 8.5-FPM on Alpine
FROM php:8.5-fpm-alpine AS runtime

# Install system dependencies
# NOTE: opcache/bcmath/etc. are static (no shared object) and have no "modules/" dir,
# which makes the combined docker-php-ext-install call fail.
# So we install them in separate RUN steps (one extension per step) and skip apk del
# until everything is done, then re-enable dynamic loading properly.
RUN set -eux; \
    apk add --no-cache \
        bash \
        git \
        curl \
        libpq-dev \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        font-dejavu \
        linux-headers \
        $PHPIZE_DEPS \
    ; \
    # install separately so each has its own modules/ dir
    # Note: bcstring, mbstring, opcache are already built into php:8.5-fpm-alpine
    docker-php-ext-install pdo_pgsql ; \
    docker-php-ext-install pgsql ; \
    docker-php-ext-install intl ; \
    docker-php-ext-install zip ; \
    docker-php-ext-install pcntl ; \
    pecl install apcu ; \
    docker-php-ext-enable apcu ; \
    apk del $PHPIZE_DEPS ; \
    rm -rf /tmp/pear ; \
    # Copy DejaVu fonts into the project so the PDF builder can find them
    mkdir -p /app/var && \
    cp /usr/share/fonts/dejavu/DejaVuSans.ttf      /app/var/DejaVuSans.ttf      && \
    cp /usr/share/fonts/dejavu/DejaVuSans-Bold.ttf /app/var/DejaVuSans-Bold.ttf && \
    echo "DejaVu fonts installed"

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Configure PHP for production
RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=64M'; \
        echo 'post_max_size=64M'; \
        echo 'max_execution_time=120'; \
        echo 'date.timezone=Europe/Moscow'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=256'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'realpath_cache_size=4096K'; \
        echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/symfony.ini

WORKDIR /app

# Copy vendor from build stage
COPY --from=vendor /app/vendor ./vendor

# Copy application code
COPY . .

# Run composer scripts now that the full code is here
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && mkdir -p var/cache var/log \
    && chown -R www-data:www-data var \
    && chmod -R 775 var

EXPOSE 9000

CMD ["php-fpm"]
