#!/bin/sh
# Entrypoint of the production image. It checks what the instance cannot run without, and
# writes nothing unless asked to: with CAIRN_AUTO_MIGRATE=1 the web server brings the schema of
# the database up to date before it starts (README, "With Docker").
set -eu

refuse() {
	echo "cairn: $1" >&2
	exit 1
}

# The web server and the console need the secrets; a shell opened to look around does not.
case "${1:-}" in
	frankenphp | bin/console | php)
		[ -n "${APP_SECRET:-}" ] \
			|| refuse 'APP_SECRET is not set. Give the instance a random string of its own, e.g. "openssl rand -hex 32".'

		# Read on every request: a typo would answer 500 everywhere, the API and the MCP server included.
		case "${CAIRN_TWO_FACTOR-optional}" in
			optional | internal | all) ;;
			*) refuse "CAIRN_TWO_FACTOR is \"$CAIRN_TWO_FACTOR\": expected optional, internal or all." ;;
		esac

		if [ -n "${MERCURE_JWT_SECRET:-}" ]; then
			[ "${#MERCURE_JWT_SECRET}" -ge 32 ] \
				|| refuse 'MERCURE_JWT_SECRET is too short: a random string of 32 characters or more.'
		elif [ -n "${MERCURE_URL:-}" ]; then
			refuse 'MERCURE_URL is set without MERCURE_JWT_SECRET: a random string of 32 characters or more, shared with the hub.'
		else
			# No real time: nothing publishes nor subscribes, and the hub of this server gets a key
			# nobody knows.
			MERCURE_JWT_SECRET="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')"
			export MERCURE_JWT_SECRET
		fi

		# A token is signed for the public address of the hub (its audience), which the hub of
		# this server would otherwise read from each request: wrong behind a proxy that holds
		# the certificate, where the requests arrive over plain HTTP.
		case "${MERCURE_PUBLIC_URL:-}" in
			http://* | https://*)
				export MERCURE_EXTRA_DIRECTIVES="${MERCURE_EXTRA_DIRECTIVES:-resource_identifier $MERCURE_PUBLIC_URL}"
				;;
		esac
		;;
esac

# The web server alone, so that the containers of an instance never migrate at the same time:
# the worker and the console leave the schema as it is. A migration that fails stops the
# container, and the database is as it was.
if [ "${CAIRN_AUTO_MIGRATE:-}" = 1 ] && [ "${1:-}" = frankenphp ]; then
	bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

exec docker-php-entrypoint "$@"
