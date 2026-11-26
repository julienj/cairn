#!/bin/sh
# Starts the production image the way compose.prod.yaml tells an operator to, and checks that an
# instance comes out of it: the schema, migrated by the web server as it starts, a first
# administrator, a sign-in, a worker that stays up.
# The CI runs it before publishing (.github/workflows/docker.yaml); by hand:
#
#   docker build --target prod -t cairn:local . && docker/smoke-test.sh cairn:local
set -eu

image="${1:?usage: docker/smoke-test.sh <image>}"
port="${SMOKE_PORT:-8099}"
url="http://localhost:$port"
cd "$(dirname "$0")/.."

export CAIRN_IMAGE="$image"
export APP_SECRET=smoke-test-app-secret-smoke-test-app-secret
export MERCURE_JWT_SECRET=smoke-test-mercure-secret-of-32-characters-or-more
export POSTGRES_PASSWORD=smoke
export DEFAULT_URI="$url"
export SERVER_NAME=:80
export HTTP_PORT="$port"
export HTTPS_PORT="$((port + 1))"
export MERCURE_URL=http://web/.well-known/mercure

compose() {
	docker compose -p cairn-smoke -f compose.prod.yaml "$@"
}

fail() {
	echo "smoke test: $1" >&2
	compose logs --tail 40 web worker >&2 || true
	exit 1
}

status() {
	curl -s -o /dev/null -w '%{http_code}' "$@"
}

trap 'compose down --volumes --remove-orphans >/dev/null 2>&1' EXIT

# The web server migrates before it answers (CAIRN_AUTO_MIGRATE in compose.prod.yaml).
compose up -d --wait database web

[ "$(status "$url/healthz")" = 200 ] || fail '/healthz does not answer'

compose exec -T web bin/console doctrine:migrations:up-to-date >/dev/null \
	|| fail 'the web server did not migrate the schema'

# Email, first name, last name, no company, administrator, the password twice.
printf 'smoke@cairn.test\nSmoke\nTest\n\nadmin\nsmoke-test-password\nsmoke-test-password\n' \
	| compose exec -T web bin/console app:user:create >/dev/null \
	|| fail 'the first administrator could not be created'

[ "$(status "$url/login")" = 200 ] || fail 'the sign-in page does not answer'
script="$(curl -s "$url/login" | grep -o '/build/[^"]*\.js' | head -n 1)"
[ -n "$script" ] && [ "$(status "$url$script")" = 200 ] || fail 'the pages name no built asset'
[ "$(status "$url/widget.js")" = 200 ] || fail 'the script of the widget was not built into the image'

jar="$(mktemp)"
curl -s -c "$jar" -o /dev/null "$url/login"
signed_in="$(status -b "$jar" -c "$jar" -X POST "$url/login" -H "Origin: $url" \
	--data-urlencode 'email=smoke@cairn.test' --data-urlencode 'password=smoke-test-password' --data-urlencode '_csrf_token=csrf-token')"
home="$(status -b "$jar" "$url/")"
rm -f "$jar"
[ "$signed_in" = 302 ] && [ "$home" = 200 ] || fail "signing in answered $signed_in, then the home page $home"

# The session is in the database, not in the container.
[ "$(compose exec -T database psql -U cairn cairn -tAc 'select count(*) from sessions')" -ge 1 ] \
	|| fail 'no session was written to the database'

# A second start finds nothing to migrate, and starts all the same.
compose up -d --wait --force-recreate web || fail 'the web server does not start a second time'

compose up -d worker
sleep 10
[ "$(docker inspect -f '{{.State.Status}} {{.RestartCount}}' "$(compose ps -q worker)")" = 'running 0' ] \
	|| fail 'the worker does not stay up'

echo 'smoke test: ok'
