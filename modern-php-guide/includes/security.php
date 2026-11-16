<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(?string $token): bool
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        return false;
    }

    if ($token === null) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $stored = (string) $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);

    return $stored;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['old'][$key] ?? $default;
}

function with_old(array $data): void
{
    $_SESSION['old'] = $data;
}

function clear_old(): void
{
    unset($_SESSION['old']);
}

/**
 * Safe SQL WHERE builder.
 *
 * @param array<string, mixed> $filters
 * @param array<string, string> $allowedColumns filter key => column name
 * @return array{sql:string, params:array<string, mixed>}
 */
function build_where_clause(array $filters, array $allowedColumns): array
{
    $clauses = [];
    $params = [];

    foreach ($filters as $key => $value) {
        if (!array_key_exists($key, $allowedColumns)) {
            continue;
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === '' || $value === null) {
            continue;
        }

        $column = $allowedColumns[$key];

        if ($key === 'q') {
            $clauses[] = '(title LIKE :q OR description LIKE :q)';
            $params['q'] = '%' . $value . '%';
            continue;
        }

        if ($key === 'date_from') {
            $clauses[] = sprintf('%s >= :date_from', $column);
            $params['date_from'] = $value;
            continue;
        }

        if ($key === 'date_to') {
            $clauses[] = sprintf('%s <= :date_to', $column);
            $params['date_to'] = $value;
            continue;
        }

        $clauses[] = sprintf('%s = :%s', $column, $key);
        $params[$key] = $value;
    }

    if ($clauses === []) {
        return ['sql' => '', 'params' => []];
    }

    return ['sql' => ' WHERE ' . implode(' AND ', $clauses), 'params' => $params];
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}
