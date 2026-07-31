<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$route = (string) ($_GET['route'] ?? 'planning');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($route === 'login') {
    if (current_user()) {
        redirect('?route=planning');
    }

    if ($method === 'POST') {
        verify_csrf();
        $user = find_user_by_email((string) ($_POST['email'] ?? ''));
        if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password_hash'])) {
            login_user($user);
            audit('login', 'user', (int) $user['id']);
            redirect('?route=planning');
        }
        flash('error', 'E-mailadres of wachtwoord is niet correct.');
        redirect('?route=login');
    }

    render_header('Aanmelden', false); ?>
    <section class="card login-card">
        <div class="login-logo">Aerts Action Bike</div>
        <p class="muted">Interne verhuurmodule</p>
        <form method="post" class="stack">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <div class="field"><label>E-mailadres</label><input name="email" type="email" required autocomplete="username"></div>
            <div class="field"><label>Wachtwoord</label><input name="password" type="password" required autocomplete="current-password"></div>
            <button class="button">Aanmelden</button>
        </form>
    </section>
    <?php render_footer(); exit;
}

if ($route === 'logout' && $method === 'POST') {
    require_auth();
    verify_csrf();
    audit('logout', 'user', (int) current_user()['id']);
    logout_user();
    redirect('?route=login');
}

require_auth();

if ($route === 'planning') {
    $days = max(7, min(28, (int) ($_GET['days'] ?? 14)));
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($_GET['start'] ?? date('Y-m-d'))) ?: new DateTimeImmutable('today');
    $end = $start->modify("+{$days} days");
    $bikes = all_bikes(false);
    $events = reservations_for_range($start, $end);
    $counts = reservation_counts();
    $byBike = [];
    foreach ($events as $event) {
        $byBike[(int) $event['bike_id']][] = $event;
    }

    render_header('Verhuurplanning'); ?>
    <section class="grid">
        <div class="card col-4"><span class="stat"><?= (int) ($counts['pickups'] ?? 0) ?></span><span class="muted">afhalingen vandaag</span></div>
        <div class="card col-4"><span class="stat"><?= (int) ($counts['returns'] ?? 0) ?></span><span class="muted">retours vandaag</span></div>
        <div class="card col-4"><span class="stat"><?= (int) ($counts['active'] ?? 0) ?></span><span class="muted">fietsen onderweg</span></div>
    </section>
    <section class="card mt-18">
        <div class="planning-toolbar">
            <div class="actions">
                <a class="button button-secondary" href="?route=planning&amp;start=<?= e($start->modify("-{$days} days")->format('Y-m-d')) ?>&amp;days=<?= $days ?>">← Vorige</a>
                <a class="button button-secondary" href="?route=planning&amp;days=<?= $days ?>">Vandaag</a>
                <a class="button button-secondary" href="?route=planning&amp;start=<?= e($end->format('Y-m-d')) ?>&amp;days=<?= $days ?>">Volgende →</a>
            </div>
            <div class="actions">
                <a href="?route=planning&amp;days=7">7 dagen</a>
                <a href="?route=planning&amp;days=14">14 dagen</a>
                <a href="?route=planning&amp;days=28">28 dagen</a>
                <a class="button" href="?route=reservation-new">+ Nieuwe verhuur</a>
            </div>
        </div>
        <?php if (!$bikes): ?>
            <div class="alert alert-warning">Voeg eerst een verhuurfiets toe.</div>
            <a class="button" href="?route=bikes">Fiets toevoegen</a>
        <?php else: ?>
            <div class="planning-wrap"><table class="planning">
                <thead><tr><th class="bike-cell">Fiets</th>
                <?php for ($i = 0; $i < $days; $i++): $date = $start->modify("+{$i} days"); ?>
                    <th class="<?= $date->format('Y-m-d') === date('Y-m-d') ? 'today' : '' ?> <?= in_array((int) $date->format('N'), [6, 7], true) ? 'weekend' : '' ?>"><?= e($date->format('D')) ?><br><strong><?= e($date->format('d/m')) ?></strong></th>
                <?php endfor; ?></tr></thead>
                <tbody>
                <?php foreach ($bikes as $bike): $cursor = 0; $bikeEvents = $byBike[(int) $bike['id']] ?? []; ?>
                    <tr><td class="bike-cell"><strong><?= e($bike['name']) ?></strong><br><span class="muted"><?= e($bike['code']) ?> · <?= e($bike['category']) ?></span></td>
                    <?php while ($cursor < $days):
                        $day = $start->modify("+{$cursor} days");
                        $dayEnd = $day->modify('+1 day');
                        $active = null;
                        foreach ($bikeEvents as $candidate) {
                            if (new DateTimeImmutable($candidate['start_at']) < $dayEnd && new DateTimeImmutable($candidate['end_at']) > $day) {
                                $active = $candidate;
                                break;
                            }
                        }
                        if ($active):
                            $activeEnd = new DateTimeImmutable($active['end_at']);
                            $span = 1;
                            while ($cursor + $span < $days && $start->modify('+' . ($cursor + $span) . ' days') < $activeEnd) {
                                $span++;
                            }
                    ?>
                        <td colspan="<?= $span ?>"><a class="booking-block status-<?= e($active['status']) ?>" href="?route=reservation-view&amp;id=<?= (int) $active['id'] ?>"><strong><?= e($active['customer_name']) ?></strong><span><?= e((new DateTimeImmutable($active['start_at']))->format('d/m H:i')) ?> → <?= e($activeEnd->format('d/m H:i')) ?></span><span><?= e(status_label($active['status'])) ?><?= $active['document_id'] ? ' · ID ✓' : ' · geen ID' ?></span></a></td>
                    <?php $cursor += $span; else: ?>
                        <td class="day-cell <?= in_array((int) $day->format('N'), [6, 7], true) ? 'weekend' : '' ?>"><a class="empty-slot" href="?route=reservation-new&amp;bike_id=<?= (int) $bike['id'] ?>&amp;start_date=<?= e($day->format('Y-m-d')) ?>" title="Nieuwe verhuur"></a></td>
                    <?php $cursor++; endif; endwhile; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
    <?php render_footer(); exit;
}

