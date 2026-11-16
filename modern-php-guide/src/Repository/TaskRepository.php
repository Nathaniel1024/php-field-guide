<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\Task;
use App\Domain\TaskStatus;
use DateTimeImmutable;
use PDO;

final class TaskRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<Task> */
    public function all(): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks ORDER BY id DESC');
        $stmt->execute();

        $tasks = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $tasks[] = $this->map($row);
        }

        return $tasks;
    }

    public function create(string $title, string $description, string $category, int $quantity, TaskStatus $status, int $priority, ?string $dueDate): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (title, description, category, quantity, status, due_date, priority)
             VALUES (:title, :description, :category, :quantity, :status, :due_date, :priority)'
        );

        $stmt->execute([
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'quantity' => $quantity,
            'status' => $status->value,
            'due_date' => $dueDate,
            'priority' => $priority,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): Task
    {
        return new Task(
            id: (int) $row['id'],
            title: (string) $row['title'],
            description: (string) $row['description'],
            category: (string) $row['category'],
            quantity: (int) $row['quantity'],
            status: TaskStatus::from((string) $row['status']),
            priority: (int) $row['priority'],
            dueDate: isset($row['due_date']) && $row['due_date'] !== null ? new DateTimeImmutable((string) $row['due_date']) : null,
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
