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
```

Notes:

- Use the same Railway PostgreSQL service already loaded with data.
- Keep `APP_KEY` set in Railway before the first boot.
- If the admin is not deployed yet, `FRONTEND_ADMIN_URL` can temporarily match the app URL or be left empty.