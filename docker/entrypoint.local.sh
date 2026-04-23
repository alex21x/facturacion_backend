#!/bin/sh
set -eu

if [ ! -f .env ] && [ -f .env.example ]; then
  cp .env.example .env
fi

set_env_value() {
  key="$1"
  value="$2"

  if [ -z "$value" ]; then
    return 0
  fi

  if grep -q "^${key}=" .env; then
    sed -i "s#^${key}=.*#${key}=${value}#" .env
  else
    printf "%s=%s\n" "$key" "$value" >> .env
  fi
}

set_env_value "APP_URL" "${APP_URL:-}"
set_env_value "APP_TIMEZONE" "${APP_TIMEZONE:-}"
set_env_value "DB_TIMEZONE" "${DB_TIMEZONE:-}"
set_env_value "DB_CONNECTION" "${DB_CONNECTION:-}"
set_env_value "DB_HOST" "${DB_HOST:-}"
set_env_value "DB_PORT" "${DB_PORT:-}"
set_env_value "DB_DATABASE" "${DB_DATABASE:-}"
set_env_value "DB_USERNAME" "${DB_USERNAME:-}"
set_env_value "DB_PASSWORD" "${DB_PASSWORD:-}"
set_env_value "FRONTEND_URL" "${FRONTEND_URL:-}"
set_env_value "FRONTEND_APP_URL" "${FRONTEND_APP_URL:-}"

if [ -f .env ] && ! grep -Eq '^APP_KEY=base64:' .env; then
  php artisan key:generate --force >/dev/null
fi

# Rebuild package manifest at runtime (build uses composer --no-scripts).
php artisan package:discover --ansi >/dev/null 2>&1 || true

php artisan optimize:clear >/dev/null 2>&1 || true

php artisan storage:link >/dev/null 2>&1 || true

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"