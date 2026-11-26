<?php
declare(strict_types=1);

/** Общие функции консольного и веб-приложения. */
function databasePath(): string
{
    $customPath = getenv('STO_DB_PATH');
    return $customPath !== false && $customPath !== ''
        ? $customPath
        : __DIR__ . '/service_station.db';
}

function connectDatabase(): PDO
{
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('В PHP не включён драйвер pdo_sqlite.');
    }
    if (!is_file(databasePath())) {
        throw new RuntimeException('База не найдена. Сначала выполните php init_db.php.');
    }

    $database = new PDO('sqlite:' . databasePath(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $database->exec('PRAGMA foreign_keys = ON');
    return $database;
}

/** Включая уволенных мастеров: их выполненные работы остаются в базе. */
function fetchMasters(PDO $database): array
{
    $statement = $database->prepare(
        'SELECT id, last_name, first_name
         FROM employees
         ORDER BY last_name, first_name, id'
    );
    $statement->execute();
    return $statement->fetchAll();
}

function masterFullName(array $master): string
{
    return $master['last_name'] . ' ' . $master['first_name'];
}

/** Пустая строка — все мастера. Любой другой номер проверяется по данным БД. */
function parseMasterSelection(mixed $input, array $masters): ?int
{
    if (!is_string($input)) {
        throw new InvalidArgumentException('Номер мастера должен быть целым числом из списка.');
    }
    $input = trim($input);
    if ($input === '') {
        return null;
    }
    $masterId = filter_var($input, FILTER_VALIDATE_INT);
    if ($masterId === false) {
        throw new InvalidArgumentException('Введите целый номер мастера из списка.');
    }
    foreach ($masters as $master) {
        if ((int) $master['id'] === $masterId) {
            return $masterId;
        }
    }
    throw new InvalidArgumentException('Мастера с таким номером нет в базе.');
}

/** Источник — фактически выполненные работы, а не запланированные услуги. */
function fetchCompletedWorks(PDO $database, ?int $masterId): array
{
    $sql = 'SELECT e.id AS master_id, e.last_name, e.first_name,
                   w.performed_at, s.name AS service_name, w.price_cents
            FROM completed_works AS w
            JOIN appointments AS a ON a.id = w.appointment_id
            JOIN employees AS e ON e.id = a.employee_id
            JOIN services AS s ON s.id = w.service_id';
    if ($masterId !== null) {
        $sql .= ' WHERE e.id = :master_id';
    }
    $sql .= ' ORDER BY e.last_name, w.performed_at, e.first_name, e.id, w.service_id';

    $statement = $database->prepare($sql);
    if ($masterId !== null) {
        $statement->bindValue(':master_id', $masterId, PDO::PARAM_INT);
    }
    $statement->execute();
    return $statement->fetchAll();
}

function formatPrice(int $priceCents): string
{
    return number_format(intdiv($priceCents, 100), 0, ',', ' ')
        . ',' . str_pad((string) ($priceCents % 100), 2, '0', STR_PAD_LEFT);
}

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
