# 09) Architecture Diagrams (Mermaid Visual Guide)

This page provides visual maps for the core flows in this repo.

---

## 1) Full-Stack Request Lifecycle (Filters → Validation → SQL → Escaped HTML)

```mermaid
flowchart TD
    U[Browser User] --> F[public/index.php\nSearch + Category + Date Filters]
    F --> S[includes/security.php\nCSRF + input sanitization + helpers]
    S --> V[Server-side Validation\nstatus/category/date checks]
    V --> W[build_where_clause()\nAllowlist-based Dynamic SQL WHERE]
    W --> P[PDO Prepared Statement\nERRMODE_EXCEPTION + FETCH_ASSOC]
    P --> D[(SQLite/MySQL tasks table)]
    D --> R[Rows fetched]
    R --> E["XSS Escape with e() / htmlspecialchars()"]
    E --> H[Render HTML table\nresponsive + mobile scroll]
    H --> U

    style S fill:#e0f2fe,stroke:#0369a1
    style W fill:#fef3c7,stroke:#92400e
    style P fill:#dcfce7,stroke:#166534
    style E fill:#fee2e2,stroke:#991b1b
```

---

## 2) Sync Request vs Async Worker Patterns

```mermaid
flowchart LR
    subgraph SYNC[Blocking Sync Request]
        A1[Client Request] --> A2[PHP-FPM Worker]
        A2 --> A3[Slow Task\nemail/pdf/webhook/API]
        A3 --> A4[HTTP Response Delayed]
    end

    subgraph ASYNC[Async Background Patterns]
        B1[Client Request] --> B2[PHP-FPM Worker]
        B2 --> B3[Persist Job in DB\nstatus=queued]
        B3 --> B4[Immediate HTTP 200/202]

        B2 --> B5["fastcgi_finish_request() flush response now"]
        B5 --> B6[Optional tail work or spawn worker]

        C1[Cron / Supervisor] --> C2[bin/worker.php]
        C2 --> C3[Claim queued jobs]
        C3 --> C4[Process heavy tasks]
        C4 --> C5[Mark done/failed + logs]
    end

    style A3 fill:#fee2e2,stroke:#991b1b
    style B3 fill:#fef3c7,stroke:#92400e
    style B4 fill:#dcfce7,stroke:#166534
    style C2 fill:#e0f2fe,stroke:#0369a1
```

---

## 3) REST API + cURL Authentication/Data Flow

```mermaid
sequenceDiagram
    participant C as cURL/Frontend Client
    participant A as api/gateway.php or api/tasks.php
    participant S as Auth Layer (Bearer token)
    participant B as Body Parser (php://input)
    participant R as Repository/PDO
    participant DB as Database

    C->>A: HTTP Request + Headers + JSON Body
    A->>S: Read Authorization: Bearer <token>
    S->>S: hash_equals(knownToken, receivedToken)

    alt Invalid token
        S-->>C: 401 Unauthorized
    else Valid token
        A->>B: file_get_contents('php://input')
        B->>A: json_decode(payload)

        alt Validation fails
            A-->>C: 422 Unprocessable Entity
        else Validation passes
            A->>R: Build/execute prepared statements
            R->>DB: Query/Insert/Update/Delete
            DB-->>R: Result

            alt Created
                A-->>C: 201 Created + JSON
            else Success
                A-->>C: 200 OK + JSON
            end
        end
    end
```

---

## Diagram usage notes

- Use these diagrams in onboarding docs and PR descriptions.
- Keep labels implementation-aware (`build_where_clause`, `php://input`, `hash_equals`, `bin/worker.php`).
- Update diagrams when flow changes (new auth layer, queue backend, or router behavior).

---

## Exporting diagrams to PNG/SVG

### Option 1: GitHub preview (quickest)
- Open this markdown file in GitHub.
- View each Mermaid diagram render.
- Use browser screenshot tooling for quick PNG export.

### Option 2: VS Code (repeatable)
- Open this file in VS Code.
- Use a Mermaid preview extension.
- Export each diagram as PNG/SVG from the extension command palette.

### Option 3: Mermaid CLI (automation-friendly)

Create `diagram.mmd` with a single Mermaid block, then run:

```bash
npx -y @mermaid-js/mermaid-cli -i diagram.mmd -o diagram.svg
npx -y @mermaid-js/mermaid-cli -i diagram.mmd -o diagram.png
```

Tips:
- Prefer SVG for docs (sharp, scalable, diff-friendly).
- Prefer PNG for slide decks and chat attachments.
- Keep source Mermaid in git, export artifacts only when needed.
