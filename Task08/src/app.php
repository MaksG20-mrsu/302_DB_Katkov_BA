<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Moscow');
const APP_ROOT = __DIR__ . '/..';

if (PHP_SAPI !== 'cli') {
    $sessionDirectory = dirname(databasePath()) . '/sessions';
    if (!is_dir($sessionDirectory) && !mkdir($sessionDirectory, 0777, true) && !is_dir($sessionDirectory)) {
        throw new RuntimeException('Не удалось создать каталог сессий.');
    }
    session_save_path($sessionDirectory);
    if (!session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax'])) {
        throw new RuntimeException('Не удалось открыть сессию.');
    }
    header('Content-Type: text/html; charset=UTF-8');
}

class ValidationException extends RuntimeException {}

function escapeHtml(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function databasePath(): string
{
    $path = getenv('STO_DB_PATH');
    return $path !== false && $path !== '' ? $path : APP_ROOT . '/data/service_station.db';
}

function database(): PDO
{
    static $connection = null;
    if ($connection === null) {
        if (!is_file(databasePath())) {
            throw new RuntimeException('База отсутствует. Выполните php init_db.php из каталога Task08.');
        }
        $connection = new PDO('sqlite:' . databasePath(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $connection->exec('PRAGMA foreign_keys = ON');
        $connection->exec('PRAGMA busy_timeout = 3000');
    }
    return $connection;
}

function queryRows(string $sql, array $parameters = []): array
{
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function executeSql(string $sql, array $parameters = []): void
{
    $statement = database()->prepare($sql);
    $statement->execute($parameters);
}

/** Проверки и изменения выполняются под одной блокировкой записи SQLite. */
function writeTransaction(callable $operation): mixed
{
    database()->exec('BEGIN IMMEDIATE');
    try {
        $result = $operation();
        database()->exec('COMMIT');
        return $result;
    } catch (Throwable $error) {
        database()->exec('ROLLBACK');
        throw $error;
    }
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(array $input): void
{
    $token = $input['csrf_token'] ?? null;
    if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
        throw new ValidationException('Форма устарела. Обновите страницу и повторите ввод.');
    }
}

function redirectWithMessage(string $location, string $message): never
{
    $_SESSION['message'] = $message;
    header('Location: ' . $location, true, 303);
    exit;
}

function formValue(string $name, string $default = ''): string
{
    $value = $_POST[$name] ?? $default;
    return is_string($value) ? $value : $default;
}

function inputText(array $input, string $key, string $label, int $maxLength = 100): string
{
    $value = $input[$key] ?? null;
    if (!is_string($value)) {
        throw new ValidationException('Заполните поле «' . $label . '».');
    }
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ($value === '' || $length === false || $length > $maxLength
        || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
        throw new ValidationException('Проверьте поле «' . $label . '» (от 1 до ' . $maxLength . ' символов).');
    }
    return $value;
}

function inputPhone(array $input, string $key): string
{
    $phone = inputText($input, $key, 'Телефон', 30);
    if (!preg_match('/^\+?[0-9 ()-]+$/D', $phone)) {
        throw new ValidationException('Телефон должен содержать цифры, пробелы, скобки или дефисы.');
    }
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        throw new ValidationException('В телефоне должно быть от 10 до 15 цифр.');
    }
    return '+' . $digits;
}

function optionalId(mixed $value, string $label): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_string($value) && !is_int($value)) {
        throw new ValidationException('Некорректное значение: ' . $label . '.');
    }
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        throw new ValidationException('Некорректное значение: ' . $label . '.');
    }
    return $id;
}

function requiredId(mixed $value, string $label): int
{
    $id = optionalId($value, $label);
    if ($id === null) {
        throw new ValidationException('Выберите: ' . $label . '.');
    }
    return $id;
}

function validDate(mixed $value): string
{
    if (!is_string($value)) {
        throw new ValidationException('Укажите корректную дату.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if ($date === false || $date->format('Y-m-d') !== $value) {
        throw new ValidationException('Укажите корректную дату.');
    }
    return $value;
}

function bookingDate(mixed $value): string
{
    $day = validDate($value);
    if ($day < date('Y-m-d') || $day > date('Y-m-d', strtotime('+90 days'))) {
        throw new ValidationException('Для записи выберите дату в ближайшие 90 дней, начиная с сегодняшнего дня.');
    }
    return $day;
}

function inputDateTime(array $input, string $key): string
{
    $value = $input[$key] ?? null;
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value) : false;
    if ($date === false || $date->format('Y-m-d\TH:i') !== $value) {
        throw new ValidationException('Укажите корректные дату и время смены.');
    }
    return $date->format('Y-m-d H:i:s');
}

