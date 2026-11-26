<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$created = false;
$database = null;
try {
    if (is_file(databasePath())) {
        $database = connectDatabase();
        fetchMasters($database);
        fetchCompletedWorks($database, null);
        fwrite(STDOUT, "База уже существует и доступна. Имеющиеся данные сохранены.\n");
        exit(0);
    }

    $sql = file_get_contents(__DIR__ . '/db_init.sql');
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('Файл db_init.sql отсутствует или пуст.');
    }
    $database = new PDO('sqlite:' . databasePath(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $created = true;
    $database->exec($sql);
    fwrite(STDOUT, "База СТО создана из решения Task06.\n");
    fwrite(STDOUT, 'Мастеров: ' . count(fetchMasters($database)) . "\n");
    fwrite(STDOUT, 'Выполненных услуг: ' . count(fetchCompletedWorks($database, null)) . "\n");
} catch (Throwable $error) {
    if ($database !== null && $database->inTransaction()) {
        $database->rollBack();
    }
    $database = null;
    // Удаляется только новый файл, созданный этой неудачной инициализацией.
    if ($created && is_file(databasePath())) {
        unlink(databasePath());
    }
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . "\n");
    exit(1);
}
