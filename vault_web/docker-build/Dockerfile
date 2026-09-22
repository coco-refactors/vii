# Build context is vault_web/ (see railway.json and .dockerignore).
FROM composer:2 AS builder

WORKDIR /app

COPY docker-build/composer.json ./

RUN composer install

FROM php:8.5-apache

WORKDIR /var/www/html/

COPY --from=builder /app/ .

COPY docker-build/001-vault.conf.tpl /etc/apache2/001-vault.conf.tpl

COPY docker-build/docker-php-vault.ini /usr/local/etc/php/conf.d/

COPY docker-build/config/ /usr/local/share/vault-config/

COPY docker-build/docker-entrypoint.sh /usr/local/bin/vault-entrypoint.sh

ENV TZ=America/Los_Angeles

# Adjusts LogFormat to capture real IP from the upstream proxy
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    sed -i '/^LogFormat "%v:%p %h/s/%h/%{X-Forwarded-For}i/' /etc/apache2/apache2.conf && \
    apt-get update && \
    apt-get install -y  --no-install-recommends tzdata ffmpeg libzip-dev libpng-dev && \
    rm -r /var/lib/apt/lists/* && \
    ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone && \
    a2dissite 000-default.conf && \
    a2enmod rewrite headers && \
    docker-php-ext-install pdo pdo_mysql zip gd exif && \
    chmod +x /usr/local/bin/vault-entrypoint.sh

# Application source. Kept after the dependency layers so editing it doesn't
# invalidate the Composer install.
COPY vault/ ./

# `./init` generates what .gitignore keeps out of the repo: the frontend/backend
# web entry points and the *-local.php configs. Its generated configs point at
# localhost with a per-run cookie key, so ours are installed over them.
RUN php init --env=Production --overwrite=All && \
    php -r 'echo bin2hex(random_bytes(16));' > /etc/vault-cookie-key && \
    cp /usr/local/share/vault-config/common-main.php common/config/main-local.php && \
    cp /usr/local/share/vault-config/backend-main.php backend/config/main-local.php && \
    cp /usr/local/share/vault-config/frontend-main.php frontend/config/main-local.php && \
    chown -R www-data:www-data \
        backend/runtime backend/web/assets console/runtime \
        frontend/runtime frontend/web/assets common/web/uploads

# Loads the same config chain each entry point does. An incomplete init leaves a
# *-local.php missing, which otherwise surfaces as a 500 on the first request
# rather than as a build failure.
COPY docker-build/verify-config.php /usr/local/share/vault-config/

RUN php /usr/local/share/vault-config/verify-config.php

ENTRYPOINT ["/usr/local/bin/vault-entrypoint.sh"]

CMD ["apache2-foreground"]
