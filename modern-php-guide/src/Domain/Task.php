<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

final class Task
{
    public function __construct(
        public readonly int $id,
        public string $title,
        public string $description,
        public string $category,
        public int $quantity,
        public TaskStatus $status,
        public int $priority,
        public ?DateTimeImmutable $dueDate,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
