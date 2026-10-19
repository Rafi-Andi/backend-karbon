#!/usr/bin/env bash
set -euo pipefail

# Entrypoint aman untuk app & queue.
# - Tunggu MySQL siap (maks ~60s) supaya migrate tidak balapan.
# - Hanya service "app" yang menjalankan migrate/cache warmup;
#   service "queue" cukup ikut menunggu lalu jalan.

wait_for_db() {
    if [ -z "${DB_HOST:-}" ]; then
        return 0
    fi
    echo "[entrypoint] menunggu database ${DB_HOST}:${DB_PORT:-3306} ..."
    local tries=0
    until php -r '
        $h = getenv("DB_HOST"); $p = getenv("DB_PORT") ?: "3306";
        $c = @fsockopen($h, (int) $p, $e, $s, 2);
        exit($c ? 0 : 1);
    ' 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "[entrypoint] database tidak siap setelah 60s, lanjut saja (akan error jelas)."
            break
        fi
        sleep 2
    done
}

prepare_app() {
    # Pastikan folder yang ditulis runtime ada (aman untuk volume mount).
    mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache

    # Volume storage_* bisa terlanjur dimiliki root dari run sebelumnya.
    # Samakan dulu supaya www-data bisa menulis log/sesi.
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

    if [ ! -L public/storage ]; then
        php artisan storage:link || true
    fi

    # Bagikan hasil build Vite ke volume bersama agar nginx bisa
    # melayani file statis. Hanya sekali / bila belum ada.
    if [ -d /opt/app-build ] && [ ! -f public/build/manifest.json ]; then
        mkdir -p public/build
        cp -a /opt/app-build/. public/build/ 2>/dev/null || true
        chmod -R a+rX public/build 2>/dev/null || true
    fi

    # APP_KEY kosong = fatal di produksi; beri pesan jelas.
    if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
        echo "[entrypoint] PERINGATAN: APP_KEY belum di-set. Jalankan:"
        echo "  docker compose exec app php artisan key:generate"
    fi

    php artisan migrate --force

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache || true
}

wait_for_db

case "$1" in
    "php-fpm")
        prepare_app
        # php-fpm master tetap root (perlu bind :9000), worker-nya www-data.
        # Setelah cache dibuat root, kembalikan kepemilikan ke www-data.
        chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
        ;;
    "queue")
        # Service queue: siapkan config cache lalu jalankan worker Laravel
        # sebagai www-data (bukan root) agar file yang dibuat konsisten.
        mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
        chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
        php artisan config:cache || true
        chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
        set -- su-exec www-data php artisan queue:work --sleep=3 --tries=1 --max-time=3600 --verbose
        ;;
    *)
        # Perintah custom (mis. `php artisan ...`), jangan timpa.
        ;;
esac

exec "$@"
