-- Modern PHP Field Guide & Starter Kit
-- SQLite-first schema (works with minor adjustments on MySQL).

DROP TABLE IF EXISTS tasks;
DROP TABLE IF EXISTS categories;

CREATE TABLE categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(50) NOT NULL UNIQUE
);

CREATE TABLE tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    category_id INTEGER NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 1 CHECK (quantity >= 0),
    status VARCHAR(20) NOT NULL CHECK (status IN ('pending', 'in_progress', 'done')),
    due_date DATE NULL,
    priority INTEGER NOT NULL DEFAULT 3 CHECK (priority BETWEEN 1 AND 5),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories (id) ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE INDEX idx_tasks_category_id ON tasks (category_id);
CREATE INDEX idx_tasks_status ON tasks (status);
CREATE INDEX idx_tasks_due_date ON tasks (due_date);

INSERT INTO categories (name) VALUES
('office'),
('it'),
('hr'),
('warehouse'),
('training');

INSERT INTO tasks (title, description, category_id, quantity, status, due_date, priority) VALUES
('Refill Printer Paper', 'Order 10 reams for office floor 2', 1, 10, 'pending', '2026-11-20', 4),
('Weekly Backup Check', 'Verify backup integrity for production snapshots', 2, 1, 'in_progress', '2026-11-18', 5),
('Onboard New Hire Kit', 'Prepare keyboard, badge, welcome packet', 3, 1, 'pending', '2026-11-22', 3),
('Inventory: HDMI Cables', 'Count and label spare HDMI cables', 4, 25, 'done', '2026-11-10', 2),
('Security Training', 'Complete annual secure coding refresher', 5, 1, 'in_progress', '2026-11-30', 5),
('Laptop Battery Stock', 'Check low-stock threshold and reorder if needed', 4, 12, 'pending', '2026-11-25', 4),
('Update Team Wiki', 'Document deployment rollback procedure', 2, 1, 'done', '2026-11-12', 3),
('Desk Sanitizer Bottles', 'Count refill units for all meeting rooms', 1, 30, 'pending', '2026-11-28', 2);
