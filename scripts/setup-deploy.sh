#!/usr/bin/env bash
#
# setup-deploy.sh — sekali jalankan untuk mengaktifkan auto-deploy
#   1. core.hooksPath → git hooks di scripts/git-hooks (bersifat lokal, tidak ikut ter-commit)
#   2. Cron tiap 1 menit → narik update dari GitHub (push dari mesin lain)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

chmod +x scripts/deploy.sh scripts/git-hooks/* 2>/dev/null || true

# 1) aktifkan git hooks
git config core.hooksPath scripts/git-hooks
echo "core.hooksPath  → $(git config core.hooksPath)"

# 2) pasang cron polling (idempotent)
CRON_LINE="* * * * * $ROOT/scripts/deploy.sh >> $ROOT/storage/logs/deploy.log 2>&1"
{
    crontab -l 2>/dev/null | grep -vF 'scripts/deploy.sh' || true
    echo "$CRON_LINE"
} | crontab -
echo "cron            → $(crontab -l | grep -F 'scripts/deploy.sh')"

# 3) pastikan direktori log siap
mkdir -p storage/logs

echo
echo "Selesai. Jalankan 'scripts/deploy.sh' sekali untuk deploy pertama."
