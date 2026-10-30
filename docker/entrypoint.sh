#!/usr/bin/env bash
#
# entrypoint.sh — disiapkan sekali setiap container start, lalu menjalankan
# proses sebenarnya (supervisord untuk service `app`, atau `php artisan ...`
# untuk `queue`/`scheduler` lewat override `command:` di docker-compose.yml).
#
# Image sudah self-contained (kode + vendor + aset baked in), jadi langkah di
# sini HANYA yang bergantung pada environment runtime (.env disuntik lewat
# `env_file`), bukan composer/npm — itu sudah selesai saat build image.
#
set -euo pipefail

cd /var/www

# Symlink storage publik. Volume `storage_app` kosong di awal akan diisi
# otomatis oleh Docker dari isi image saat volume pertama kali dibuat, jadi
# `public/storage -> ../storage/app/public` tetap valid.
php artisan storage:link --force >/dev/null 2>&1 || true

# Cache config/view memakai env runtime (bukan env saat build). Route tidak
# di-cache karena routes/web.php memakai closure (lihat scripts/deploy.sh).
php artisan config:cache
php artisan view:cache

exec "$@"