if ($route === 'bikes') {
    if ($method === 'POST') {
        verify_csrf();
        $data = [
            ':code' => strtoupper(trim((string) ($_POST['code'] ?? ''))),
            ':name' => trim((string) ($_POST['name'] ?? '')),
            ':category' => trim((string) ($_POST['category'] ?? '')),
            ':frame_size' => trim((string) ($_POST['frame_size'] ?? '')) ?: null,
            ':daily_rate' => max(0, (float) ($_POST['daily_rate'] ?? 0)),
            ':status' => in_array($_POST['status'] ?? '', ['active', 'maintenance', 'inactive'], true) ? $_POST['status'] : 'active',
            ':notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
        ];

        if (!$data[':code'] || !$data[':name'] || !$data[':category']) {
            flash('error', 'Code, naam en categorie zijn verplicht.');
            redirect('?route=bikes');
        }

        try {
            $stmt = db()->prepare('INSERT INTO bikes (code,name,category,frame_size,daily_rate,status,notes) VALUES (:code,:name,:category,:frame_size,:daily_rate,:status,:notes)');
            $stmt->execute($data);
            audit('create', 'bike', (int) db()->lastInsertId(), ['code' => $data[':code']]);
            flash('success', 'Fiets toegevoegd.');
        } catch (PDOException $e) {
            flash('error', str_contains($e->getMessage(), 'UNIQUE') ? 'Deze fietscode bestaat al.' : 'Opslaan is mislukt.');
        }
        redirect('?route=bikes');
    }

    $bikes = all_bikes(true);
    render_header('Verhuurfietsen'); ?>
    <section class="grid">
        <div class="card col-8"><h2>Overzicht</h2><div class="table-wrap"><table><thead><tr><th>Code</th><th>Fiets</th><th>Categorie</th><th>Maat</th><th>Dagprijs</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($bikes as $bike): ?><tr><td><strong><?= e($bike['code']) ?></strong></td><td><?= e($bike['name']) ?></td><td><?= e($bike['category']) ?></td><td><?= e($bike['frame_size'] ?: '—') ?></td><td>€ <?= number_format((float) $bike['daily_rate'], 2, ',', '.') ?></td><td><span class="badge badge-<?= e($bike['status']) ?>"><?= e(ucfirst($bike['status'])) ?></span></td></tr><?php endforeach; ?>
        <?php if (!$bikes): ?><tr><td colspan="6" class="muted">Nog geen fietsen.</td></tr><?php endif; ?>
        </tbody></table></div></div>
        <div class="card col-4"><h2>Fiets toevoegen</h2><form method="post" class="stack"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <div class="field"><label>Interne code *</label><input name="code" required placeholder="AAB-E01"></div>
            <div class="field"><label>Naam/model *</label><input name="name" required></div>
            <div class="field"><label>Categorie *</label><select name="category" required><option value="">Kies…</option><option>E-bike</option><option>Stadsfiets</option><option>Kinderfiets</option><option>Tandem</option><option>Racefiets</option><option>Gravelfiets</option><option>Andere</option></select></div>
            <div class="field"><label>Framemaat</label><input name="frame_size"></div>
            <div class="field"><label>Dagprijs</label><input name="daily_rate" type="number" min="0" step="0.01" value="0"></div>
            <div class="field"><label>Status</label><select name="status"><option value="active">Actief</option><option value="maintenance">Onderhoud</option><option value="inactive">Inactief</option></select></div>
            <div class="field"><label>Notities</label><textarea name="notes"></textarea></div>
            <button class="button">Fiets opslaan</button>
        </form></div>
    </section>
    <?php render_footer(); exit;
}

