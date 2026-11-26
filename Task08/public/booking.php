<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/app.php';
$pageTitle = 'Предварительная запись';
$errorMessage = null;
$serviceId = null;
$employeeId = null;
$service = null;
$master = null;
$masters = [];
$slots = [];
$day = date('Y-m-d', strtotime('+1 day'));
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf($_POST);
    }
    $serviceId = optionalId($input['service_id'] ?? null, 'услуга');
    $employeeId = optionalId($input['employee_id'] ?? null, 'мастер');
    $day = bookingDate($input['day'] ?? $day);
    if ($serviceId !== null) {
        $service = selectedService($serviceId);
        $masters = mastersForService($serviceId, $day);
    }
    if ($employeeId !== null) {
        if ($serviceId === null) {
            throw new ValidationException('Сначала выберите услугу.');
        }
        foreach ($masters as $candidate) {
            if ((int) $candidate['id'] === $employeeId) {
                $master = $candidate;
                break;
            }
        }
        if ($master === null) {
            throw new ValidationException('Этот мастер не оказывает выбранную услугу или не работает в указанную дату.');
        }
        $slots = availableSlots($serviceId, $employeeId, $day);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $appointmentId = createAppointment($_POST);
        redirectWithMessage('booking.php?' . http_build_query(['service_id' => $serviceId, 'employee_id' => $employeeId, 'day' => $day]),
                            'Запись № ' . $appointmentId . ' сохранена. Время: ' . formValue('starts_at') . '.');
    }
} catch (ValidationException $error) {
    http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 422 : 400);
    $errorMessage = $error->getMessage();
} catch (PDOException $error) {
    http_response_code(422);
    $errorMessage = storageError($error);
}
$services = activeServices();
require APP_ROOT . '/templates/header.php';
?>
<p class="lead">Выберите услугу, затем подходящего мастера и свободное время. После этого укажите клиента и автомобиль.</p>
<?php if ($errorMessage !== null): ?><p class="error" role="alert"><?= escapeHtml($errorMessage) ?></p><?php endif; ?>
<section class="panel"><h2><span class="step-number">1</span> Услуга</h2>
    <form method="get" action="booking.php" class="inline-form">
        <label for="service_id">Нужная услуга</label>
        <select id="service_id" name="service_id" required>
            <option value="">Выберите услугу</option>
            <?php foreach ($services as $item): ?>
                <option value="<?= escapeHtml($item['id']) ?>"<?= $serviceId === (int) $item['id'] ? ' selected' : '' ?>>
                    <?= escapeHtml($item['name']) ?> · <?= escapeHtml($item['duration_minutes']) ?> мин · <?= escapeHtml(formatPrice((int) $item['price_cents'])) ?> руб.
                </option>
            <?php endforeach; ?>
        </select><button type="submit">Выбрать услугу</button>
    </form>
</section>
<?php if ($service !== null): ?>
<section class="panel"><h2><span class="step-number">2</span> Мастер</h2>
    <?php if ($masters === []): ?><p class="muted">На выбранную дату нет работающих мастеров, оказывающих эту услугу.</p>
    <?php else: ?>
    <form method="get" action="booking.php" class="inline-form">
        <input type="hidden" name="service_id" value="<?= escapeHtml($serviceId) ?>">
        <input type="hidden" name="day" value="<?= escapeHtml($day) ?>">
        <label for="employee_id">Мастер для услуги «<?= escapeHtml($service['name']) ?>»</label>
        <select id="employee_id" name="employee_id" required>
            <option value="">Выберите мастера</option>
            <?php foreach ($masters as $item): ?>
                <option value="<?= escapeHtml($item['id']) ?>"<?= $employeeId === (int) $item['id'] ? ' selected' : '' ?>>
                    № <?= escapeHtml($item['id']) ?> — <?= escapeHtml(masterFullName($item)) ?>
                </option>
            <?php endforeach; ?>
        </select><button type="submit">Выбрать мастера</button>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($service !== null && $master !== null): ?>
<section class="panel"><h2><span class="step-number">3</span> Свободное время</h2>
    <p><?= escapeHtml(masterFullName($master)) ?> · <?= escapeHtml($service['name']) ?> · <?= escapeHtml($service['duration_minutes']) ?> мин</p>
    <form method="get" action="booking.php" class="inline-form date-filter">
        <input type="hidden" name="service_id" value="<?= escapeHtml($serviceId) ?>">
        <input type="hidden" name="employee_id" value="<?= escapeHtml($employeeId) ?>">
        <label for="day">Дата приёма</label><input type="date" id="day" name="day" required min="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+90 days')) ?>" value="<?= escapeHtml($day) ?>">
        <button type="submit">Показать свободное время</button>
    </form>
    <?php if ($slots === []): ?><p class="muted">Свободного времени на эту дату нет. Выберите другую дату или мастера.</p>
    <?php else: ?>
    <form method="post" action="booking.php" class="form-grid booking-details">
        <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
        <input type="hidden" name="service_id" value="<?= escapeHtml($serviceId) ?>">
        <input type="hidden" name="employee_id" value="<?= escapeHtml($employeeId) ?>">
        <input type="hidden" name="day" value="<?= escapeHtml($day) ?>">
        <label class="full-width">Свободный интервал<select name="starts_at" required>
            <option value="">Выберите время</option>
            <?php foreach ($slots as $slot): ?>
                <option value="<?= escapeHtml($slot['starts_at']) ?>"<?= formValue('starts_at') === $slot['starts_at'] ? ' selected' : '' ?>><?= escapeHtml($slot['label']) ?></option>
            <?php endforeach; ?>
        </select></label>
        <label>Фамилия и имя клиента<input name="client_name" required maxlength="100" autocomplete="name" value="<?= escapeHtml(formValue('client_name')) ?>"></label>
        <label>Телефон клиента<input name="client_phone" type="tel" required maxlength="30" autocomplete="tel" value="<?= escapeHtml(formValue('client_phone')) ?>"></label>
        <label>Госномер автомобиля<input name="registration_number" required maxlength="20" value="<?= escapeHtml(formValue('registration_number')) ?>"></label>
        <label>Марка<input name="brand" required maxlength="60" value="<?= escapeHtml(formValue('brand')) ?>"></label>
        <label>Модель<input name="model" required maxlength="60" value="<?= escapeHtml(formValue('model')) ?>"></label>
        <p class="full-width muted">Стоимость услуги: <?= escapeHtml(formatPrice((int) $service['price_cents'])) ?> руб. Данные сохранятся после нажатия кнопки.</p>
        <div class="full-width"><button type="submit">Сохранить запись</button></div>
    </form>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php require APP_ROOT . '/templates/footer.php'; ?>
