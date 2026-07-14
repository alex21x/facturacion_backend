# Railway Deployment

## Backend service

Build command:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan optimize:clear
```

Start command:

```bash
php artisan serve --host=0.0.0.0 --port=$PORT
```

Required variables:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=
APP_URL=https://<backend-domain>
FRONTEND_URL=https://<frontend-domain>
FRONTEND_APP_URL=https://<frontend-domain>
FRONTEND_ADMIN_URL=https://<admin-domain>
DB_CONNECTION=pgsql
DATABASE_URL=<railway-postgres-url>
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
LOG_CHANNEL=stack
COMPANY_SUBSCRIPTION_ALERT_FREQUENCY=WEEKLY
COMPANY_SUBSCRIPTION_ALERT_TIME=08:00
COMPANY_SUBSCRIPTION_WEEKLY_DIGEST_DAY=1
COMPANY_SUBSCRIPTION_MONTHLY_DIGEST_DAY=1
```

Notes:

- Use the same Railway PostgreSQL service already loaded with data.
- Keep `APP_KEY` set in Railway before the first boot.
- The Railway image already runs `php artisan schedule:run` in a background loop inside `Dockerfile.railway`; keep `RUN_LARAVEL_SCHEDULER=true` to preserve automatic alerts.
- If the admin is not deployed yet, `FRONTEND_ADMIN_URL` can temporarily match the app URL or be left empty.