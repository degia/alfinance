#!/usr/bin/env bash
#
# deploy.sh — Auto deploy untuk Alfinance (production Docker)
#
# Dipicu oleh:
#   1. Git hooks (post-commit / post-merge / post-checkout) → perubahan lokal langsung ter-deploy
#   2. Cron tiap 1 menit → narik commit terbaru dari GitHub (update dari mesin lain)
#
# Yang dilakukan:
#   - git pull --ff-only (hanya jika working tree bersih)
#   - npm ci + npm run build   → hanya jika file frontend berubah
#   - artisan optimize:clear / config:cache / view:cache → hanya jika file PHP / .env berubah
#
# Deteksi perubahan pakai state git (commit terakhir yang sukses di-deploy + file
# yang belum di-commit), BUKAN mtime — jadi file hasil build (public/build,
# resources/js/routes, bootstrap/cache, storage) tidak pernah memicu deploy berulang.
#
# Variabel environment (opsional):
#   DEPLOY_REMOTE=origin  DEPLOY_BRANCH=main  DEPLOY_CONTAINER=alfinance_app  AUTO_PUSH=0|1
#
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP_DIR" || exit 1

REMOTE="${DEPLOY_REMOTE:-origin}"
BRANCH="${DEPLOY_BRANCH:-main}"
CONTAINER="${DEPLOY_CONTAINER:-alfinance_app}"
AUTO_PUSH="${AUTO_PUSH:-0}"

STAMP="$APP_DIR/.git/deploy.stamp"      # berisi SHA commit yang terakhir SUKSES di-deploy
FAILED="$APP_DIR/.git/deploy.failed"     # penanda deploy gagal (untuk jeda retry)
LOCK="$APP_DIR/.git/deploy.lock"

