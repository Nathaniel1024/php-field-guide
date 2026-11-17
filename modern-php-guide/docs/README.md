# Docs Index: Deep-Dive Modules

This index helps you jump directly to practical, runnable guides.

## Prerequisites
- PHP 8.2+
- SQLite available (for most examples)
- MySQL + PDO MySQL extension (for MySQL-specific import examples)
- cURL extension enabled (for API client examples)

From repo root:

```bash
cd modern-php-guide
php -r "new PDO('sqlite:database.sqlite');"
php -r "\$pdo=new PDO('sqlite:database.sqlite');\$pdo->exec(file_get_contents('schema.sql')); echo 'Schema loaded'.PHP_EOL;"
```

---

## 01) Advanced Queries and Sorting

Guide:
- `docs/01-advanced-queries-and-sorting.md`

Topics:
- Dynamic `ORDER BY` allowlists
- Dynamic `WHERE IN (...)`
- Pagination with preserved filters
- Transactions with rollback safety
- Timezone-safe date filtering with `DateTimeImmutable`

Quick run examples:

```bash
php -S localhost:8000
```

Then open examples:
- `http://localhost:8000/public/pagination_demo.php`
- `http://localhost:8000/public/sort_demo.php?sort=priority&dir=desc`

---

## 02) Files and CSV

Guide:
- `docs/02-files-and-csv.md`

Topics:
- Hardened uploads (`finfo`, `random_bytes`, traversal checks)
- Memory-safe CSV export (`php://output`)
- CSV import validation + prepared inserts + transactions

Quick run examples:

```bash
php -S localhost:8000
```

Then open:
- `http://localhost:8000/public/upload_demo.php`
- `http://localhost:8000/public/export_tasks_csv.php`
- `http://localhost:8000/public/import_tasks_csv.php`

---

## 03) API Auth and Hardening

Guide:
- `docs/03-api-auth-and-hardening.md`

Topics:
- CORS + `OPTIONS` preflight
- Bearer auth + timing-safe token checks
- cURL timeouts/errors/multipart/parallel calls
- Secure sessions + brute-force limiter

Quick run examples:

```bash
php -S localhost:8000
curl -i -X OPTIONS http://localhost:8000/api/gateway.php -H "Origin: http://localhost:3000"
curl -i http://localhost:8000/api/gateway.php -H "Origin: http://localhost:3000"
curl -i http://localhost:8000/api/gateway.php -H "Origin: http://localhost:3000" -H "Authorization: Bearer prod_token_7f0fd263e7"
```

Session demo endpoint:
- `http://localhost:8000/public/login_demo.php`

---

## 04) Modern PHP 8 OOP and Composer

Guide:
- `docs/04-modern-php8-oop-and-composer.md`

Topics:
- Constructor property promotion
- `readonly` properties
- Backed enums + `match`
- Composer PSR-4 autoloading layout

Quick run examples:

```bash
composer dump-autoload
php -S localhost:8000
```

Then open:
- `http://localhost:8000/public/oop_demo.php`

---

## 05) Production Checklist (Linux + Nginx/Apache + PHP-FPM)

Guide:
- `docs/05-production-checklist.md`

Topics:
- Production `php.ini` hardening values
- Linux permissions and web-root protection
- Security headers (`CSP`, `X-Frame-Options`, `nosniff`, `Referrer-Policy`)
- MySQL backup cron one-liner with `mysqldump | gzip`

Quick run checks:

```bash
php -i | rg -n "display_errors|log_errors|expose_php|session.cookie_secure|session.use_strict_mode"
```

---

## 06) From Procedural to MVC-lite (No Framework)

Guide:
- `docs/06-from-procedural-to-mvc.md`

Topics:
- Single front controller (`public/index.php`) with regex/match routing
- Repository pattern for all PDO queries
- Lightweight `view()` helper for template rendering

Quick focus routes in the guide:
- `GET /tasks`
- `POST /tasks`
- `GET /tasks/{id}`

