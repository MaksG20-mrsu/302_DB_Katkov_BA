<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/app.php';
$pageTitle = 'График работы мастеров';
$errorMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verifyCsrf($_POST);
        createShift($_POST);
        redirectWithMessage('schedule.php', 'Рабочая смена сохранена. Время стало доступно для записи клиентов.');
    } catch (ValidationException $error) {
        http_response_code(422);
        $errorMessage = $error->getMessage();
    } catch (PDOException $error) {
        http_response_code(422);
        $errorMessage = storageError($error);
    }
}
$masters = activeMasters();
$shifts = queryRows('SELECT s.*,e.last_name,e.first_name FROM work_shifts s
    JOIN employees e ON e.id=s.employee_id WHERE s.ends_at>:now
    ORDER BY s.starts_at,e.last_name,e.first_name LIMIT 50', ['now' => date('Y-m-d H:i:s')]);
$tomorrow = date('Y-m-d', strtotime('+1 day'));
require APP_ROOT . '/templates/header.php';
?>
<p class="lead">Задайте начало и конец смены. Пересекающиеся смены одного мастера сохранять нельзя.</p>
<?php if ($errorMessage !== null): ?><p class="error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>
<form method="post" class="panel form-grid">
    <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
    <label>Мастер<select name="employee_id" required>
        <option value="">Выберите мастера</option>
        <?php foreach ($masters as $master): ?>
            <option value="<?= escapeHtml($master['id']) ?>"<?= formValue('employee_id') === (string) $master['id'] ? ' selected' : '' ?>>
                № <?= escapeHtml($master['id']) ?> — <?= escapeHtml(masterFullName($master)) ?>
            </option>
        <?php endforeach; ?>
    </select></label>
    <label>Начало смены<input type="datetime-local" name="starts_at" required value="<?= escapeHtml(formValue('starts_at', $tomorrow . 'T09:00')) ?>"></label>
    <label>Конец смены<input type="datetime-local" name="ends_at" required value="<?= escapeHtml(formValue('ends_at', $tomorrow . 'T18:00')) ?>"></label>
    <div class="full-width"><button type="submit">Сохранить смену</button></div>
</form>
<section class="panel"><h2>Предстоящие смены</h2>
    <?php if ($shifts === []): ?><p class="muted">Смен пока нет. Добавьте первую смену через форму выше.</p>
    <?php else: ?>
        <div class="table-wrapper"><table>
            <thead><tr><th>Мастер</th><th>Начало</th><th>Конец</th></tr></thead>
            <tbody><?php foreach ($shifts as $shift): ?>
                <tr><td><?= escapeHtml(masterFullName($shift)) ?></td><td class="nowrap"><?= escapeHtml($shift['starts_at']) ?></td><td class="nowrap"><?= escapeHtml($shift['ends_at']) ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
