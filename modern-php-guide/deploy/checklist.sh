#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

ok() { printf '[OK] %s\n' "$1"; }
warn() { printf '[WARN] %s\n' "$1"; }
fail() { printf '[FAIL] %s\n' "$1"; }

echo "== Modern PHP production preflight =="
echo "Project root: $ROOT_DIR"

# 1) PHP version
if command -v php >/dev/null 2>&1; then
  php_ver="$(php -r 'echo PHP_VERSION;')"
  php_id="$(php -r 'echo PHP_VERSION_ID;')"
  if [[ "$php_id" -ge 80200 ]]; then
    ok "PHP version is $php_ver (>=8.2)"
  else
    fail "PHP version is $php_ver (<8.2)"
  fi
else
  fail "php command not found"
  exit 1
fi

# 2) Required extensions
for ext in pdo curl mbstring fileinfo; do
  if php -m | grep -qiE "^${ext}$"; then
    ok "Extension loaded: $ext"
  else
    fail "Missing extension: $ext"
  fi
done

# 3) Recommended php.ini values
check_ini() {
  local key="$1"
  local expected="$2"
  local current
  current="$(php -r "echo ini_get('${key}');")"
  if [[ "$current" == "$expected" ]]; then
    ok "php.ini ${key}=${current}"
  else
    warn "php.ini ${key}=${current:-<empty>} (recommended: ${expected})"
  fi
}

check_ini "display_errors" "0"
check_ini "log_errors" "1"
check_ini "expose_php" "0"
check_ini "session.cookie_secure" "1"
check_ini "session.use_strict_mode" "1"

# 4) Permissions sanity check (repo-local sample)
if [[ -d "public" ]]; then
  perms="$(stat -c '%a' public 2>/dev/null || true)"
  if [[ "$perms" == "755" ]]; then
    ok "public/ directory mode is 755"
  else
    warn "public/ directory mode is ${perms:-unknown} (recommended: 755)"
  fi
fi

if [[ -f "README.md" ]]; then
  perms_file="$(stat -c '%a' README.md 2>/dev/null || true)"
  if [[ "$perms_file" == "644" ]]; then
    ok "README.md mode is 644"
  else
    warn "README.md mode is ${perms_file:-unknown} (recommended: 644 for normal files)"
  fi
fi

# 5) Sensitive file exposure hints
if [[ -f ".env" ]]; then
  warn ".env exists in project root. Ensure web root points to /public and block dotfiles at server level."
else
  ok "No .env file in project root (or managed elsewhere)"
fi

if [[ -d "config" ]]; then
  warn "config/ exists. Ensure it is not directly web-accessible in Nginx/Apache."
fi

# 6) Health endpoint presence
if [[ -f "public/health.php" ]]; then
  ok "Health endpoint found: public/health.php"
else
  fail "Missing health endpoint: public/health.php"
fi

# 7) Backup command reference
echo
echo "Backup one-liner (manual run):"
echo "mysqldump --single-transaction --quick --lock-tables=false -u backup_user -p'*****' my_database | gzip > /var/backups/my_database_\$(date +%F_%H-%M-%S).sql.gz"

echo
echo "Checklist complete. Review WARN/FAIL items before go-live."
