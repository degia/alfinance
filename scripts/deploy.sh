#!/usr/bin/env bash
#
# deploy.sh — Auto deploy untuk Alfinance (production Docker)
#
# Dipicu oleh:
#   1. Git hooks (post-commit / post-merge / post-checkout) → perubahan lokal langsung ter-deploy
#   2. Cron tiap 1 menit → narik commit terbaru dari GitHub (update dari mesin lain)
#
# Image sekarang self-contained (kode + vendor + aset Vite di-bake langsung
# oleh `docker build`, lihat Dockerfile) — jadi tidak ada lagi `npm run
# build`/`composer install`/`artisan cache` manual di host. Yang dilakukan
# skrip ini kalau ada file relevan yang berubah:
#   - git pull --ff-only (hanya jika working tree bersih)
#   - docker compose build app   (composer install + npm build terjadi di
#     dalam image)
#   - docker compose up -d app queue scheduler   (container baru otomatis
#     memuat kode baru; `config:cache`/`view:cache` dijalankan sendiri oleh
#     `docker/entrypoint.sh` saat container start)
#   - artisan migrate --force   (hanya jika ada migration baru)
#
# Deteksi perubahan pakai state git (commit terakhir yang sukses di-deploy +
# file yang belum di-commit), BUKAN mtime.
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
NEED_MIGRATE=0

LAST_HEAD="$(cat "$STAMP" 2>/dev/null || true)"
if [ -z "$LAST_HEAD" ] || ! git cat-file -e "${LAST_HEAD}^{commit}" 2>/dev/null; then
    NEED_BUILD=1
    NEED_MIGRATE=1
    log "deploy pertama → build image penuh + migrasi"
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
            app/*|bootstrap/*|config/*|database/*|routes/*|resources/*|public/*|composer.json|composer.lock|package.json|package-lock.json|vite.config.ts|tsconfig.json|components.json|.npmrc|pnpm-workspace.yaml|Dockerfile|docker/*|nginx/*|artisan)
                if [ "$NEED_BUILD" = 0 ]; then NEED_BUILD=1; log "file aplikasi berubah → build ulang image (contoh: $f)"; fi ;;
        esac
        case "$f" in
            database/migrations/*)
                if [ "$NEED_MIGRATE" = 0 ]; then NEED_MIGRATE=1; log "ada migration baru → migrate (contoh: $f)"; fi ;;
        esac
    done <<< "$CHANGED"
fi

[ "$PULLED" = 1 ] && log "ada code baru dari GitHub"

# ------------------------------------------------------------------- 5. eksekusi
run_build() {
    log "docker compose build app ..."
    docker compose build app || return 1
    log "docker compose up -d app queue scheduler ..."
    docker compose up -d app queue scheduler || return 1
    log "build & restart selesai"
}

run_migrate() {
    if [ -z "$(docker ps -q --filter "name=^${CONTAINER}$" 2>/dev/null || true)" ]; then
        log "container $CONTAINER tidak berjalan → migrasi dilewati"
        return 1
    fi
    log "artisan migrate --force"
    docker exec -w /var/www "$CONTAINER" php artisan migrate --force || return 1
    log "migrasi selesai"
}

main() {
    if [ "$NEED_BUILD" = 1 ]; then run_build || return 1; fi
    if [ "$NEED_MIGRATE" = 1 ]; then run_migrate || return 1; fi
    return 0
}

if main; then
    printf '%s\n' "$(git rev-parse HEAD)" > "$STAMP"
    touch "$STAMP"
    rm -f "$FAILED"
    log "deploy selesai (build=$NEED_BUILD migrate=$NEED_MIGRATE pull=$PULLED)"
else
    printf '%s\n' "$SIG" > "$FAILED"
    log "deploy GAGAL → lihat pesan di atas"
    exit 1
fi
