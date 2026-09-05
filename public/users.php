<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_admin();

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$editId = (int) ($_GET['edit'] ?? $_POST['id'] ?? 0);
$editUser = $editId > 0 ? find_user($editId) : null;

if ($method === 'POST') {
    verify_csrf();

    if ($editId > 0 && !$editUser) {
        flash('error', 'Gebruiker niet gevonden.');
        redirect('users.php');
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $role = in_array($_POST['role'] ?? '', ['admin', 'staff'], true) ? (string) $_POST['role'] : 'staff';
    $active = !empty($_POST['active']) ? 1 : 0;
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Vul een naam en geldig e-mailadres in.');
        redirect($editId > 0 ? 'users.php?edit=' . $editId : 'users.php');
    }

    if ($editId === 0 && strlen($password) < 8) {
        flash('error', 'Een nieuw account heeft een wachtwoord van minstens 8 tekens nodig.');
        redirect('users.php');
    }

    if ($password !== '' && strlen($password) < 8) {
        flash('error', 'Het nieuwe wachtwoord moet minstens 8 tekens bevatten.');
        redirect($editId > 0 ? 'users.php?edit=' . $editId : 'users.php');
    }

    if ($editId === (int) current_user()['id'] && ($active !== 1 || $role !== 'admin')) {
        flash('error', 'Je kan je eigen beheerdersaccount niet deactiveren of degraderen.');
        redirect('users.php?edit=' . $editId);
    }

    try {
        if ($editUser) {
            $sql = 'UPDATE users SET name = :name, email = :email, role = :role, active = :active';
            $params = [
                ':name' => $name,
                ':email' => $email,
                ':role' => $role,
                ':active' => $active,
                ':id' => $editId,
            ];
            if ($password !== '') {
                $sql .= ', password_hash = :password_hash';
                $params[':password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE id = :id';
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $userId = $editId;
            $action = 'update';
        } else {
            $stmt = db()->prepare(
                'INSERT INTO users (name, email, password_hash, role, active, created_at)
                 VALUES (:name, :email, :password_hash, :role, :active, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':role' => $role,
                ':active' => $active,
            ]);
            $userId = (int) db()->lastInsertId();
            $action = 'create';
        }

        audit($action, 'user', $userId, [
            'email' => $email,
            'role' => $role,
            'active' => $active,
            'password_changed' => $password !== '',
        ]);
        flash('success', $editUser ? 'Gebruikersprofiel bijgewerkt.' : 'Gebruikersprofiel aangemaakt.');
        redirect('users.php?edit=' . $userId);
    } catch (PDOException $e) {
        $message = str_contains($e->getMessage(), 'UNIQUE')
            ? 'Dit e-mailadres is al aan een ander profiel gekoppeld.'
            : 'Opslaan is mislukt.';
        flash('error', $message);
        redirect($editId > 0 ? 'users.php?edit=' . $editId : 'users.php');
    }
}

$users = all_users();
$editUser = $editId > 0 ? find_user($editId) : null;

render_header('Gebruikersprofielen');
?>
<section class="grid">
    <div class="card col-8">
        <div class="actions actions-between">
            <div>
                <h2>Accounts</h2>
                <p class="muted">Elke medewerker gebruikt een eigen login. De naam van het profiel wordt bij aanmaak en afsluiting van een huur geregistreerd.</p>
            </div>
            <a class="button button-secondary" href="users.php">+ Nieuw profiel</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Naam</th><th>E-mail</th><th>Rol</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><strong><?= e((string) $user['name']) ?></strong></td>
                        <td><?= e((string) $user['email']) ?></td>
                        <td><?= $user['role'] === 'admin' ? 'Beheerder' : 'Medewerker' ?></td>
                        <td><span class="badge <?= (int) $user['active'] === 1 ? 'badge-active' : 'badge-inactive' ?>"><?= (int) $user['active'] === 1 ? 'Actief' : 'Inactief' ?></span></td>
                        <td><a href="users.php?edit=<?= (int) $user['id'] ?>">Bewerken</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <aside class="card col-4">
        <h2><?= $editUser ? 'Profiel bewerken' : 'Profiel toevoegen' ?></h2>
        <form method="post" class="stack">
            <input type="hidden" name="_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) ($editUser['id'] ?? 0) ?>">
            <div class="field">
                <label>Naam *</label>
                <input name="name" required value="<?= e((string) ($editUser['name'] ?? '')) ?>" placeholder="Verkoop">
            </div>
            <div class="field">
                <label>E-mailadres *</label>
                <input name="email" type="email" required value="<?= e((string) ($editUser['email'] ?? '')) ?>" placeholder="verkoop@aertsactionbike.be">
            </div>
            <div class="field">
                <label>Rol</label>
                <select name="role">
                    <option value="staff" <?= ($editUser['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Medewerker</option>
                    <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Beheerder</option>
                </select>
            </div>
            <div class="field">
                <label><?= $editUser ? 'Nieuw wachtwoord' : 'Wachtwoord *' ?></label>
                <input name="password" type="password" <?= $editUser ? '' : 'required' ?> minlength="8" autocomplete="new-password">
                <span class="help"><?= $editUser ? 'Leeg laten om het huidige wachtwoord te behouden.' : 'Minstens 8 tekens. Gebruik vóór definitieve ingebruikname een uniek wachtwoord per medewerker.' ?></span>
            </div>
            <label class="actions">
                <input class="checkbox-inline" name="active" type="checkbox" value="1" <?= !$editUser || (int) $editUser['active'] === 1 ? 'checked' : '' ?>>
                Account actief
            </label>
            <button class="button"><?= $editUser ? 'Profiel opslaan' : 'Profiel aanmaken' ?></button>
        </form>
    </aside>
</section>
<?php render_footer();