function formatPrice(int $cents): string
{
    return number_format(intdiv($cents, 100), 0, ',', ' ')
        . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function activeServices(): array
{
    return queryRows('SELECT * FROM services WHERE is_active = 1 ORDER BY name, id');
}

function activeMasters(?string $day = null): array
{
    return queryRows('SELECT * FROM employees WHERE hired_on <= :day
                     AND (dismissed_on IS NULL OR dismissed_on > :day)
                     ORDER BY last_name, first_name, id', ['day' => $day ?? date('Y-m-d')]);
}

function masterFullName(array $master): string
{
    return $master['last_name'] . ' ' . $master['first_name'];
}

function mastersForService(int $serviceId, string $day): array
{
    return queryRows('SELECT e.* FROM employees e
                     JOIN master_services ms ON ms.employee_id = e.id
                     JOIN services s ON s.id = ms.service_id
                     WHERE ms.service_id = :service AND s.is_active = 1
                       AND e.hired_on <= :day AND (e.dismissed_on IS NULL OR e.dismissed_on > :day)
                     ORDER BY e.last_name, e.first_name, e.id', ['service' => $serviceId, 'day' => $day]);
}

function selectedService(int $serviceId): array
{
    $rows = queryRows('SELECT * FROM services WHERE id = :id AND is_active = 1', ['id' => $serviceId]);
    if ($rows === []) {
        throw new ValidationException('Выбранная услуга недоступна.');
    }
    return $rows[0];
}

/** Генерирует интервалы по 15 минут с учётом длительности выбранной услуги. */
function availableSlots(int $serviceId, int $employeeId, string $day): array
{
    $day = bookingDate($day);
    $service = selectedService($serviceId);
    $eligible = array_column(mastersForService($serviceId, $day), 'id');
    if (!in_array($employeeId, array_map('intval', $eligible), true)) {
        throw new ValidationException('Этот мастер не оказывает выбранную услугу или не работает в указанную дату.');
    }
    $dayStart = new DateTimeImmutable($day . ' 00:00:00');
    $dayEnd = $dayStart->modify('+1 day');
    $parameters = ['employee' => $employeeId, 'day_start' => $dayStart->format('Y-m-d H:i:s'),
                   'day_end' => $dayEnd->format('Y-m-d H:i:s')];
    $shifts = queryRows('SELECT starts_at, ends_at FROM work_shifts
                        WHERE employee_id = :employee AND starts_at < :day_end AND ends_at > :day_start
                        ORDER BY starts_at', $parameters);
    $appointments = queryRows("SELECT starts_at, ends_at FROM appointments
                              WHERE employee_id = :employee AND status <> 'cancelled'
                              AND starts_at < :day_end AND ends_at > :day_start", $parameters);
    $duration = (int) $service['duration_minutes'] * 60;
    $now = time();
    $slots = [];
    foreach ($shifts as $shift) {
        $shiftStart = new DateTimeImmutable($shift['starts_at']);
        $shiftEnd = new DateTimeImmutable($shift['ends_at']);
        for ($start = $shiftStart; $start < $dayEnd; $start = $start->modify('+15 minutes')) {
            $end = $start->modify('+' . $duration . ' seconds');
            if ($start < $dayStart || $start->getTimestamp() <= $now || $end > $shiftEnd) {
                continue;
            }
            $startText = $start->format('Y-m-d H:i:s');
            $endText = $end->format('Y-m-d H:i:s');
            $busy = false;
            foreach ($appointments as $appointment) {
                if ($startText < $appointment['ends_at'] && $endText > $appointment['starts_at']) {
                    $busy = true;
                    break;
                }
            }
            if (!$busy) {
                $slots[$startText] = ['starts_at' => $startText, 'ends_at' => $endText,
                    'label' => $start->format('H:i') . ' — ' . $end->format('H:i')];
            }
        }
    }
    ksort($slots);
    return array_values($slots);
}

function createMaster(array $input): int
{
    $lastName = inputText($input, 'last_name', 'Фамилия');
    $firstName = inputText($input, 'first_name', 'Имя');
    $phone = inputPhone($input, 'phone');
    $hiredOn = validDate($input['hired_on'] ?? null);
    if ($hiredOn > date('Y-m-d')) {
        throw new ValidationException('Для работающего мастера дата приёма не может быть в будущем.');
    }
    $percentText = inputText($input, 'commission_percent', 'Процент зарплаты', 6);
    $percentText = str_replace(',', '.', $percentText);
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/D', $percentText) || (float) $percentText > 100) {
        throw new ValidationException('Процент зарплаты должен быть числом от 0 до 100.');
    }
    $rawServices = $input['service_ids'] ?? [];
    if (!is_array($rawServices) || $rawServices === []) {
        throw new ValidationException('Выберите хотя бы одну услугу мастера.');
    }
    $serviceIds = [];
    $allowed = array_map('intval', array_column(activeServices(), 'id'));
    foreach ($rawServices as $value) {
        $id = requiredId($value, 'услуга');
        if (!in_array($id, $allowed, true)) {
            throw new ValidationException('Одна из выбранных услуг недоступна.');
        }
        $serviceIds[$id] = $id;
    }
    return writeTransaction(function () use ($lastName, $firstName, $phone, $hiredOn, $percentText, $serviceIds): int {
        executeSql('INSERT INTO employees(last_name,first_name,phone,hired_on,commission_percent)
                    VALUES(:last,:first,:phone,:hired,:percent)',
                    ['last' => $lastName, 'first' => $firstName, 'phone' => $phone,
                     'hired' => $hiredOn, 'percent' => (float) $percentText]);
        $employeeId = (int) database()->lastInsertId();
        foreach ($serviceIds as $serviceId) {
            executeSql('INSERT INTO master_services(employee_id,service_id) VALUES(:employee,:service)',
                       ['employee' => $employeeId, 'service' => $serviceId]);
        }
        return $employeeId;
    });
}

function createShift(array $input): void
{
    $employeeId = requiredId($input['employee_id'] ?? null, 'мастера');
    if (!in_array($employeeId, array_map('intval', array_column(activeMasters(), 'id')), true)) {
        throw new ValidationException('Выберите работающего мастера из списка.');
    }
    $startsAt = inputDateTime($input, 'starts_at');
    $endsAt = inputDateTime($input, 'ends_at');
    if ($endsAt <= $startsAt || $startsAt <= date('Y-m-d H:i:s')) {
        throw new ValidationException('Начало смены должно быть в будущем, конец — позже начала.');
    }
    executeSql('INSERT INTO work_shifts(employee_id,starts_at,ends_at) VALUES(:employee,:start,:end)',
               ['employee' => $employeeId, 'start' => $startsAt, 'end' => $endsAt]);
}

function createAppointment(array $input): int
{
    $serviceId = requiredId($input['service_id'] ?? null, 'услугу');
    $employeeId = requiredId($input['employee_id'] ?? null, 'мастера');
    $day = bookingDate($input['day'] ?? null);
    $startsAt = inputText($input, 'starts_at', 'Свободное время', 19);
    $clientName = inputText($input, 'client_name', 'Имя клиента');
    $phone = inputPhone($input, 'client_phone');
    $plate = inputText($input, 'registration_number', 'Госномер', 20);
    $brand = inputText($input, 'brand', 'Марка автомобиля', 60);
    $model = inputText($input, 'model', 'Модель автомобиля', 60);

    return writeTransaction(function () use ($serviceId, $employeeId, $day, $startsAt, $clientName, $phone, $plate, $brand, $model): int {
        $service = selectedService($serviceId);
        $slots = availableSlots($serviceId, $employeeId, $day);
        $slot = null;
        foreach ($slots as $candidate) {
            if ($candidate['starts_at'] === $startsAt) {
                $slot = $candidate;
                break;
            }
        }
        if ($slot === null) {
            throw new ValidationException('Выбранное время недоступно. Обновите список свободного времени.');
        }
        $clients = queryRows('SELECT id FROM clients WHERE phone = :phone', ['phone' => $phone]);
        if ($clients === []) {
            executeSql('INSERT INTO clients(full_name,phone) VALUES(:name,:phone)', ['name' => $clientName, 'phone' => $phone]);
            $clientId = (int) database()->lastInsertId();
        } else {
            $clientId = (int) $clients[0]['id'];
        }
        $vehicles = queryRows('SELECT id,client_id FROM vehicles WHERE registration_number = :plate', ['plate' => $plate]);
        if ($vehicles === []) {
            executeSql('INSERT INTO vehicles(client_id,registration_number,brand,model) VALUES(:client,:plate,:brand,:model)',
                       ['client' => $clientId, 'plate' => $plate, 'brand' => $brand, 'model' => $model]);
            $vehicleId = (int) database()->lastInsertId();
        } else {
            if ((int) $vehicles[0]['client_id'] !== $clientId) {
                throw new ValidationException('Этот автомобиль уже связан с другим телефоном клиента. Проверьте данные.');
            }
            $vehicleId = (int) $vehicles[0]['id'];
        }
        executeSql('INSERT INTO appointments(employee_id,vehicle_id,starts_at,ends_at) VALUES(:employee,:vehicle,:start,:end)',
                   ['employee' => $employeeId, 'vehicle' => $vehicleId, 'start' => $slot['starts_at'], 'end' => $slot['ends_at']]);
        $appointmentId = (int) database()->lastInsertId();
        executeSql('INSERT INTO appointment_services(appointment_id,service_id,agreed_price_cents,planned_minutes)
                    VALUES(:appointment,:service,:price,:minutes)',
                   ['appointment' => $appointmentId, 'service' => $serviceId,
                    'price' => $service['price_cents'], 'minutes' => $service['duration_minutes']]);
        return $appointmentId;
    });
}

function storageError(PDOException $error): string
{
    error_log($error->getMessage());
    $message = $error->getMessage();
    return match (true) {
        str_contains($message, 'employees.phone') => 'Мастер с таким телефоном уже есть в базе.',
        str_contains($message, 'shifts overlap') => 'У мастера уже есть смена, пересекающаяся с указанным временем.',
        str_contains($message, 'employment dates') => 'В указанный период мастер не работает в организации.',
        str_contains($message, 'Vehicle already') => 'Автомобиль уже записан на это время.',
        str_contains($message, 'already has an appointment') => 'Это время мастера уже занято. Выберите другое.',
        str_contains($message, 'does not provide') => 'Мастер не оказывает выбранную услугу.',
        default => 'Не удалось сохранить данные. Проверьте введённые значения и повторите попытку.',
    };
}
