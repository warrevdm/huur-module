<?php

declare(strict_types=1);

function handle_reservation_status_request(): never
{
    require_auth();

    if ((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('Methode niet toegestaan.');
    }

    verify_csrf();

    $id = (int) ($_GET['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    $allowed = ['reserved', 'confirmed', 'picked_up', 'returned', 'cancelled'];
    $reservation = find_reservation($id);

    if (!$reservation || !in_array($status, $allowed, true)) {
        flash('error', 'Ongeldige status.');
        redirect('index.php?route=planning');
    }

    $previousStatus = (string) $reservation['status'];
    $closingStatuses = ['returned', 'cancelled'];
    $isClosing = in_array($status, $closingStatuses, true);
    $wasClosed = !empty($reservation['closed_at']);
    $userId = (int) current_user()['id'];

    if ($isClosing) {
        $stmt = db()->prepare(
            'UPDATE reservations
             SET status = :status,
                 closed_by = CASE WHEN closed_at IS NULL THEN :closed_by ELSE closed_by END,
                 closed_at = CASE WHEN closed_at IS NULL THEN CURRENT_TIMESTAMP ELSE closed_at END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':closed_by' => $userId,
            ':id' => $id,
        ]);
    } else {
        $stmt = db()->prepare(
            'UPDATE reservations
             SET status = :status,
                 closed_by = NULL,
                 closed_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':id' => $id,
        ]);
    }

    audit('status_update', 'reservation', $id, [
        'from_status' => $previousStatus,
        'to_status' => $status,
        'closed_now' => $isClosing && !$wasClosed,
        'changed_by' => $userId,
    ]);

    if ($isClosing && !$wasClosed) {
        flash('success', 'Huur afgesloten en voorzien van naam-, datum- en tijdstempel.');
    } elseif (!$isClosing && $wasClosed) {
        flash('success', 'Status aangepast. De eerdere afsluitstempel is verwijderd omdat de huur opnieuw geopend werd.');
    } else {
        flash('success', 'Status aangepast.');
    }

    redirect('index.php?route=reservation-view&id=' . $id);
}
