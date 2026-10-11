#!/usr/bin/env bash
# Pulls the latest code on the VPS and applies it. Run as the deploy user:
#   bash /var/www/rental/server/deploy/deploy.sh [branch]
# GitHub Actions (.github/workflows/deploy.yml) runs this over SSH after
# every push to main. Safe to run again: migrations only apply new files.
set -euo pipefail

BRANCH="${1:-main}"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_DIR="$REPO_DIR/server"
PHP_BIN="${PHP_BIN:-php}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"

cd "$REPO_DIR"
echo "==> Updating $REPO_DIR to origin/$BRANCH"
git fetch --prune origin "$BRANCH"
# The server never edits tracked files; uploads, env.php, logs and
# sessions are untracked (.gitignore), so a hard reset leaves them alone.
git reset --hard "origin/$BRANCH"

cd "$APP_DIR"
if [ ! -f config/env.php ]; then
    echo "config/env.php is missing. Create it first (docs/DEPLOY-HOSTINGER-VPS.md, step 6)." >&2
    exit 1
fi

echo "==> Database migrations"
"$PHP_BIN" bin/migrate.php

echo "==> Minified CSS/JS"
"$PHP_BIN" bin/build-assets.php

echo "==> Reloading PHP-FPM (clears OPcache)"
sudo -n systemctl reload "$FPM_SERVICE"

echo "==> Readiness check"
"$PHP_BIN" bin/check.php || echo "check.php reported items to fix (see above)."

echo "Deployed $(git -C "$REPO_DIR" rev-parse --short HEAD)."
