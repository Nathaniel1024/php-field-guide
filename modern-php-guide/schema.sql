-- Modern PHP Field Guide & Starter Kit
-- SQLite-first schema (works with minor adjustments on MySQL).

DROP TABLE IF EXISTS tasks;

CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(50) NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
    status VARCHAR(20) NOT NULL CHECK (status IN ('pending', 'in_progress', 'done')),
    due_date DATE NULL,
    priority INTEGER NOT NULL DEFAULT 3 CHECK (priority BETWEEN 1 AND 5),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO tasks (title, description, category, quantity, status, due_date, priority) VALUES
('Refill Printer Paper', 'Order 10 reams for office floor 2', 'office', 10, 'pending', '2026-11-20', 4),
('Weekly Backup Check', 'Verify backup integrity for production snapshots', 'it', 1, 'in_progress', '2026-11-18', 5),
('Onboard New Hire Kit', 'Prepare keyboard, badge, welcome packet', 'hr', 1, 'pending', '2026-11-22', 3),
('Inventory: HDMI Cables', 'Count and label spare HDMI cables', 'warehouse', 25, 'done', '2026-11-10', 2),
('Security Training', 'Complete annual secure coding refresher', 'training', 1, 'in_progress', '2026-11-30', 5),
('Laptop Battery Stock', 'Check low-stock threshold and reorder if needed', 'warehouse', 12, 'pending', '2026-11-25', 4),
('Update Team Wiki', 'Document deployment rollback procedure', 'it', 1, 'done', '2026-11-12', 3),
('Desk Sanitizer Bottles', 'Count refill units for all meeting rooms', 'office', 30, 'pending', '2026-11-28', 2);
