#!/usr/bin/env bash
#
# deploy-prod.sh — Update Alfinance production (Docker) dengan satu perintah
#
# Pemakaian:
#   ./scripts/deploy-prod.sh              # deploy normal
#   ./scripts/deploy-prod.sh --no-build   # lewati build frontend (jika hanya ubah PHP)
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR"

BRANCH="${DEPLOY_BRANCH:-main}"
NODE_IMAGE="${NODE_IMAGE:-node:22}"
SKIP_BUILD=0
[ "${1:-}" = "--no-build" ] && SKIP_BUILD=1

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
    log "Tidak ada commit baru ($NEW_SHA). Deploy tetap dilanjutkan."
else
    log "Update $OLD_SHA → $NEW_SHA"
    git --no-pager log --oneline "$OLD_SHA..$NEW_SHA"
fi

# 2. Image & PHP deps
if git diff --name-only "$OLD_SHA" "$NEW_SHA" | grep -q '^Dockerfile$'; then
    log "Dockerfile berubah → build ulang image..."
    docker compose build
fi

log "composer install..."
docker compose run --rm -T app composer install --no-dev --optimize-autoloader --no-interaction

# 3. Frontend
if [ "$SKIP_BUILD" = 0 ]; then
    log "Generate route Wayfinder..."
    docker compose run --rm -T app php artisan wayfinder:generate --with-form

    log "Build frontend ($NODE_IMAGE)..."
    docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp \
        -v "$APP_DIR":/app -w /app "$NODE_IMAGE" \
        sh -c "npm ci --no-audit --no-fund && npm run build"
else
    log "Build frontend dilewati (--no-build)."
fi

# 4. Rilis
log "Maintenance mode ON..."
docker exec alfinance_app php artisan down --retry=15 || true
IN_MAINTENANCE=1

log "Migrasi database..."
docker exec alfinance_app php artisan migrate --force

log "Refresh cache Laravel..."
docker exec alfinance_app php artisan optimize:clear
docker exec alfinance_app php artisan optimize

log "Permission storage..."
docker exec alfinance_app chown -R www-data:www-data storage bootstrap/cache

log "Restart container..."
docker compose up -d
docker compose restart app queue scheduler nginx

log "Maintenance mode OFF..."
docker exec alfinance_app php artisan up
IN_MAINTENANCE=0

# 5. Cek
sleep 3
docker compose ps
if docker compose ps --status restarting --services | grep -q .; then
    fail "Ada container yang restart terus. Cek: docker logs --tail 50 <nama_container>"
fi

log "Deploy selesai → $(git rev-parse --short HEAD) $(git log -1 --format=%s)"
