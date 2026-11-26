<?php
declare(strict_types=1);
require_once __DIR__ . '/src/app.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$connection = null;
$created = false;
try {
    if (is_file(databasePath())) {
        queryRows('SELECT employee_id,service_id FROM master_services LIMIT 1');
        fwrite(STDOUT, "База уже существует. Имеющиеся данные сохранены.\n");
        exit(0);
    }
    $directory = dirname(databasePath());
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог базы.');
    }
    $sql = file_get_contents(__DIR__ . '/db_init.sql');
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Файл db_init.sql отсутствует или пуст.');
    }
    $connection = new PDO('sqlite:' . databasePath(), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $created = true;
    $connection->exec($sql);
    fwrite(STDOUT, "База СТО создана. Есть мастера, услуги и смены на ближайшие семь дней.\n");
} catch (Throwable $error) {
    $connection = null;
    if ($created && is_file(databasePath())) {
        unlink(databasePath());
    }
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . "\n");
    exit(1);
}
