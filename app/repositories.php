<?php

declare(strict_types=1);

function find_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE lower(email) = lower(:email) AND active = 1 LIMIT 1');
    $stmt->execute([':email' => trim($email)]);
    return $stmt->fetch() ?: null;
}

function find_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function all_users(): array
{
    return db()->query("SELECT * FROM users ORDER BY active DESC, CASE role WHEN 'admin' THEN 0 ELSE 1 END, name, email")->fetchAll();
}

function all_bikes(bool $includeInactive = true): array
{
    $sql = 'SELECT * FROM bikes';
    if (!$includeInactive) {
        $sql .= " WHERE status != 'inactive'";
    }
    $sql .= ' ORDER BY category, name, code';
    return db()->query($sql)->fetchAll();
}

function find_bike(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM bikes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function reservation_bikes(int $reservationId): array
{
    $stmt = db()->prepare(
        'SELECT b.*, rb.daily_rate AS reserved_daily_rate
         FROM reservation_bikes rb
         JOIN bikes b ON b.id = rb.bike_id
         WHERE rb.reservation_id = :reservation_id
         ORDER BY b.category, b.name, b.code'
    );
    $stmt->execute([':reservation_id' => $reservationId]);
    return $stmt->fetchAll();
}

function reservations_for_range(DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $stmt = db()->prepare(
        "SELECT r.*, rb.bike_id, b.name AS bike_name, b.code AS bike_code,
                b.status AS bike_status, c.name AS customer_name, d.id AS document_id
         FROM reservations r
         JOIN reservation_bikes rb ON rb.reservation_id = r.id
         JOIN bikes b ON b.id = rb.bike_id
         JOIN customers c ON c.id = r.customer_id
         LEFT JOIN identity_documents d ON d.id = r.identity_document_id AND d.deleted_at IS NULL
         WHERE r.start_at < :range_end
           AND r.end_at > :range_start
           AND r.status != 'cancelled'
         ORDER BY rb.bike_id, r.start_at"
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
        'SELECT r.*,
                c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone,
                c.address AS customer_address, d.id AS document_id, d.original_name AS document_name,
                d.mime_type AS document_mime, d.size_bytes AS document_size, d.retention_until,
                d.deleted_at AS document_deleted_at,
                creator.name AS created_by_name, creator.email AS created_by_email,
                closer.name AS closed_by_name, closer.email AS closed_by_email
         FROM reservations r
         JOIN customers c ON c.id = r.customer_id
         LEFT JOIN identity_documents d ON d.id = r.identity_document_id
         LEFT JOIN users creator ON creator.id = r.created_by
         LEFT JOIN users closer ON closer.id = r.closed_by
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $reservation = $stmt->fetch();
    if (!$reservation) {
        return null;
    }

    $bikes = reservation_bikes($id);
    if (!$bikes) {
        $legacyBike = find_bike((int) $reservation['bike_id']);
        $bikes = $legacyBike ? [$legacyBike + ['reserved_daily_rate' => $legacyBike['daily_rate']]] : [];
    }

    $reservation['bikes'] = $bikes;
    $first = $bikes[0] ?? [];
    $reservation['bike_name'] = $first['name'] ?? 'Onbekende fiets';
    $reservation['bike_code'] = $first['code'] ?? '—';
    $reservation['bike_category'] = $first['category'] ?? '—';
    $reservation['bike_frame_size'] = $first['frame_size'] ?? null;
    $reservation['daily_rate'] = array_sum(array_map(
        static fn (array $bike): float => (float) ($bike['reserved_daily_rate'] ?? $bike['daily_rate'] ?? 0),
        $bikes
    ));
    $reservation['bike_summary'] = implode(', ', array_map(
        static fn (array $bike): string => (string) $bike['code'] . ' — ' . (string) $bike['name'],
        $bikes
    ));

    return $reservation;
}

function reservation_conflicts(int $bikeId, string $startAt, string $endAt, ?int $excludeId = null): bool
{
    $sql = "SELECT 1
            FROM reservation_bikes rb
            JOIN reservations r ON r.id = rb.reservation_id
            WHERE rb.bike_id = :bike_id
              AND r.status NOT IN ('cancelled', 'returned')
              AND r.start_at < :end_at
              AND r.end_at > :start_at";
    $params = [
        ':bike_id' => $bikeId,
        ':start_at' => $startAt,
        ':end_at' => $endAt,
    ];
    if ($excludeId !== null) {
        $sql .= ' AND r.id != :exclude_id';
        $params[':exclude_id'] = $excludeId;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

function bike_availability(string $startAt, string $endAt, ?int $excludeReservationId = null): array
{
    $result = [];
    foreach (all_bikes(true) as $bike) {
        $available = (string) $bike['status'] === 'active';
        $reason = match ((string) $bike['status']) {
            'maintenance' => 'In onderhoud',
            'inactive' => 'Inactief',
            default => null,
        };

        if ($available && reservation_conflicts((int) $bike['id'], $startAt, $endAt, $excludeReservationId)) {
            $available = false;
            $reason = 'Al gereserveerd in deze periode';
        }

        $result[(int) $bike['id']] = [
            'available' => $available,
            'reason' => $reason,
            'status' => (string) $bike['status'],
        ];
    }
    return $result;
}

function reservation_payments(int $reservationId): array
{
    $stmt = db()->prepare(
        'SELECT p.*, u.name AS recorded_by_name, u.email AS recorded_by_email
         FROM payment_logs p
         LEFT JOIN users u ON u.id = p.recorded_by
         WHERE p.reservation_id = :reservation_id
         ORDER BY p.paid_at DESC, p.id DESC'
    );
    $stmt->execute([':reservation_id' => $reservationId]);
    return $stmt->fetchAll();
}

function reservation_payment_summary(int $reservationId, float $totalPrice): array
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM payment_logs WHERE reservation_id = :reservation_id');
    $stmt->execute([':reservation_id' => $reservationId]);
    $paid = round((float) $stmt->fetchColumn(), 2);
    $outstanding = max(0, round($totalPrice - $paid, 2));

    return [
        'paid' => $paid,
        'outstanding' => $outstanding,
        'is_paid' => $totalPrice > 0 && $outstanding < 0.01,
        'is_partial' => $paid > 0 && $outstanding >= 0.01,
    ];
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