if ($route === 'reservation-new') {
    $bikes = all_bikes(false);
    $selectedBike = (int) ($_GET['bike_id'] ?? $_POST['bike_id'] ?? 0);
    $startDate = (string) ($_GET['start_date'] ?? date('Y-m-d'));

    if ($method === 'POST') {
        verify_csrf();
        $bikeId = (int) ($_POST['bike_id'] ?? 0);
        $startAt = parse_datetime((string) ($_POST['start_date'] ?? ''), (string) ($_POST['start_time'] ?? ''));
        $endAt = parse_datetime((string) ($_POST['end_date'] ?? ''), (string) ($_POST['end_time'] ?? ''));
        $name = trim((string) ($_POST['customer_name'] ?? ''));

        if (!$bikeId || !$startAt || !$endAt || $endAt <= $startAt || !$name) {
            flash('error', 'Vul alle verplichte velden correct in.');
            redirect('?route=reservation-new');
        }

        if (reservation_conflicts($bikeId, $startAt->format('Y-m-d H:i:s'), $endAt->format('Y-m-d H:i:s'))) {
            flash('error', 'Deze fiets is al ingepland binnen dit tijdsblok.');
            redirect('?route=reservation-new&amp;bike_id=' . $bikeId . '&amp;start_date=' . $startAt->format('Y-m-d'));
        }

        try {
            db()->beginTransaction();
            $stmt = db()->prepare('INSERT INTO customers (name,email,phone,address) VALUES (:name,:email,:phone,:address)');
            $stmt->execute([
                ':name' => $name,
                ':email' => trim((string) ($_POST['customer_email'] ?? '')) ?: null,
                ':phone' => trim((string) ($_POST['customer_phone'] ?? '')) ?: null,
                ':address' => trim((string) ($_POST['customer_address'] ?? '')) ?: null,
            ]);
            $customerId = (int) db()->lastInsertId();
            $retention = trim((string) ($_POST['retention_until'] ?? '')) ?: $endAt->modify('+' . (int) env('ID_RETENTION_DAYS', '30') . ' days')->format('Y-m-d');
            $documentId = upload_identity_document($_FILES['identity_document'] ?? [], $customerId, $retention);
            $stmt = db()->prepare('INSERT INTO reservations (bike_id,customer_id,identity_document_id,start_at,end_at,status,total_price,notes,created_by) VALUES (:bike,:customer,:document,:start,:end,:status,:price,:notes,:user)');
            $stmt->execute([
                ':bike' => $bikeId,
                ':customer' => $customerId,
                ':document' => $documentId,
                ':start' => $startAt->format('Y-m-d H:i:s'),
                ':end' => $endAt->format('Y-m-d H:i:s'),
                ':status' => ($_POST['status'] ?? 'reserved') === 'confirmed' ? 'confirmed' : 'reserved',
                ':price' => max(0, (float) ($_POST['total_price'] ?? 0)),
                ':notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
                ':user' => current_user()['id'],
            ]);
            $id = (int) db()->lastInsertId();
            db()->commit();
            audit('create', 'reservation', $id, ['bike_id' => $bikeId, 'has_identity_document' => $documentId !== null]);
            flash('success', 'Verhuur is ingepland.');
            redirect('?route=reservation-view&amp;id=' . $id);
        } catch (Throwable $e) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Opslaan is mislukt.');
            redirect('?route=reservation-new');
        }
    }

    $endDate = (new DateTimeImmutable($startDate))->modify('+1 day')->format('Y-m-d');
    render_header('Nieuwe verhuur'); ?>
    <section class="card"><form method="post" enctype="multipart/form-data" class="stack"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
        <div class="form-grid">
            <div class="field field-full"><label>Fiets *</label><select name="bike_id" required><option value="">Kies…</option><?php foreach ($bikes as $item): ?><option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === $selectedBike ? 'selected' : '' ?>><?= e($item['code'] . ' — ' . $item['name'] . ' (' . $item['category'] . ')') ?></option><?php endforeach; ?></select></div>
            <div class="field"><label>Startdatum *</label><input name="start_date" type="date" value="<?= e($startDate) ?>" required></div>
            <div class="field"><label>Afhaaluur *</label><input name="start_time" type="time" value="09:00" required></div>
            <div class="field"><label>Einddatum *</label><input name="end_date" type="date" value="<?= e($endDate) ?>" required></div>
            <div class="field"><label>Retouruur *</label><input name="end_time" type="time" value="17:00" required></div>
        </div>
        <hr><h2>Klantgegevens</h2><div class="form-grid">
            <div class="field"><label>Volledige naam *</label><input name="customer_name" required></div>
            <div class="field"><label>Telefoon</label><input name="customer_phone" type="tel"></div>
            <div class="field"><label>E-mail</label><input name="customer_email" type="email"></div>
            <div class="field"><label>Adres</label><input name="customer_address"></div>
            <div class="field field-full"><label>Identiteitsdocument</label><input name="identity_document" type="file" accept="image/jpeg,image/png,application/pdf"><span class="help">JPG, PNG of PDF · max. <?= e(env('ID_MAX_MB', '8')) ?> MB · privé opgeslagen.</span></div>
            <div class="field"><label>Automatisch verwijderen na</label><input name="retention_until" type="date" value="<?= e((new DateTimeImmutable($endDate))->modify('+' . (int) env('ID_RETENTION_DAYS', '30') . ' days')->format('Y-m-d')) ?>"></div>
        </div>
        <hr><h2>Prijs en intern</h2><div class="form-grid">
            <div class="field"><label>Status</label><select name="status"><option value="reserved">Gereserveerd</option><option value="confirmed">Bevestigd</option></select></div>
            <div class="field"><label>Totaalprijs</label><input name="total_price" type="number" min="0" step="0.01" value="0"></div>
            <div class="field field-full"><label>Interne notities</label><textarea name="notes"></textarea></div>
        </div>
        <div class="actions"><button class="button">Verhuur inplannen</button><a class="button button-secondary" href="?route=planning">Annuleren</a></div>
    </form></section>
    <?php render_footer(); exit;
}

