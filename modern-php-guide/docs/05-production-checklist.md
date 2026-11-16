# 05) Production Checklist: Localhost → Live Linux (Nginx/Apache + PHP-FPM)

Use this as a final pre-launch pass before exposing your vanilla PHP app publicly.

## 1) `php.ini` production baseline

Set safe defaults and restart PHP-FPM after changes.

```ini
; Error handling
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php/error.log

; Information disclosure
expose_php = Off

; Session hardening
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1
session.use_only_cookies = 1
session.sid_length = 48

; Upload and request safety (example values)
upload_max_filesize = 10M
post_max_size = 12M
max_file_uploads = 10
max_execution_time = 30
memory_limit = 256M
```

Apply and verify:

```bash
sudo systemctl restart php8.2-fpm
php -i | rg -n "display_errors|log_errors|expose_php|session.cookie_secure|session.use_strict_mode"
```

---

## 2) File/folder permissions and sensitive config placement

Recommended defaults:
- Directories: `755`
- Files: `644`
- Owner: your deploy user + web server group

```bash
cd /var/www/myapp
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;
sudo chown -R deploy:www-data /var/www/myapp
```

### Protect `.env` and `config/`

Best practice:
- Keep `.env` and private config **outside** the public web root.
- Public web root should be only your `public/` directory.

If they must remain in project root, explicitly block access.

### Nginx block rules

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/myapp/public;
    index index.php;

    location ~ /\. {
        deny all;
    }

    location ~* ^/(?:config|storage|vendor|src|docs)/ {
        deny all;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

### Apache block rules (`.htaccess` or vhost)

```apache
# Block dotfiles (.env, .git, etc.)
<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# Block sensitive folders
RewriteEngine On
RewriteRule ^(config|storage|vendor|src|docs)/ - [F,L,NC]
```

---

## 3) Essential HTTP security headers (set in PHP)

Put this early in your bootstrap (before output), for example in `includes/security.php` or a dedicated `bootstrap.php`.

```php
<?php
declare(strict_types=1);

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
```

Notes:
- Tune CSP gradually; start strict and allow only what you need.
- If using external CDNs, explicitly allow those domains in CSP.
- `X-Frame-Options: DENY` prevents clickjacking in iframes.

---

## 4) Automated MySQL backup (cron + gzip)

One-liner command suitable for cron:

```bash
mysqldump --single-transaction --quick --lock-tables=false -u backup_user -p'StrongPassword' my_database | gzip > /var/backups/my_database_$(date +\%F_\%H-\%M-\%S).sql.gz
```

Example daily cron at 02:30:

```cron
30 2 * * * mysqldump --single-transaction --quick --lock-tables=false -u backup_user -p'StrongPassword' my_database | gzip > /var/backups/my_database_$(date +\%F_\%H-\%M-\%S).sql.gz
```

Recommended hardening for backups:
- Use a dedicated DB backup user with minimal privileges.
- Prefer credentials from `/root/.my.cnf` instead of inline passwords.
- Add retention cleanup (e.g., delete backups older than 14–30 days).
- Test restore regularly (a backup is only valid if it restores).

---

## 5) Final go-live quick checklist

- `APP_ENV=production` (or equivalent) and debug disabled.
- HTTPS enabled (valid TLS cert) and HTTP → HTTPS redirect.
- `public/` is the only web-exposed directory.
- All state-changing forms protected with CSRF tokens.
- DB user permissions are least-privilege.
- Logs writable by PHP-FPM and monitored.
- Backups scheduled and restore-tested.
- Health check endpoint returns expected status.

Project helpers included in this repo:
- Health endpoint: `public/health.php`
- CLI preflight script: `deploy/checklist.sh`

Run preflight locally/on server:

```bash
cd modern-php-guide
./deploy/checklist.sh
```

