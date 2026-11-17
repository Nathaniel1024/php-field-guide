# 13) Launch Readiness & Deployment Checklist

This module is a final pre-launch runbook for the handbook project.

---

## 1) Data model consistency checks

Before launch, confirm app + API use normalized categories:

- `schema.sql` has `categories(id, name)`.
- `tasks` stores `category_id` (not a raw category string).
- UI listing and filters use joins:
  - `LEFT JOIN categories ON tasks.category_id = categories.id`
- API payloads use `category_id` and validate it exists.

Quick verification query:

```sql
SELECT t.id, t.title, t.category_id, c.name AS category_name
FROM tasks t
LEFT JOIN categories c ON c.id = t.category_id
ORDER BY t.id DESC;
```

---

## 2) Local boot modes

### Mode A: Public web root (recommended)

```bash
cd modern-php-guide
php -r "new PDO('sqlite:database.sqlite');"
php -r "\$pdo=new PDO('sqlite:database.sqlite');\$pdo->exec(file_get_contents('schema.sql')); echo 'Schema loaded'.PHP_EOL;"
php -S localhost:8000 -t public
```

Open:
- `http://localhost:8000/index.php`
- `http://localhost:8000/api_demo.php`
- `http://localhost:8000/api/tasks.php?status=pending&category_id=2`

### Mode B: Repository root web server (legacy)

```bash
php -S localhost:8000
```

Open:
- `http://localhost:8000/public/index.php`
- `http://localhost:8000/public/api_demo.php`
- `http://localhost:8000/api/tasks.php?status=pending&category_id=2`

---

## 3) Docker Compose (optional)

Create `docker-compose.yml` in project root:

```yaml
services:
  app:
    image: php:8.2-cli
    working_dir: /app
    volumes:
      - ./:/app
    ports:
      - "8000:8000"
    command: >
      sh -lc "php -r 'new PDO(\"sqlite:database.sqlite\");'
      && php -r '\$pdo=new PDO(\"sqlite:database.sqlite\");\$pdo->exec(file_get_contents(\"schema.sql\")); echo \"Schema loaded\\n\";'
      && php -S 0.0.0.0:8000 -t public"
```

Run:

```bash
docker compose up --build
```

---

## 4) Production readiness checklist

- Disable browser error output (`display_errors=Off`), keep server logging enabled.
- Keep `.env` and credentials out of git.
- Verify CSRF tokens on state-changing forms.
- Verify API validates input and returns stable JSON errors.
- Confirm daily logs are written under `storage/logs/`.
- Confirm backups for SQLite/MySQL and uploaded files.

---

## 5) Final smoke tests

```bash
# UI page loads
curl -i http://localhost:8000/index.php

# API list works with normalized category_id
curl -i "http://localhost:8000/api/tasks.php?status=pending&category_id=2"

# API create uses category_id
curl -i -X POST http://localhost:8000/api/tasks.php \
  -H "Content-Type: application/json" \
  -d '{"title":"Launch check","description":"Verify production readiness","category_id":2,"quantity":1,"status":"pending","due_date":"2026-12-01","priority":3}'
```
