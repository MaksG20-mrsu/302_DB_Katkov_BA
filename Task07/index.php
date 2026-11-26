<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

header('Content-Type: text/html; charset=UTF-8');
$masters = [];
$works = [];
$masterId = null;
$errorMessage = null;
try {
    $database = connectDatabase();
    $masters = fetchMasters($database);
    $masterId = parseMasterSelection($_GET['master_id'] ?? '', $masters);
    $works = fetchCompletedWorks($database, $masterId);
} catch (InvalidArgumentException $error) {
    http_response_code(400);
    $errorMessage = $error->getMessage();
} catch (Throwable $error) {
    http_response_code(500);
    error_log($error->getMessage());
    $errorMessage = 'Не удалось загрузить данные СТО.';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Оказанные услуги СТО</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="page">
    <header class="page-header">
        <p class="eyebrow">Станция технического обслуживания</p>
        <h1>Оказанные услуги</h1>
        <p>Фактически выполненные работы мастеров СТО.</p>
    </header>

    <form method="get" action="index.php" class="filter">
        <label for="master_id">Мастер</label>
        <select id="master_id" name="master_id">
            <option value=""<?= $masterId === null ? ' selected' : '' ?>>Все мастера</option>
            <?php foreach ($masters as $master): ?>
                <option value="<?= escapeHtml($master['id']) ?>"<?= $masterId === (int) $master['id'] ? ' selected' : '' ?>>
                    № <?= escapeHtml($master['id']) ?> — <?= escapeHtml(masterFullName($master)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Показать</button>
        <a href="index.php" class="reset-link">Сбросить</a>
    </form>

    <?php if ($errorMessage !== null): ?>
        <p class="error" role="alert"><?= escapeHtml($errorMessage) ?></p>
    <?php else: ?>
        <p class="summary">Выполненных услуг: <strong><?= count($works) ?></strong></p>
        <div class="table-wrapper">
            <table>
                <caption>Услуги по фамилии мастера и дате выполнения работы</caption>
                <thead>
                <tr>
                    <th scope="col">Номер мастера</th>
                    <th scope="col">ФИО мастера</th>
                    <th scope="col">Дата работы</th>
                    <th scope="col">Услуга</th>
                    <th scope="col" class="price">Стоимость, руб.</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($works === []): ?>
                    <tr><td colspan="5" class="empty">По выбранному условию выполненных услуг нет.</td></tr>
                <?php else: ?>
                    <?php foreach ($works as $work): ?>
                        <tr>
                            <td><?= escapeHtml($work['master_id']) ?></td>
                            <td><?= escapeHtml(masterFullName($work)) ?></td>
                            <td class="date"><?= escapeHtml($work['performed_at']) ?></td>
                            <td><?= escapeHtml($work['service_name']) ?></td>
                            <td class="price"><?= escapeHtml(formatPrice((int) $work['price_cents'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
