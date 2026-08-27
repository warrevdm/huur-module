<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_auth();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$editId = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
$editBike = $editId > 0 ? find_bike($editId) : null;

if ($method === 'POST' && (string) ($_POST['action'] ?? '') === 'optimize_images') {
    verify_csrf();

    $optimized = 0;
    $failed = 0;
    foreach (all_bikes(true) as $bike) {
        if (empty($bike['photo_stored_name'])) {
            continue;
        }
        $variant = bike_ensure_web_variant($bike, 240);
        if ($variant !== null) {
            $optimized++;
        } else {
            $failed++;
        }
    }

    flash(
        $failed === 0 ? 'success' : 'warning',
        $failed === 0
            ? "{$optimized} fietsafbeeldingen geoptimaliseerd."
            : "{$optimized} afbeeldingen geoptimaliseerd; {$failed} konden niet worden verwerkt."
    );
    redirect('bikes.php');
}

if ($method === 'POST') {
    verify_csrf();

    if ($editId > 0 && !$editBike) {
        flash('error', 'Fiets niet gevonden.');
        redirect('bikes.php');
    }

    $frameNumber = strtoupper(trim((string) ($_POST['frame_number'] ?? '')));
    $frameNumber = preg_replace('/\s+/', '', $frameNumber) ?: '';

    $data = [
        ':code' => strtoupper(trim((string) ($_POST['code'] ?? ''))),
        ':name' => trim((string) ($_POST['name'] ?? '')),
        ':category' => trim((string) ($_POST['category'] ?? '')),
        ':usage_type' => in_array((string) ($_POST['usage_type'] ?? ''), ['rental', 'replacement', 'test', 'replacement_rental'], true)
            ? (string) $_POST['usage_type']
            : 'rental',
        ':frame_size' => trim((string) ($_POST['frame_size'] ?? '')) ?: null,
        ':frame_number' => $frameNumber ?: null,
        ':daily_rate' => max(0, (float) ($_POST['daily_rate'] ?? 0)),
        ':status' => in_array($_POST['status'] ?? '', ['active', 'maintenance', 'inactive'], true) ? $_POST['status'] : 'active',
        ':notes' => trim((string) ($_POST['notes'] ?? '')) ?: null,
    ];

    if (!$data[':code'] || !$data[':name'] || !$data[':category'] || !$data[':frame_number']) {
        flash('error', 'Interne code, naam, categorie en framenummer zijn verplicht.');
        redirect($editId > 0 ? 'bikes.php?edit=' . $editId : 'bikes.php');
    }

    $newPhoto = null;
    $oldStoredName = $editBike['photo_stored_name'] ?? null;
    $removePhoto = !empty($_POST['remove_photo']);

    try {
        $newPhoto = upload_bike_photo($_FILES['photo'] ?? []);

        $photoStoredName = $newPhoto['stored_name'] ?? ($removePhoto ? null : ($editBike['photo_stored_name'] ?? null));
        $photoOriginalName = $newPhoto['original_name'] ?? ($removePhoto ? null : ($editBike['photo_original_name'] ?? null));
        $photoMimeType = $newPhoto['mime_type'] ?? ($removePhoto ? null : ($editBike['photo_mime_type'] ?? null));
        $photoSizeBytes = $newPhoto['size_bytes'] ?? ($removePhoto ? null : ($editBike['photo_size_bytes'] ?? null));

        db()->beginTransaction();

        if ($editBike) {
            $stmt = db()->prepare(
                'UPDATE bikes
                 SET code = :code,
                     name = :name,
                     category = :category,
                     usage_type = :usage_type,
                     frame_size = :frame_size,
                     frame_number = :frame_number,
                     photo_stored_name = :photo_stored_name,
                     photo_original_name = :photo_original_name,
                     photo_mime_type = :photo_mime_type,
                     photo_size_bytes = :photo_size_bytes,
                     daily_rate = :daily_rate,
                     status = :status,
                     notes = :notes,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute($data + [
                ':photo_stored_name' => $photoStoredName,
                ':photo_original_name' => $photoOriginalName,
                ':photo_mime_type' => $photoMimeType,
                ':photo_size_bytes' => $photoSizeBytes,
                ':id' => $editId,
            ]);
            $bikeId = $editId;
            $auditAction = 'update';
        } else {
            $stmt = db()->prepare(
                'INSERT INTO bikes
                 (code, name, category, usage_type, frame_size, frame_number, photo_stored_name, photo_original_name, photo_mime_type, photo_size_bytes, daily_rate, status, notes)
                 VALUES
                 (:code, :name, :category, :usage_type, :frame_size, :frame_number, :photo_stored_name, :photo_original_name, :photo_mime_type, :photo_size_bytes, :daily_rate, :status, :notes)'
            );
            $stmt->execute($data + [
                ':photo_stored_name' => $photoStoredName,
                ':photo_original_name' => $photoOriginalName,
                ':photo_mime_type' => $photoMimeType,
                ':photo_size_bytes' => $photoSizeBytes,
            ]);
            $bikeId = (int) db()->lastInsertId();
            $auditAction = 'create';
        }

        audit($auditAction, 'bike', $bikeId, [
            'code' => $data[':code'],
            'frame_number' => $data[':frame_number'],
            'usage_type' => $data[':usage_type'],
            'has_photo' => $photoStoredName !== null,
        ]);
        db()->commit();

        if (($newPhoto !== null || $removePhoto) && $oldStoredName && $oldStoredName !== $photoStoredName) {
            delete_bike_photo((string) $oldStoredName);
        }

        if ($newPhoto !== null) {
            $savedBike = find_bike($bikeId);
            if ($savedBike) {
                bike_pregenerate_web_variants($savedBike, [240, 800]);
            }
        }

        flash('success', $editBike ? 'Fiets bijgewerkt.' : 'Fiets toegevoegd.');
        redirect('bikes.php?edit=' . $bikeId);
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        if ($newPhoto !== null) {
            delete_bike_photo((string) $newPhoto['stored_name']);
        }

        $message = $e instanceof RuntimeException ? $e->getMessage() : 'Opslaan is mislukt.';
        if ($e instanceof PDOException && str_contains($e->getMessage(), 'UNIQUE')) {
            $message = 'Deze interne code of dit framenummer bestaat al.';
        }
        flash('error', $message);
        redirect($editId > 0 ? 'bikes.php?edit=' . $editId : 'bikes.php');
    }
}

$bikes = all_bikes(true);
$editBike = $editId > 0 ? find_bike($editId) : null;
$categories = ['E-bike', 'Speedpedelec', 'Stadsfiets', 'Kinderfiets', 'Tandem', 'Racefiets', 'Gravelfiets', 'Andere'];
$usageTypes = [
    'rental' => 'Huurfiets',
    'replacement' => 'Vervangfiets',
    'test' => 'Testfiets',
    'replacement_rental' => 'Vervang-/huurfiets',
];

render_header('Verhuurfietsen');
?>
<section class="grid bike-management-grid">
    <div class="card col-8">
        <div class="actions actions-between">
            <div>
                <h2>Fietsoverzicht</h2>
                <p class="muted">Herken elke fiets aan foto, interne code, framenummer en inzettype.</p>
            </div>
            <div class="actions">
                <form method="post">
                    <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="optimize_images">
                    <button class="button button-secondary" type="submit">Foto's optimaliseren</button>
                </form>
                <a class="button button-secondary" href="bikes.php">+ Nieuwe fiets</a>
            </div>
        </div>

        <?php if (!$bikes): ?>
            <div class="alert alert-warning">Nog geen fietsen toegevoegd.</div>
        <?php else: ?>
            <div class="bike-card-grid">
                <?php foreach ($bikes as $index => $bike): ?>
                    <article class="bike-card <?= $editId === (int) $bike['id'] ? 'bike-card-selected' : '' ?>">
                        <div class="bike-photo-frame">
                            <?php if (!empty($bike['photo_stored_name'])): ?>
                                <img src="<?= e(bike_photo_src($bike, 240)) ?>" alt="<?= e($bike['name']) ?>" loading="<?= $index < 4 ? 'eager' : 'lazy' ?>" decoding="async"<?= $index < 2 ? ' fetchpriority="high"' : '' ?>>
                            <?php else: ?>
                                <div class="bike-photo-placeholder">Nog geen foto</div>
                            <?php endif; ?>
                        </div>
                        <div class="bike-card-body">
                            <div class="actions actions-between bike-card-heading">
                                <strong><?= e($bike['code']) ?></strong>
                                <span class="badge badge-<?= e($bike['status']) ?>"><?= e(ucfirst($bike['status'])) ?></span>
                            </div>
                            <h3><?= e($bike['name']) ?></h3>
                            <dl class="bike-facts">
                                <dt>Framenummer</dt><dd><?= e($bike['frame_number'] ?: 'Nog invullen') ?></dd>
                                <dt>Categorie</dt><dd><?= e($bike['category']) ?></dd>
                                <dt>Inzettype</dt><dd><?= e(bike_usage_type_label((string) ($bike['usage_type'] ?? 'rental'))) ?></dd>
                                <dt>Framemaat</dt><dd><?= e($bike['frame_size'] ?: '—') ?></dd>
                                <dt>Dagprijs</dt><dd>€ <?= number_format((float) $bike['daily_rate'], 2, ',', '.') ?></dd>
                            </dl>
                            <a class="button button-secondary button-full" href="bikes.php?edit=<?= (int) $bike['id'] ?>">Gegevens en foto bewerken</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <aside class="card col-4 bike-form-card">
        <h2><?= $editBike ? 'Fiets bewerken' : 'Fiets toevoegen' ?></h2>
        <form method="post" enctype="multipart/form-data" class="stack">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) ($editBike['id'] ?? 0) ?>">

            <?php if ($editBike && !empty($editBike['photo_stored_name'])): ?>
                <div class="bike-current-photo">
                    <img src="<?= e(bike_photo_src($editBike, 800)) ?>" alt="<?= e($editBike['name']) ?>" loading="eager" decoding="async" fetchpriority="high">
                </div>
            <?php endif; ?>

            <div class="field">
                <label>Fietsafbeelding</label>
                <input name="photo" type="file" accept="image/jpeg,image/png,image/webp">
                <span class="help">JPG, PNG of WebP · maximum <?= (int) env('BIKE_IMAGE_MAX_MB', '8') ?> MB. Nieuwe foto's worden meteen geoptimaliseerd voor snelle weergave.</span>
            </div>

            <?php if ($editBike && !empty($editBike['photo_stored_name'])): ?>
                <label class="actions remove-photo-option">
                    <input class="checkbox-inline" name="remove_photo" type="checkbox" value="1">
                    Bestaande foto verwijderen
                </label>
            <?php endif; ?>

            <div class="field">
                <label>Interne code *</label>
                <input name="code" required placeholder="AAB-E01" value="<?= e((string) ($editBike['code'] ?? '')) ?>">
            </div>
            <div class="field">
                <label>Naam/model *</label>
                <input name="name" required value="<?= e((string) ($editBike['name'] ?? '')) ?>">
            </div>
            <div class="field">
                <label>Framenummer *</label>
                <input name="frame_number" required autocomplete="off" value="<?= e($editBike['frame_number'] ?: '') ?>">
                <span class="help">Wordt in hoofdletters opgeslagen en moet uniek zijn.</span>
            </div>
            <div class="field">
                <label>Categorie *</label>
                <select name="category" required>
                    <option value="">Kies…</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= e($category) ?>" <?= ($editBike['category'] ?? '') === $category ? 'selected' : '' ?>><?= e($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Inzettype *</label>
                <select name="usage_type" required>
                    <?php foreach ($usageTypes as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($editBike['usage_type'] ?? 'rental') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="help">Huurfiets, vervangfiets, testfiets of gecombineerd inzetbaar.</span>
            </div>
            <div class="field">
                <label>Framemaat</label>
                <input name="frame_size" value="<?= e((string) ($editBike['frame_size'] ?? '')) ?>">
            </div>
            <div class="field">
                <label>Dagprijs</label>
                <input name="daily_rate" type="number" min="0" step="0.01" value="<?= e((string) ($editBike['daily_rate'] ?? '0')) ?>">
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <?php foreach (['active' => 'Actief', 'maintenance' => 'Onderhoud', 'inactive' => 'Inactief'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($editBike['status'] ?? 'active') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Notities</label>
                <textarea name="notes"><?= e((string) ($editBike['notes'] ?? '')) ?></textarea>
            </div>
            <div class="actions">
                <button class="button" type="submit"><?= $editBike ? 'Wijzigingen opslaan' : 'Fiets opslaan' ?></button>
                <?php if ($editBike): ?><a class="button button-secondary" href="bikes.php">Annuleren</a><?php endif; ?>
            </div>
        </form>
    </aside>
</section>
<?php render_footer();
