# hadolint global ignore=DL3008

FROM dunglas/frankenphp:1.13.3-php8.5.12 AS php-base

# pcntl: the Messenger worker stops cleanly on SIGTERM, after the message it is handling
RUN install-php-extensions \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        uuid

COPY ./base/php.ini $PHP_INI_DIR/conf.d/app-base.ini

ENV HOME=/home/app

WORKDIR /var/www

FROM php-base AS frontend

RUN setcap CAP_NET_BIND_SERVICE=+eip /usr/local/bin/frankenphp && \
    chmod -R 777 /data /config

COPY ./frontend/etc/. /etc/
COPY ./frontend/php.ini $PHP_INI_DIR/conf.d/app-frontend.ini

CMD [ "frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]

EXPOSE 80

FROM php-base AS worker

# Remove caddy healthcheck inherited from frankenphp image
HEALTHCHECK NONE

FROM php-base AS builder

SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Default toys
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash-completion \
        ca-certificates \
        curl \
        git \
        make \
        procps \
        unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*

# Composer
COPY --from=composer/composer:2.10.0 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_MEMORY_LIMIT=-1

ADD https://raw.githubusercontent.com/symfony/symfony/refs/heads/8.1/src/Symfony/Component/Console/Resources/completion.bash /tmp/completion.bash

# Composer symfony/console version is too old, and doest not support "API version feature", so we remove it
# Hey, while we are at it, let's add some more completion
RUN sed /tmp/completion.bash \
        -e "s/{{ COMMAND_NAME }}/composer/g" \
        -e 's/"-a{{ VERSION }}"//g' \
        -e "s/{{ VERSION }}/1/g"  \
        > /etc/bash_completion.d/composer \
    && sed /tmp/completion.bash \
        -e "s/{{ COMMAND_NAME }}/console/g" \
        -e "s/{{ VERSION }}/1/g"  \
        > /etc/bash_completion.d/console

# Config
COPY ./builder/php.ini $PHP_INI_DIR/conf.d/app-builder.ini
RUN mkdir /var/log/php \
    && chmod 777 /var/log/php

# Third party tools
ENV PATH="$PATH:/var/www/tools/bin"

# frankenphp defined the two following vars. But they are located in a folder
# not owned by the user (/data, and /config). Yarn needs it, and it must be
# shared between all containers instances! So let's override(reset) them to
# point to the user home directory (default values)
ENV XDG_DATA_HOME=
ENV XDG_CONFIG_HOME=

# We can't rely on \u: the container runs as the host's raw UID, which has no
# /etc/passwd entry, so bash would print "I have no name!" instead. Show
# "builder" normally, and "root" once elevated.
RUN cat >> /etc/bash.bashrc <<'EOF'
. /etc/bash_completion

PS1='\[\e[01;33m\]$([ "$EUID" = 0 ] && echo root || echo builder) \[\e[00;32m\]\w\[\e[0m\] '
EOF

# ------------------------- Production stages -------------------------
#
# A single self-contained image, "app": FrankenPHP serves public/, the embedded Mercure hub and the
# MCP server on port 8080, in HTTP (the TLS ends in front of it). The code is baked in, the
# dependencies installed without the dev packages, and the container runs as a non-root user. The
# same image runs the migrations and the Messenger worker (`bin/console ...`).
#
# The source code comes from the additional build context "source", the repository root (the root
# .dockerignore applies), see infrastructure/docker/docker-compose.prod.yml.

FROM builder AS app-build

ENV APP_ENV=prod
ENV APP_DEBUG=0
ENV COMPOSER_ALLOW_SUPERUSER=1
# The cache warmup builds the Mercure hub (Twig needs it), which refuses an empty URL. Only for
# this stage: the image gets the real one from its environment, and dump-env leaves real
# environment variables out of .env.local.php.
ENV MERCURE_PUBLIC_URL=http://localhost/.well-known/mercure

# Dependencies first, for the layer cache. "source" is a build context, not a stage.
# hadolint ignore=DL3022
COPY --from=source composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-autoloader

# hadolint ignore=DL3022
COPY --from=source . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && composer dump-env prod \
    && bin/console importmap:install \
    && bin/console asset-map:compile \
    && bin/console cache:warmup

FROM php-base AS app

ENV APP_ENV=prod
ENV APP_DEBUG=0
# Every Host: a reverse proxy in front, and the worker through the internal network
ENV SERVER_NAME=:8080

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY ./prod/php.ini $PHP_INI_DIR/conf.d/app-prod.ini
COPY ./frontend/etc/. /etc/

# The persistent volume, created here to belong to "app": var/share (APP_SHARE_DIR: the sessions,
# the cache.app pool, and the photos of the plantings in var/share/prod/uploads, served by the
# application to the members of their garden only)
RUN groupadd -g 1000 app \
    && useradd -M -u 1000 -g 1000 -s /bin/false app \
    && chown -R app:app /data /config \
    && mkdir -p /var/www/var/share \
    && chown app:app /var/www/var/share

COPY --from=app-build --chown=app:app /var/www /var/www

# The release of the errors sent to Sentry: the short commit sha, as the tag of the image
# (.github/workflows/build-push.yml). Empty otherwise: no release. Declared last, it changes with every commit without
# invalidating the cache of the steps before.
ARG SENTRY_RELEASE=""
ENV SENTRY_RELEASE=${SENTRY_RELEASE}

USER 1000:1000

EXPOSE 8080

# PHP answers, not only Caddy (the /metrics of its admin API, inherited from the FrankenPHP image): the probe of the
# application, without session nor database (App\Controller\HealthController)
HEALTHCHECK CMD curl --fail --silent --output /dev/null http://localhost:8080/healthz || exit 1

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile", "--adapter", "caddyfile"]
