#!/bin/sh
set -e

php artisan storage:link --force || true

# MySQL on Railway can refuse connections for a few seconds after the app
# container starts. Retry migrate until the DB accepts, then refuse to serve
# an unmigrated schema if it never comes up.
max_attempts=30
attempt=1
until php artisan migrate --force; do
  if [ "$attempt" -ge "$max_attempts" ]; then
    echo "ERROR: migrate failed after ${max_attempts} attempts (~90s). Exiting."
    exit 1
  fi
  echo "MySQL not ready (attempt ${attempt}/${max_attempts}). Retrying in 3s…"
  attempt=$((attempt + 1))
  sleep 3
done

php artisan serve --host=0.0.0.0 --port="${PORT:-8000}"
