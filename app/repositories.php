<?php

declare(strict_types=1);

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE lower(email) = lower(:email) AND active = 1 LIMIT 1');
    $stmt->execute([':email' => trim($email)]);
    return $stmt->fetch() ?: null;
}

function all_bikes(bool $includeInactive = true): array
{
    $sql = 'SELECT * FROM bikes';
    if (!$includeInactive) {
        $sql .= " WHERE status != 'inactive'";
    }
    $sql .= ' ORDER BY category, name';
    return db()->query($sql)->fetchAll();
}

function find_bike(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bikes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function reservations_for_range(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $stmt = db()->prepare(
        "SELECT r.*, b.name AS bike_name, b.code AS bike_code, c.name AS customer_name,
                d.id AS document_id
         FROM reservations r
         JOIN bikes b ON b.id = r.bike_id
         JOIN customers c ON c.id = r.customer_id
         LEFT JOIN identity_documents d ON d.id = r.identity_document_id AND d.deleted_at IS NULL
         WHERE r.start_at < :range_end
           AND r.end_at > :range_start
           AND r.status != 'cancelled'
         ORDER BY r.bike_id, r.start_at"
    );
    $stmt->execute([
        ':range_start' => $start->format('Y-m-d H:i:s'),
        ':range_end' => $end->format('Y-m-d H:i:s'),
    ]);
    return $stmt->fetchAll();
}

function find_reservation(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, b.name AS bike_name, b.code AS bike_code, b.category AS bike_category,
                c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                c.address AS customer_address, d.id AS document_id, d.original_name AS document_name,
                d.mime_type AS document_mime, d.size_bytes AS document_size, d.retention_until,
                d.deleted_at AS document_deleted_at
         FROM reservations r
         JOIN bikes b ON b.id = r.bike_id
         JOIN customers c ON c.id = r.customer_id
         LEFT JOIN identity_documents d ON d.id = r.identity_document_id
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function reservation_conflicts(int $bikeId, string $startAt, string $endAt, ?int $excludeId = null): bool
{
    $sql = "SELECT 1 FROM reservations
            WHERE bike_id = :bike_id
              AND status NOT IN ('cancelled', 'returned')
              AND start_at < :end_at
              AND end_at > :start_at";
    $params = [
        ':bike_id' => $bikeId,
        ':start_at' => $startAt,
        ':end_at' => $endAt,
    ];
    if ($excludeId !== null) {
        $sql .= ' AND id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function reservation_counts(): array
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $stmt = db()->prepare(
        "SELECT
            SUM(CASE WHEN date(start_at) = :today AND status IN ('reserved','confirmed') THEN 1 ELSE 0 END) AS pickups,
            SUM(CASE WHEN date(end_at) = :today AND status IN ('picked_up','confirmed') THEN 1 ELSE 0 END) AS returns,
            SUM(CASE WHEN status = 'picked_up' THEN 1 ELSE 0 END) AS active
         FROM reservations"
    );
    $stmt->execute([':today' => $today]);
    return $stmt->fetch() ?: ['pickups' => 0, 'returns' => 0, 'active' => 0];
}