if ($route === 'reservation-view') {
    $id = (int) ($_GET['id'] ?? 0);
    $reservation = find_reservation($id);
    if (!$reservation) {
        http_response_code(404);
        exit('Verhuur niet gevonden.');
    }

    render_header('Verhuur #' . $id); ?>
    <section class="grid">
        <div class="card col-8"><div class="actions actions-between"><h2><?= e($reservation['bike_code'] . ' — ' . $reservation['bike_name']) ?></h2><span class="badge status-<?= e($reservation['status']) ?>"><?= e(status_label($reservation['status'])) ?></span></div>
            <dl class="summary-list"><dt>Periode</dt><dd><?= e((new DateTimeImmutable($reservation['start_at']))->format('d/m/Y H:i')) ?> → <?= e((new DateTimeImmutable($reservation['end_at']))->format('d/m/Y H:i')) ?></dd><dt>Klant</dt><dd><?= e($reservation['customer_name']) ?></dd><dt>Telefoon</dt><dd><?= e($reservation['customer_phone'] ?: '—') ?></dd><dt>E-mail</dt><dd><?= e($reservation['customer_email'] ?: '—') ?></dd><dt>Adres</dt><dd><?= e($reservation['customer_address'] ?: '—') ?></dd><dt>Totaalprijs</dt><dd>€ <?= number_format((float) $reservation['total_price'], 2, ',', '.') ?></dd><dt>Notities</dt><dd><?= nl2br(e($reservation['notes'] ?: '—')) ?></dd></dl>
        </div>
        <div class="card col-4"><h2>Identiteitsdocument</h2>
        <?php if ($reservation['document_id'] && !$reservation['document_deleted_at']): ?>
            <p><strong><?= e($reservation['document_name']) ?></strong><br><span class="muted"><?= e($reservation['document_mime']) ?> · <?= number_format((int) $reservation['document_size'] / 1024, 0, ',', '.') ?> KB</span></p>
            <p class="muted">Bewaren tot <?= e($reservation['retention_until'] ? (new DateTimeImmutable($reservation['retention_until']))->format('d/m/Y') : 'niet ingesteld') ?></p>
            <a class="button" href="?route=id-download&amp;id=<?= (int) $reservation['document_id'] ?>">Veilig openen</a>
        <?php else: ?>
            <p class="muted">Nog geen document gekoppeld.</p>
            <form method="post" action="?route=id-upload&amp;reservation_id=<?= $id ?>" enctype="multipart/form-data" class="stack">
                <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                <div class="field"><label>Document</label><input name="identity_document" type="file" accept="image/jpeg,image/png,application/pdf" required></div>
                <div class="field"><label>Verwijderen na</label><input name="retention_until" type="date" value="<?= e((new DateTimeImmutable($reservation['end_at']))->modify('+' . (int) env('ID_RETENTION_DAYS', '30') . ' days')->format('Y-m-d')) ?>" required></div>
                <button class="button">ID veilig uploaden</button>
            </form>
        <?php endif; ?>
        </div>
        <div class="card col-12"><h2>Status wijzigen</h2><form method="post" action="?route=reservation-status&amp;id=<?= $id ?>" class="actions"><input type="hidden" name="_token" value="<?= e(csrf_token()) ?>"><select class="select-narrow" name="status"><?php foreach (['reserved', 'confirmed', 'picked_up', 'returned', 'cancelled'] as $status): ?><option value="<?= e($status) ?>" <?= $reservation['status'] === $status ? 'selected' : '' ?>><?= e(status_label($status)) ?></option><?php endforeach; ?></select><button class="button">Status opslaan</button><a class="button button-secondary" href="?route=planning&amp;start=<?= e((new DateTimeImmutable($reservation['start_at']))->format('Y-m-d')) ?>">Toon in planning</a></form></div>
    </section>
    <?php render_footer(); exit;
}

