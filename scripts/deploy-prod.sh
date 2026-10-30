#!/usr/bin/env bash
#
# deploy-prod.sh — Update Alfinance production (Docker) dengan satu perintah
#
# Image sekarang self-contained (kode + vendor + aset Vite di-bake langsung
# oleh `docker build`, lihat Dockerfile), jadi deploy = build ulang image +
# migrasi + restart, tidak ada lagi `composer install`/`npm run build` manual
# di host.
#
# Pemakaian:
#   ./scripts/deploy-prod.sh              # deploy normal
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

BRANCH="${DEPLOY_BRANCH:-main}"

log()  { printf '\033[1;32m[%s]\033[0m %s\n' "$(date '+%H:%M:%S')" "$*"; }
fail() { printf '\033[1;31m[GAGAL]\033[0m %s\n' "$*" >&2; exit 1; }

exec 9>"$APP_DIR/.git/deploy-prod.lock"
flock -n 9 || fail "Deploy lain sedang berjalan."

[ -f .env ] || fail ".env tidak ditemukan di $APP_DIR"

IN_MAINTENANCE=0
cleanup() {
    if [ "$IN_MAINTENANCE" = 1 ]; then
        log "Menonaktifkan maintenance mode setelah error..."
        docker exec alfinance_app php artisan up >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

# 1. Pull code
log "Mengecek perubahan lokal..."
if ! git diff --quiet || ! git diff --cached --quiet; then
    git status --short
    fail "Ada perubahan file yang belum di-commit. Commit/stash dulu, lalu jalankan ulang."
fi

OLD_SHA="$(git rev-parse --short HEAD)"
log "Menarik update dari origin/$BRANCH..."
git pull --ff-only origin "$BRANCH" || fail "git pull gagal (diverged / SSH key?)."
NEW_SHA="$(git rev-parse --short HEAD)"

if [ "$OLD_SHA" = "$NEW_SHA" ]; then
    log "Tidak ada commit baru ($NEW_SHA). Deploy tetap dilanjutkan (build ulang image)."
else
    log "Update $OLD_SHA → $NEW_SHA"
    git --no-pager log --oneline "$OLD_SHA..$NEW_SHA"
fi

# 2. Build image self-contained (composer install + wayfinder + npm build
#    semua terjadi di dalam `docker build`, lihat Dockerfile).
log "Build image (composer install + npm build di dalam image)..."
docker compose build app

# 3. Rilis
log "Maintenance mode ON (container lama)..."
docker exec alfinance_app php artisan down --retry=15 || true
IN_MAINTENANCE=1

log "Restart container dengan image baru..."
docker compose up -d app queue scheduler

# File flag maintenance ikut hilang saat container lama diganti (bukan di
# volume persisten), jadi dikunci lagi di container baru sebelum migrasi.
log "Maintenance mode ON (container baru)..."
docker exec alfinance_app php artisan down --retry=15 || true

log "Migrasi database..."
docker exec alfinance_app php artisan migrate --force

log "Maintenance mode OFF..."
docker exec alfinance_app php artisan up
IN_MAINTENANCE=0

# 4. Cek
sleep 3
docker compose ps
if docker compose ps --status restarting --services | grep -q .; then
    fail "Ada container yang restart terus. Cek: docker logs --tail 50 <nama_container>"
fi

log "Deploy selesai → $(git rev-parse --short HEAD) $(git log -1 --format=%s)"
