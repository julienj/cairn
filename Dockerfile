#syntax=docker/dockerfile:1

# Two images from one file:
#   dev   what compose.yaml runs: the tools, and the code bind-mounted at runtime;
#   prod  what is published: the code, its dependencies and the built assets, nothing to build
#         nor to write at runtime. The same image is the web server (default command) and the
#         worker (bin/console messenger:consume async scheduler_default).

FROM node:22-bookworm-slim AS node

# --- What both images share ---------------------------------------------------------------------
FROM dunglas/frankenphp:1-php8.5-trixie AS base

SHELL ["/bin/bash", "-euxo", "pipefail", "-c"]

WORKDIR /app

# PostgreSQL, and pcntl for the worker: without it a consumer hears no signal, so stopping its
# container kills it in the middle of a message.
RUN install-php-extensions \
		apcu \
		intl \
		opcache \
		pcntl \
		pdo_pgsql \
		zip

ENV PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d"

HEALTHCHECK --start-period=60s CMD php -r 'exit(false === @file_get_contents("http://localhost:2019/metrics", context: stream_context_create(["http" => ["timeout" => 5]])) ? 1 : 0);'
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]

# --- Development --------------------------------------------------------------------------------
FROM base AS dev

# Node.js runs the Vite dev server and the production build of the assets.
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# hadolint ignore=DL3008
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends \
		file \
		git \
		postgresql-client \
		unzip
	install-php-extensions \
		@composer \
		xdebug
	mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"
	git config --system --add safe.directory /app
	rm -rf /var/lib/apt/lists/*
EOF

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV XDEBUG_MODE=off
ENV SERVER_NAME=:80

COPY --link docker/php/app.dev.ini $PHP_INI_DIR/app.conf.d/
COPY --link docker/Caddyfile /etc/frankenphp/Caddyfile

# --- Production: the dependencies and the assets, built on the machine that builds ----------------
# Neither depends on the architecture of the image, so an image for another one emulates nothing here.
FROM --platform=$BUILDPLATFORM composer:2 AS vendor

WORKDIR /app

# The platform is the one of the image, checked below by "composer check-platform-reqs".
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --prefer-dist --ignore-platform-reqs

FROM --platform=$BUILDPLATFORM node:22-bookworm-slim AS assets

WORKDIR /app

# The Symfony UX npm packages point to their copy in vendor/.
COPY package.json package-lock.json ./
COPY --from=vendor /app/vendor vendor
RUN npm ci

# Tailwind reads the classes of the templates and of the controllers.
COPY vite.config.ts ./
COPY assets assets
COPY templates templates
RUN npm run build

# --- Production: the application, put together on the architecture of the image -------------------
FROM base AS app

ENV APP_ENV=prod
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor vendor
COPY . .
COPY --from=assets /app/public/build public/build
# The script of the widget, which the sites being reviewed load at an address of its own.
COPY --from=assets /app/public/widget.js public/widget.js

# .env is compiled, so nothing reads it at runtime; real environment variables still win.
RUN <<-EOF
	composer check-platform-reqs --no-dev
	composer dump-autoload --no-dev --classmap-authoritative
	composer dump-env prod
	bin/console assets:install public
	bin/console cache:warmup
	rm -rf docker
EOF

# --- Production: the image ------------------------------------------------------------------------
FROM base AS prod

ENV APP_ENV=prod
# Behind a proxy by default: plain HTTP, any host. A domain name makes Caddy serve it over HTTPS
# with a certificate of its own (docker/Caddyfile.prod).
ENV SERVER_NAME=:8080
# The one folder to keep: the files of the instance, and the certificates of Caddy (/data/caddy).
ENV ATTACHMENTS_DIR=/data/attachments
ENV IMPORTS_DIR=/data/imports

RUN <<-EOF
	mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
	# No capability on the binary, so it starts where they are all dropped. It listens on 8080
	# by default; on 80 and 443 (a domain name as SERVER_NAME) Docker lets any user bind them.
	setcap -r /usr/local/bin/frankenphp
	mkdir -p /data/attachments /data/imports
	chown -R www-data:www-data /data /config
EOF

COPY --link docker/php/app.prod.ini $PHP_INI_DIR/app.conf.d/
COPY --link docker/Caddyfile.prod /etc/frankenphp/Caddyfile
COPY --link --chmod=755 docker/entrypoint.sh /usr/local/bin/cairn-entrypoint
COPY --from=app --chown=www-data:www-data /app /app

USER www-data
VOLUME /data
EXPOSE 8080 80 443 443/udp

ENTRYPOINT ["cairn-entrypoint"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
