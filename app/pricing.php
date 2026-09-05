<?php

declare(strict_types=1);

function rental_pricing_rule(array $bike): ?array
{
    return match ((string) ($bike['category'] ?? '')) {
        'E-bike' => [
            'day_rate' => 30.0,
            'week_rate' => 150.0,
            'label' => 'E-bike · €30/dag · €150/week',
        ],
        'Stadsfiets' => [
            'day_rate' => 15.0,
            'week_rate' => 75.0,
            'label' => 'Stadsfiets · €15/dag · €75/week',
        ],
        default => null,
    };
}

function rental_billable_days(DateTimeImmutable $startAt, DateTimeImmutable $endAt): int
{
    if ($endAt <= $startAt) {
        return 0;
    }

    $seconds = $endAt->getTimestamp() - $startAt->getTimestamp();
    return max(1, (int) ceil($seconds / 86400));
}

function rental_price_for_days(float $dayRate, float $weekRate, int $days): float
{
    if ($days <= 0 || ($dayRate <= 0 && $weekRate <= 0)) {
        return 0.0;
    }

    $fullWeeks = intdiv($days, 7);
    $remainingDays = $days % 7;
    $remainingPrice = min($remainingDays * $dayRate, $weekRate);

    return round(($fullWeeks * $weekRate) + $remainingPrice, 2);
}

function rental_price_quote(array $bikes, DateTimeImmutable $startAt, DateTimeImmutable $endAt): array
{
    $days = rental_billable_days($startAt, $endAt);
    $items = [];
    $total = 0.0;
    $complete = $days > 0;

    foreach ($bikes as $bike) {
        $rule = rental_pricing_rule($bike);
        if ($rule === null) {
            $complete = false;
            $items[] = [
                'bike_id' => (int) ($bike['id'] ?? 0),
                'code' => (string) ($bike['code'] ?? ''),
                'name' => (string) ($bike['name'] ?? ''),
                'category' => (string) ($bike['category'] ?? ''),
                'usage_type' => (string) ($bike['usage_type'] ?? 'rental'),
                'supported' => false,
                'price' => null,
                'day_rate' => null,
                'week_rate' => null,
                'label' => 'Geen automatisch tarief',
            ];
            continue;
        }

        $price = rental_price_for_days((float) $rule['day_rate'], (float) $rule['week_rate'], $days);
        $total += $price;
        $items[] = [
            'bike_id' => (int) ($bike['id'] ?? 0),
            'code' => (string) ($bike['code'] ?? ''),
            'name' => (string) ($bike['name'] ?? ''),
            'category' => (string) ($bike['category'] ?? ''),
            'usage_type' => (string) ($bike['usage_type'] ?? 'rental'),
            'supported' => true,
            'price' => $price,
            'day_rate' => (float) $rule['day_rate'],
            'week_rate' => (float) $rule['week_rate'],
            'label' => (string) $rule['label'],
        ];
    }

    return [
        'days' => $days,
        'items' => $items,
        'total' => round($total, 2),
        'complete' => $complete,
    ];
}