---

## 07) CLI Scripts and Cron

Guide:
- `docs/07-cli-scripts-and-cron.md`

Topics:
- CLI-only safety guards (`PHP_SAPI`, exit codes, no timeout)
- Flag parsing (`$argv`, `getopt`) + interactive confirmation
- Cron overlap protection with `flock()`
- Memory-safe chunk processing + colors + progress bar

---

## 08) Sync and Async PHP

Guide:
- `docs/08-sync-and-async-php.md`

Topics:
- PHP-FPM synchronous request model (strengths vs bottlenecks)
- Parallel HTTP calls with `curl_multi_*`
- Async web app patterns: DB job queues + CLI workers, spawn + `fastcgi_finish_request()`
- Fiber primitives + when to use ReactPHP/Amp/Swoole

---

## 09) Architecture Diagrams

Guide:
- `docs/09-architecture-diagrams.md`

Topics:
- Full-stack request lifecycle (filters → validation → SQL → escaped output)
- Blocking request vs async background worker patterns
- REST API + Bearer auth + JSON parsing + status-code response flow

---

## 10) Code Review Challenges

Guide:
- `docs/10-code-review-challenges.md`

Topics:
- 7 spot-the-bug/security-flaw review exercises (Beginner → Senior)
- Collapsible GitHub answer/fix blocks
- Practical fixes for SQL injection, timing-safe auth checks, timezone boundaries, and more

---

## 11) Relational Joins and HTML

Guide:
- `docs/11-relational-joins-and-html.md`

Topics:
- Foreign key design for `users`, `categories`, `tasks` with `RESTRICT`/`CASCADE`
- `INNER JOIN` vs `LEFT JOIN` with safe column aliasing for `PDO::FETCH_ASSOC`
- Aggregations for dashboard cards and dynamic dropdown counts
- N+1 anti-patterns and single-query solutions with `GROUP_CONCAT` or PHP grouping

Quick run examples:

```bash
php -S localhost:8000
```

Then open:
- `http://localhost:8000/public/joins_demo.php?join=left`
- `http://localhost:8000/public/joins_demo.php?join=inner`
- `http://localhost:8000/public/category_totals_demo.php`
- `http://localhost:8000/public/n_plus_one_solution_group_concat.php`
- `http://localhost:8000/public/n_plus_one_solution_php_grouping.php`

---

## 12) Email and Structured Logging

Guide:
- `docs/12-email-and-structured-logging.md`

Topics:
- Why `mail()` often fails in production and when to use SMTP/API providers
- PHPMailer SMTP helper with `.env` credentials, HTML/text bodies, and attachments
- Zero-dependency HTTP email API calls (Resend/Postmark/Mailgun-style) using cURL
- PSR-3-inspired structured logger with daily rotating `storage/logs/app-YYYY-MM-DD.log`
- `audit_logs` table pattern for immutable CRUD audit trails

Quick run examples:

```bash
composer require phpmailer/phpmailer
mkdir -p storage/logs
php -r "echo 'log path: '.getcwd().'/storage/logs/app-'.gmdate('Y-m-d').'.log'.PHP_EOL;"
```

Then:
- Create `.env` from the guide and fill SMTP/API credentials.
- Run a small send script using the SMTP/API helper snippets in module 12.
- Open the daily log file in `storage/logs/` and confirm JSON log lines were written.

---

## 13) Launch Readiness and Deployment

Guide:
- `docs/13-launch-readiness-and-deployment.md`

Topics:
- Final consistency checks for normalized `categories` + `tasks.category_id`
- Local boot modes (`php -S localhost:8000 -t public` and legacy root mode)
- Optional `docker-compose.yml` template for local containerized boot
- Production readiness checklist and launch smoke tests

---

## Suggested workflow
1. Read module markdown.
2. Copy snippet into a dedicated `public/*_demo.php` file.
3. Run in browser/cURL.
4. Move proven code into your main app structure.
