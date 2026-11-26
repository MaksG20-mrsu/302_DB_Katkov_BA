<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/app.php';
$pageTitle = 'Мастера и оказываемые услуги';
$errorMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST);
        $employeeId = createMaster($_POST);
        redirectWithMessage('masters.php', 'Мастер № ' . $employeeId . ' добавлен вместе с выбранными услугами.');
    } catch (ValidationException $error) {
        http_response_code(422);
        $errorMessage = $error->getMessage();
    } catch (PDOException $error) {
        http_response_code(422);
        $errorMessage = storageError($error);
    }
}
$services = activeServices();
$masters = queryRows("SELECT e.*,group_concat(s.name, ', ') AS service_names
    FROM employees e LEFT JOIN master_services ms ON ms.employee_id=e.id
    LEFT JOIN services s ON s.id=ms.service_id
    WHERE e.hired_on<=:day AND (e.dismissed_on IS NULL OR e.dismissed_on>:day)
    GROUP BY e.id ORDER BY e.last_name,e.first_name,e.id", ['day' => date('Y-m-d')]);
$checkedServices = $_POST['service_ids'] ?? [];
$checkedServices = is_array($checkedServices) ? $checkedServices : [];
require APP_ROOT . '/templates/header.php';
?>
<p class="lead">Добавьте работающего мастера и отметьте услуги, которые он может выполнять.</p>
<?php if ($errorMessage !== null): ?><p class="error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>
<form method="post" class="panel form-grid">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
    <label>Фамилия<input name="last_name" required maxlength="100" autocomplete="family-name" value="<?= escapeHtml(formValue('last_name')) ?>"></label>
    <label>Имя<input name="first_name" required maxlength="100" autocomplete="given-name" value="<?= escapeHtml(formValue('first_name')) ?>"></label>
    <label>Телефон<input name="phone" type="tel" required maxlength="30" autocomplete="tel" value="<?= escapeHtml(formValue('phone')) ?>"></label>
    <label>Дата приёма<input name="hired_on" type="date" required max="<?= date('Y-m-d') ?>" value="<?= escapeHtml(formValue('hired_on', date('Y-m-d'))) ?>"></label>
    <label>Процент зарплаты<input name="commission_percent" type="number" required min="0" max="100" step="0.01" value="<?= escapeHtml(formValue('commission_percent', '30')) ?>"></label>
    <fieldset class="full-width"><legend>Услуги мастера — выберите хотя бы одну</legend>
        <div class="checkbox-list">
        <?php foreach ($services as $service): ?>
            <label><input type="checkbox" name="service_ids[]" value="<?= escapeHtml($service['id']) ?>"<?= in_array((string) $service['id'], $checkedServices, true) ? ' checked' : '' ?>>
                <?= escapeHtml($service['name']) ?></label>
        <?php endforeach; ?>
        </div>
    </fieldset>
    <div class="full-width"><button type="submit">Сохранить мастера</button></div>
</form>
<section class="panel"><h2>Работающие мастера</h2>
    <div class="table-wrapper"><table>
        <thead><tr><th>Номер</th><th>Фамилия и имя</th><th>Телефон</th><th>Дата приёма</th><th>Процент</th><th>Услуги</th></tr></thead>
        <tbody>
        <?php foreach ($masters as $master): ?>
            <tr><td><?= escapeHtml($master['id']) ?></td><td><?= escapeHtml(masterFullName($master)) ?></td>
                <td><?= escapeHtml($master['phone']) ?></td><td><?= escapeHtml($master['hired_on']) ?></td>
                <td><?= escapeHtml($master['commission_percent']) ?>%</td><td><?= escapeHtml($master['service_names'] ?? 'Не назначены') ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
