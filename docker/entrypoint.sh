#!/bin/sh
# One image, three roles: web (nginx + php-fpm via supervisord), queue, scheduler.
set -eu

ROLE="${ROLE:-web}"
cd /var/www/html

wait_for_db() {
    echo "Waiting for the database at ${DB_HOST}:${DB_PORT:-5432}..."
    php -r '
        for ($attempt = 0; $attempt < 60; $attempt++) {
            try {
                new PDO(
                    sprintf(
                        "pgsql:host=%s;port=%d;dbname=%s",
                        getenv("DB_HOST"),
                        (int) (getenv("DB_PORT") ?: 5432),
                        getenv("DB_DATABASE"),
                    ),
                    getenv("DB_USERNAME") ?: null,
                    getenv("DB_PASSWORD") ?: null,
                    [PDO::ATTR_TIMEOUT => 2],
                );
                exit(0);
            } catch (Throwable) {
                sleep(1);
            }
        }
        fwrite(STDERR, "Database not reachable after 60 attempts.\n");
        exit(1);
    '
}

if [ -n "${DB_HOST:-}" ]; then
    wait_for_db
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

if [ ! -e public/storage ]; then
    php artisan storage:link
fi

chown -R www-data:www-data storage bootstrap/cache

case "$ROLE" in
    web)
        exec supervisord -c /etc/supervisor/conf.d/lafiel.conf
        ;;
    queue)
        exec php artisan queue:work --tries=3 --max-time=3600
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    *)
        echo "Unknown ROLE \"$ROLE\" (expected web, queue, or scheduler)." >&2
        exit 1
        ;;
esac
