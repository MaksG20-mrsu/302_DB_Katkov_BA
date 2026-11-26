<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/app.php';
$pageTitle = 'Работа станции техобслуживания';
$appointments = queryRows("SELECT a.id,a.starts_at,e.last_name,e.first_name,
    c.full_name,v.registration_number,group_concat(s.name, ', ') AS service_names
    FROM appointments a JOIN employees e ON e.id=a.employee_id
    JOIN vehicles v ON v.id=a.vehicle_id JOIN clients c ON c.id=v.client_id
    JOIN appointment_services aps ON aps.appointment_id=a.id
    JOIN services s ON s.id=aps.service_id
    WHERE a.status='booked' AND a.starts_at>=:now
    GROUP BY a.id,a.starts_at,e.last_name,e.first_name,c.full_name,v.registration_number
    ORDER BY a.starts_at,a.id LIMIT 15", ['now' => date('Y-m-d H:i:s')]);
require APP_ROOT . '/templates/header.php';
?>
<p class="lead">Добавляйте мастеров, задавайте индивидуальные смены и записывайте клиентов на свободное время.</p>
<div class="cards">
    <a class="card" href="masters.php"><span class="step-number">01</span><h2>Мастера и услуги</h2><p>Данные сотрудника и перечень работ, которые он выполняет.</p></a>
    <a class="card" href="schedule.php"><span class="step-number">02</span><h2>График работы</h2><p>Индивидуальные смены мастеров с проверкой пересечений.</p></a>
    <a class="card" href="booking.php"><span class="step-number">03</span><h2>Предварительная запись</h2><p>Услуга, подходящий мастер и доступное время приёма.</p></a>
</div>
<section class="panel">
    <h2>Ближайшие записи</h2>
    <?php if ($appointments === []): ?>
        <p class="muted">Предстоящих записей пока нет. Их можно добавить через форму предварительной записи.</p>
    <?php else: ?>
        <div class="table-wrapper"><table>
            <thead><tr><th>Номер</th><th>Начало</th><th>Мастер</th><th>Клиент</th><th>Автомобиль</th><th>Услуги</th></tr></thead>
            <tbody>
            <?php foreach ($appointments as $appointment): ?>
                <tr><td><?= escapeHtml($appointment['id']) ?></td><td class="nowrap"><?= escapeHtml($appointment['starts_at']) ?></td>
                    <td><?= escapeHtml(masterFullName($appointment)) ?></td><td><?= escapeHtml($appointment['full_name']) ?></td>
                    <td><?= escapeHtml($appointment['registration_number']) ?></td><td><?= escapeHtml($appointment['service_names']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
