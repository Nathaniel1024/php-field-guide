<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/security.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** @return array<string, mixed> */
function json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** @param array<string, mixed> $payload */
function validate_payload(array $payload, bool $partial = false): array
{
    $errors = [];

    if (!$partial || array_key_exists('title', $payload)) {
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) {
            $errors[] = 'title is required and <= 150 chars';
        }
    }

    if (!$partial || array_key_exists('description', $payload)) {
        if (trim((string) ($payload['description'] ?? '')) === '') {
            $errors[] = 'description is required';
        }
    }

    if (!$partial || array_key_exists('category_id', $payload)) {
        $categoryId = (int) ($payload['category_id'] ?? 0);
        if ($categoryId <= 0) {
            $errors[] = 'category_id is required and must be a positive integer';
        }
    }

    if (!$partial || array_key_exists('status', $payload)) {
        $status = (string) ($payload['status'] ?? '');
        if (!in_array($status, ['pending', 'in_progress', 'done'], true)) {
            $errors[] = 'status must be pending|in_progress|done';
        }
    }

    if ((!$partial || array_key_exists('quantity', $payload)) && (int) ($payload['quantity'] ?? -1) < 0) {
        $errors[] = 'quantity cannot be negative';
    }

    if ((!$partial || array_key_exists('priority', $payload)) && ((int) ($payload['priority'] ?? 0) < 1 || (int) ($payload['priority'] ?? 0) > 5)) {
        $errors[] = 'priority must be between 1 and 5';
    }

    if ((!$partial || array_key_exists('due_date', $payload)) && ($payload['due_date'] ?? null) !== null && (string) $payload['due_date'] !== '') {
        if (DateTime::createFromFormat('Y-m-d', (string) $payload['due_date']) === false) {
            $errors[] = 'due_date must be YYYY-MM-DD';
        }
    }

    return $errors;
}

function category_exists(PDO $pdo, int $categoryId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id');
    $stmt->execute(['id' => $categoryId]);

    return (int) $stmt->fetchColumn() > 0;
}

try {
    switch ($method) {
        case 'GET':
            $filters = [
                'q' => $_GET['q'] ?? '',
                'category_id' => $_GET['category_id'] ?? '',
                'status' => $_GET['status'] ?? '',
                'date_from' => $_GET['date_from'] ?? '',
                'date_to' => $_GET['date_to'] ?? '',
            ];

            $where = build_where_clause($filters, [
                'q' => 'tasks.title',
                'category_id' => 'tasks.category_id',
                'status' => 'tasks.status',
                'date_from' => 'tasks.due_date',
                'date_to' => 'tasks.due_date',
            ]);

            $stmt = $pdo->prepare('SELECT tasks.*, categories.name AS category_name
                                   FROM tasks
                                   LEFT JOIN categories ON categories.id = tasks.category_id'
                                   . $where['sql'] . ' ORDER BY tasks.id DESC');
            $stmt->execute($where['params']);
            $rows = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode(['data' => $rows], JSON_PRETTY_PRINT);
            break;

        case 'POST':
            $payload = json_input();
            $errors = validate_payload($payload);
            if ($errors !== []) {
                http_response_code(422);
                echo json_encode(['errors' => $errors], JSON_PRETTY_PRINT);
                break;
            }

            $categoryId = (int) $payload['category_id'];
            if (!category_exists($pdo, $categoryId)) {
                http_response_code(422);
                echo json_encode(['errors' => ['category_id does not exist']], JSON_PRETTY_PRINT);
                break;
            }

            $stmt = $pdo->prepare('INSERT INTO tasks (title, description, category_id, quantity, status, due_date, priority)
                                   VALUES (:title, :description, :category_id, :quantity, :status, :due_date, :priority)');
            $stmt->execute([
                'title' => trim((string) $payload['title']),
                'description' => trim((string) $payload['description']),
                'category_id' => $categoryId,
                'quantity' => (int) ($payload['quantity'] ?? 0),
                'status' => (string) $payload['status'],
                'due_date' => ($payload['due_date'] ?? '') !== '' ? (string) $payload['due_date'] : null,
                'priority' => (int) ($payload['priority'] ?? 3),
            ]);

            http_response_code(201);
            echo json_encode(['message' => 'created', 'id' => (int) $pdo->lastInsertId()], JSON_PRETTY_PRINT);
            break;

        case 'PUT':
            $payload = json_input();
            $id = (int) ($payload['id'] ?? 0);

            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['errors' => ['id is required']], JSON_PRETTY_PRINT);
                break;
            }

            $errors = validate_payload($payload, partial: true);
            if ($errors !== []) {
                http_response_code(422);
                echo json_encode(['errors' => $errors], JSON_PRETTY_PRINT);
                break;
            }

            if (array_key_exists('category_id', $payload)) {
                $categoryId = (int) $payload['category_id'];
                if (!category_exists($pdo, $categoryId)) {
                    http_response_code(422);
                    echo json_encode(['errors' => ['category_id does not exist']], JSON_PRETTY_PRINT);
                    break;
                }
            }

            $updates = [];
            $params = ['id' => $id];

            foreach (['title', 'description', 'category_id', 'quantity', 'status', 'due_date', 'priority'] as $field) {
                if (!array_key_exists($field, $payload)) {
                    continue;
                }

                $updates[] = $field . ' = :' . $field;
                $params[$field] = match ($field) {
                    'title', 'description', 'status', 'due_date' => ($payload[$field] === '' ? null : (string) $payload[$field]),
                    'category_id',
                    'quantity', 'priority' => (int) $payload[$field],
                    default => $payload[$field],
                };
            }

            if ($updates === []) {
                http_response_code(422);
                echo json_encode(['errors' => ['No fields to update']], JSON_PRETTY_PRINT);
                break;
            }

            $updates[] = 'updated_at = CURRENT_TIMESTAMP';

            $stmt = $pdo->prepare('UPDATE tasks SET ' . implode(', ', $updates) . ' WHERE id = :id');
            $stmt->execute($params);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'not found'], JSON_PRETTY_PRINT);
                break;
            }

            http_response_code(200);
            echo json_encode(['message' => 'updated'], JSON_PRETTY_PRINT);
            break;

        case 'DELETE':
            $payload = json_input();
            $id = (int) ($payload['id'] ?? 0);

            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['errors' => ['id is required']], JSON_PRETTY_PRINT);
                break;
            }

            $stmt = $pdo->prepare('DELETE FROM tasks WHERE id = :id');
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['message' => 'not found'], JSON_PRETTY_PRINT);
                break;
            }

            http_response_code(200);
            echo json_encode(['message' => 'deleted'], JSON_PRETTY_PRINT);
            break;

        default:
            http_response_code(405);
            header('Allow: GET, POST, PUT, DELETE');
            echo json_encode(['error' => 'Method Not Allowed'], JSON_PRETTY_PRINT);
            break;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'detail' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT);
}