if ($route === 'reservation-status' && $method === 'POST') {
    verify_csrf();
    $id = (int) ($_GET['id'] ?? 0);
    $status = (string) ($_POST['status'] ?? '');
    if (!find_reservation($id) || !in_array($status, ['reserved', 'confirmed', 'picked_up', 'returned', 'cancelled'], true)) {
        flash('error', 'Ongeldige status.');
        redirect('?route=planning');
    }
    $stmt = db()->prepare('UPDATE reservations SET status=:status, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
    $stmt->execute([':status' => $status, ':id' => $id]);
    audit('status_update', 'reservation', $id, ['status' => $status]);
    flash('success', 'Status aangepast.');
    redirect('?route=reservation-view&amp;id=' . $id);
}

if ($route === 'id-upload' && $method === 'POST') {
    verify_csrf();
    $id = (int) ($_GET['reservation_id'] ?? 0);
    $reservation = find_reservation($id);
    if (!$reservation) {
        flash('error', 'Verhuur niet gevonden.');
        redirect('?route=planning');
    }
    try {
        $documentId = upload_identity_document($_FILES['identity_document'] ?? [], (int) $reservation['customer_id'], (string) ($_POST['retention_until'] ?? ''));
        if (!$documentId) {
            throw new RuntimeException('Selecteer een document.');
        }
        $stmt = db()->prepare('UPDATE reservations SET identity_document_id=:document, updated_at=CURRENT_TIMESTAMP WHERE id=:id');
        $stmt->execute([':document' => $documentId, ':id' => $id]);
        audit('upload', 'identity_document', $documentId, ['reservation_id' => $id]);
        flash('success', 'Identiteitsdocument gekoppeld.');
    } catch (Throwable $e) {
        flash('error', $e instanceof RuntimeException ? $e->getMessage() : 'Uploaden is mislukt.');
    }
    redirect('?route=reservation-view&amp;id=' . $id);
}

if ($route === 'id-download') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM identity_documents WHERE id=:id AND deleted_at IS NULL');
    $stmt->execute([':id' => $id]);
    $document = $stmt->fetch();
    if (!$document) {
        http_response_code(404);
        exit('Document niet gevonden.');
    }
    $path = ROOT_PATH . '/storage/private/ids/' . basename($document['stored_name']);
    if (!is_file($path)) {
        http_response_code(404);
        exit('Bestand niet gevonden.');
    }
    audit('download', 'identity_document', $id);
    header('Content-Type: ' . $document['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode($document['original_name']) . '"');
    header('Cache-Control: no-store, private');
    readfile($path);
    exit;
}

http_response_code(404);
render_header('Pagina niet gevonden');
echo '<div class="alert alert-error">De gevraagde pagina bestaat niet.</div>';
render_footer();