log() { printf '%s | %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }

# hanya boleh satu proses deploy pada satu waktu (mencegah hook & cron bertabrakan)
exec 9>"$LOCK"
if ! flock -n 9; then
    log "deploy lain sedang berjalan → dilewati"
    exit 0
fi

# ---------------------------------------------------------------- 1. ambil code
FETCH_OK=1
if ! git fetch "$REMOTE" "$BRANCH" --quiet 2>/dev/null; then
    FETCH_OK=0
    log "fetch $REMOTE/$BRANCH gagal (offline / key?) → pakai code lokal"
fi

# ---------------------------------------------------------------- 2. pull
PULLED=0
REMOTE_SHA=""
[ "$FETCH_OK" = 1 ] && REMOTE_SHA="$(git rev-parse "$REMOTE/$BRANCH" 2>/dev/null || true)"
LOCAL_SHA="$(git rev-parse HEAD 2>/dev/null || true)"

if [ -n "$REMOTE_SHA" ] && [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
    if ! git diff --quiet 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
        log "ada perubahan file tracked yang belum di-commit → pull dilewati (commit/stash dulu)"
    elif git merge-base --is-ancestor "$LOCAL_SHA" "$REMOTE_SHA"; then
        if git merge --ff-only --quiet "$REMOTE_SHA"; then
            PULLED=1
            log "pull OK → $(git rev-parse --short HEAD) $(git log -1 --format=%s)"
        else
            log "pull gagal → cek manual"
        fi
    elif git merge-base --is-ancestor "$REMOTE_SHA" "$LOCAL_SHA"; then
        log "ada commit lokal yang belum di-push ke GitHub"
        if [ "$AUTO_PUSH" = "1" ]; then
            if git push --quiet "$REMOTE" "$BRANCH"; then
                log "auto-push OK"
            else
                log "auto-push gagal"
            fi
        fi
    else
        log "PERINGATAN: local & remote berbeda (diverged) → resolve manual"
    fi
fi

# ------------------------------------------------- 3. jeda kalau masih gagal & belum ada perubahan
CONTAINER_STATE="$(docker ps -q --filter "name=^${CONTAINER}$" 2>/dev/null || true)"
SIG="$(printf '%s|%s|%s' "$(git rev-parse HEAD)" "$(git status --porcelain | sort)" "$CONTAINER_STATE")"
if [ -f "$FAILED" ] && [ "$(cat "$FAILED" 2>/dev/null)" = "$SIG" ]; then
    log "deploy sebelumnya gagal & belum ada perubahan baru → dilewati"
    exit 0
fi

# --------------------------------------------------------------- 4. deteksi perubahan
NEED_BUILD=0
NEED_CACHE=0

LAST_HEAD="$(cat "$STAMP" 2>/dev/null || true)"
if [ -z "$LAST_HEAD" ] || ! git cat-file -e "${LAST_HEAD}^{commit}" 2>/dev/null; then
    NEED_BUILD=1
    NEED_CACHE=1
    log "deploy pertama → build aset + refresh cache penuh"
else
    # sejak deploy terakhir: commit baru + perubahan belum di-commit + file baru
    CHANGED="$({
        git diff --name-only "$LAST_HEAD" HEAD 2>/dev/null
        git diff --name-only HEAD 2>/dev/null
        git ls-files --others --exclude-standard 2>/dev/null
    } | sort -u)"

    while IFS= read -r f; do
        [ -n "$f" ] || continue
        case "$f" in
            resources/js/*|resources/css/*|package.json|package-lock.json|vite.config.ts|tsconfig.json|components.json|.npmrc|pnpm-workspace.yaml)
                if [ "$NEED_BUILD" = 0 ]; then NEED_BUILD=1; log "file frontend berubah → build aset (contoh: $f)"; fi ;;
        esac
        case "$f" in
            app/*|bootstrap/*|config/*|database/*|routes/*|resources/views/*|public/index.php|composer.json|composer.lock|artisan)
                if [ "$NEED_CACHE" = 0 ]; then NEED_CACHE=1; log "file PHP berubah → refresh cache Laravel (contoh: $f)"; fi ;;
        esac
    done <<< "$CHANGED"

    # .env tidak di-track git → dicek lewat waktu ubah
    if [ -f .env ] && [ .env -nt "$STAMP" ] && [ "$NEED_CACHE" = 0 ]; then
        NEED_CACHE=1
        log ".env berubah → refresh cache Laravel"
    fi
fi

[ "$PULLED" = 1 ] && log "ada code baru dari GitHub"

# ------------------------------------------------------------------- 5. eksekusi
run_build() {
    log "npm install & build ..."
    if [ ! -d node_modules ] || [ package-lock.json -nt node_modules/.package-lock.json ]; then
        npm ci --no-audit --no-fund || return 1
    fi
    npm run build || return 1
    log "build aset selesai"
}

run_cache() {
    if [ -z "$CONTAINER_STATE" ]; then
        log "container $CONTAINER tidak berjalan → refresh cache dilewati"
        return 1
    fi
    log "artisan optimize:clear / config:cache / view:cache"
    docker exec -w /var/www "$CONTAINER" php artisan optimize:clear || return 1
    docker exec -w /var/www "$CONTAINER" php artisan config:cache || return 1
    docker exec -w /var/www "$CONTAINER" php artisan view:cache || return 1
    log "cache Laravel di-refresh"
}

main() {
    if [ "$NEED_BUILD" = 1 ]; then run_build || return 1; fi
    if [ "$NEED_CACHE" = 1 ]; then run_cache || return 1; fi
    return 0
}

if main; then
    printf '%s\n' "$(git rev-parse HEAD)" > "$STAMP"
    touch "$STAMP"
    rm -f "$FAILED"
    log "deploy selesai (build=$NEED_BUILD cache=$NEED_CACHE pull=$PULLED)"
else
    printf '%s\n' "$SIG" > "$FAILED"
    log "deploy GAGAL → lihat pesan di atas"
    exit 1
fi
