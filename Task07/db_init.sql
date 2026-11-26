-- Лабораторная работа 6. Катков Богдан.
-- База данных станции технического обслуживания автомобилей.
-- Запуск: sqlite3 -bail service_station.db < db_init.sql
-- Повторный запуск пересоздаёт таблицы с демонстрационными данными.
-- PRAGMA foreign_keys = ON необходимо выполнять в каждом подключении SQLite.
-- День увольнения — первый день, когда сотрудник уже не работает.
-- Интервалы записи и смен: [начало, конец), поэтому соседние записи допустимы.
-- Деньги хранятся целым числом копеек. Дата и время: YYYY-MM-DD HH:MM:SS.

PRAGMA foreign_keys = ON;
BEGIN TRANSACTION;

DROP VIEW IF EXISTS work_income;
DROP VIEW IF EXISTS employee_totals;
DROP TABLE IF EXISTS completed_works;
DROP TABLE IF EXISTS appointment_services;
DROP TABLE IF EXISTS appointments;
DROP TABLE IF EXISTS work_shifts;
DROP TABLE IF EXISTS vehicles;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS employees;

CREATE TABLE employees (
    id                 INTEGER PRIMARY KEY,
    last_name          TEXT NOT NULL CHECK (length(trim(last_name)) > 0),
    first_name         TEXT NOT NULL CHECK (length(trim(first_name)) > 0),
    phone              TEXT NOT NULL UNIQUE CHECK (length(trim(phone)) > 0),
    hired_on           TEXT NOT NULL DEFAULT (date('now'))
                       CHECK (date(hired_on, '+0 days') IS NOT NULL
                              AND date(hired_on, '+0 days') = hired_on),
    dismissed_on       TEXT CHECK (dismissed_on IS NULL OR (
                           date(dismissed_on, '+0 days') IS NOT NULL
                           AND date(dismissed_on, '+0 days') = dismissed_on
                           AND dismissed_on >= hired_on)),
    commission_percent REAL NOT NULL DEFAULT 30
                       CHECK (typeof(commission_percent) IN ('integer', 'real')
                              AND commission_percent BETWEEN 0 AND 100)
);

CREATE TABLE clients (
    id        INTEGER PRIMARY KEY,
    full_name TEXT NOT NULL CHECK (length(trim(full_name)) > 0),
    phone     TEXT NOT NULL UNIQUE CHECK (length(trim(phone)) > 0)
);

CREATE TABLE vehicles (
    id                  INTEGER PRIMARY KEY,
    client_id           INTEGER NOT NULL,
    registration_number TEXT NOT NULL COLLATE NOCASE UNIQUE
                        CHECK (length(trim(registration_number)) > 0),
    brand               TEXT NOT NULL CHECK (length(trim(brand)) > 0),
    model               TEXT NOT NULL CHECK (length(trim(model)) > 0),
    FOREIGN KEY (client_id) REFERENCES clients(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE services (
    id               INTEGER PRIMARY KEY,
    name             TEXT NOT NULL UNIQUE CHECK (length(trim(name)) > 0),
    duration_minutes INTEGER NOT NULL CHECK (
                         typeof(duration_minutes) = 'integer' AND duration_minutes > 0),
    price_cents      INTEGER NOT NULL CHECK (
                         typeof(price_cents) = 'integer' AND price_cents >= 0),
    is_active        INTEGER NOT NULL DEFAULT 1 CHECK (is_active IN (0, 1))
);

CREATE TABLE work_shifts (
    id          INTEGER PRIMARY KEY,
    employee_id INTEGER NOT NULL,
    starts_at   TEXT NOT NULL CHECK (
                    datetime(starts_at, '+0 seconds') IS NOT NULL
                    AND datetime(starts_at, '+0 seconds') = starts_at),
    ends_at     TEXT NOT NULL CHECK (
                    datetime(ends_at, '+0 seconds') IS NOT NULL
                    AND datetime(ends_at, '+0 seconds') = ends_at
                    AND ends_at > starts_at),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE TABLE appointments (
    id          INTEGER PRIMARY KEY,
    employee_id INTEGER NOT NULL,
    vehicle_id  INTEGER NOT NULL,
    starts_at   TEXT NOT NULL CHECK (
                    datetime(starts_at, '+0 seconds') IS NOT NULL
                    AND datetime(starts_at, '+0 seconds') = starts_at),
    ends_at     TEXT NOT NULL CHECK (
                    datetime(ends_at, '+0 seconds') IS NOT NULL
                    AND datetime(ends_at, '+0 seconds') = ends_at
                    AND ends_at > starts_at),
    status      TEXT NOT NULL DEFAULT 'booked'
                CHECK (status IN ('booked', 'completed', 'cancelled')),
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (employee_id) REFERENCES employees(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

-- Состав выбранных услуг. Цена и длительность фиксируются при записи,
-- поэтому последующее изменение справочника не меняет старую запись.
CREATE TABLE appointment_services (
    appointment_id  INTEGER NOT NULL,
    service_id      INTEGER NOT NULL,
    agreed_price_cents INTEGER NOT NULL CHECK (
                           typeof(agreed_price_cents) = 'integer' AND agreed_price_cents >= 0),
    planned_minutes INTEGER NOT NULL CHECK (
                        typeof(planned_minutes) = 'integer' AND planned_minutes > 0),
    PRIMARY KEY (appointment_id, service_id),
    FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    FOREIGN KEY (service_id) REFERENCES services(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

-- Факт выполнения выбранной услуги. Отсутствие строки означает, что услуга
-- ещё не выполнена. У каждой выбранной услуги может быть не более одного факта.
-- price_cents и commission_percent — фактические условия выполненной работы.
-- Они сохраняются отдельно от текущего прайса и текущего процента сотрудника.
CREATE TABLE completed_works (
    appointment_id    INTEGER NOT NULL,
    service_id        INTEGER NOT NULL,
    performed_at      TEXT NOT NULL DEFAULT (datetime('now')) CHECK (
                          datetime(performed_at, '+0 seconds') IS NOT NULL
                          AND datetime(performed_at, '+0 seconds') = performed_at),
    price_cents       INTEGER NOT NULL CHECK (
                          typeof(price_cents) = 'integer' AND price_cents >= 0),
    commission_percent REAL NOT NULL CHECK (
                           typeof(commission_percent) IN ('integer', 'real')
                           AND commission_percent BETWEEN 0 AND 100),
    PRIMARY KEY (appointment_id, service_id),
    FOREIGN KEY (appointment_id, service_id)
        REFERENCES appointment_services(appointment_id, service_id)
        ON UPDATE CASCADE ON DELETE RESTRICT
);

CREATE INDEX idx_employees_last_name ON employees(last_name);
CREATE INDEX idx_vehicles_client ON vehicles(client_id);
CREATE INDEX idx_shifts_employee_time ON work_shifts(employee_id, starts_at, ends_at);
CREATE INDEX idx_appointments_employee_time ON appointments(employee_id, starts_at, ends_at);
CREATE INDEX idx_appointments_vehicle_time ON appointments(vehicle_id, starts_at, ends_at);
CREATE INDEX idx_appointment_services_service ON appointment_services(service_id);
CREATE INDEX idx_completed_works_performed ON completed_works(performed_at);

-- Нельзя задать пересекающиеся смены одному сотруднику.
CREATE TRIGGER shifts_check_insert BEFORE INSERT ON work_shifts
BEGIN
    SELECT RAISE(ABORT, 'Shift is outside employment dates')
    WHERE NOT EXISTS (
        SELECT 1 FROM employees e WHERE e.id = NEW.employee_id
        AND NEW.starts_at >= e.hired_on || ' 00:00:00'
        AND (e.dismissed_on IS NULL OR NEW.ends_at <= e.dismissed_on || ' 00:00:00')
    );
    SELECT RAISE(ABORT, 'Employee shifts overlap') WHERE EXISTS (
        SELECT 1 FROM work_shifts s WHERE s.employee_id = NEW.employee_id
        AND NEW.starts_at < s.ends_at AND NEW.ends_at > s.starts_at
    );
END;

CREATE TRIGGER shifts_check_update BEFORE UPDATE ON work_shifts
BEGIN
    SELECT RAISE(ABORT, 'Shift is outside employment dates')
    WHERE NOT EXISTS (
        SELECT 1 FROM employees e WHERE e.id = NEW.employee_id
        AND NEW.starts_at >= e.hired_on || ' 00:00:00'
        AND (e.dismissed_on IS NULL OR NEW.ends_at <= e.dismissed_on || ' 00:00:00')
    );
    SELECT RAISE(ABORT, 'Employee shifts overlap') WHERE EXISTS (
        SELECT 1 FROM work_shifts s WHERE s.employee_id = NEW.employee_id AND s.id <> OLD.id
        AND NEW.starts_at < s.ends_at AND NEW.ends_at > s.starts_at
    );
    SELECT RAISE(ABORT, 'Shift change would leave an appointment outside the schedule')
    WHERE EXISTS (
        SELECT 1 FROM appointments a WHERE a.employee_id = OLD.employee_id
        AND a.status <> 'cancelled' AND a.starts_at >= OLD.starts_at AND a.ends_at <= OLD.ends_at
        AND NOT (NEW.employee_id = OLD.employee_id
                 AND a.starts_at >= NEW.starts_at AND a.ends_at <= NEW.ends_at)
    );
END;

CREATE TRIGGER shifts_check_delete BEFORE DELETE ON work_shifts
BEGIN
    SELECT RAISE(ABORT, 'Shift contains appointments') WHERE EXISTS (
        SELECT 1 FROM appointments a WHERE a.employee_id = OLD.employee_id
        AND a.status <> 'cancelled' AND a.starts_at >= OLD.starts_at AND a.ends_at <= OLD.ends_at
    );
END;

-- Проверка записи: трудоустройство, рабочая смена, занятость мастера и автомобиля.
CREATE TRIGGER appointments_check_insert BEFORE INSERT ON appointments
WHEN NEW.status <> 'cancelled'
BEGIN
    SELECT RAISE(ABORT, 'Appointment is outside employment dates')
    WHERE NOT EXISTS (
        SELECT 1 FROM employees e WHERE e.id = NEW.employee_id
        AND NEW.starts_at >= e.hired_on || ' 00:00:00'
        AND (e.dismissed_on IS NULL OR NEW.ends_at <= e.dismissed_on || ' 00:00:00')
    );
    SELECT RAISE(ABORT, 'Appointment is outside the work schedule') WHERE NOT EXISTS (
        SELECT 1 FROM work_shifts s WHERE s.employee_id = NEW.employee_id
        AND s.starts_at <= NEW.starts_at AND s.ends_at >= NEW.ends_at
    );
    SELECT RAISE(ABORT, 'Employee already has an appointment') WHERE EXISTS (
        SELECT 1 FROM appointments a WHERE a.employee_id = NEW.employee_id
        AND a.status <> 'cancelled' AND NEW.starts_at < a.ends_at AND NEW.ends_at > a.starts_at
    );
    SELECT RAISE(ABORT, 'Vehicle already has an appointment') WHERE EXISTS (
        SELECT 1 FROM appointments a WHERE a.vehicle_id = NEW.vehicle_id
        AND a.status <> 'cancelled' AND NEW.starts_at < a.ends_at AND NEW.ends_at > a.starts_at
    );
    SELECT RAISE(ABORT, 'Create an appointment as booked, then record the completed work')
    WHERE NEW.status = 'completed';
END;

CREATE TRIGGER appointments_check_update BEFORE UPDATE ON appointments
BEGIN
    SELECT RAISE(ABORT, 'Completed work cannot be reassigned or cancelled')
    WHERE EXISTS (SELECT 1 FROM completed_works w WHERE w.appointment_id = OLD.id)
    AND (NEW.employee_id <> OLD.employee_id OR NEW.vehicle_id <> OLD.vehicle_id
         OR NEW.starts_at <> OLD.starts_at OR NEW.ends_at <> OLD.ends_at OR NEW.status = 'cancelled');
    SELECT RAISE(ABORT, 'Appointment is outside employment dates')
    WHERE NEW.status <> 'cancelled' AND NOT EXISTS (
        SELECT 1 FROM employees e WHERE e.id = NEW.employee_id
        AND NEW.starts_at >= e.hired_on || ' 00:00:00'
        AND (e.dismissed_on IS NULL OR NEW.ends_at <= e.dismissed_on || ' 00:00:00')
    );
    SELECT RAISE(ABORT, 'Appointment is outside the work schedule')
    WHERE NEW.status <> 'cancelled' AND NOT EXISTS (
        SELECT 1 FROM work_shifts s WHERE s.employee_id = NEW.employee_id
        AND s.starts_at <= NEW.starts_at AND s.ends_at >= NEW.ends_at
    );
    SELECT RAISE(ABORT, 'Employee already has an appointment')
    WHERE NEW.status <> 'cancelled' AND EXISTS (
        SELECT 1 FROM appointments a WHERE a.employee_id = NEW.employee_id AND a.id <> OLD.id
        AND a.status <> 'cancelled' AND NEW.starts_at < a.ends_at AND NEW.ends_at > a.starts_at
    );
    SELECT RAISE(ABORT, 'Vehicle already has an appointment')
    WHERE NEW.status <> 'cancelled' AND EXISTS (
        SELECT 1 FROM appointments a WHERE a.vehicle_id = NEW.vehicle_id AND a.id <> OLD.id
        AND a.status <> 'cancelled' AND NEW.starts_at < a.ends_at AND NEW.ends_at > a.starts_at
    );
    SELECT RAISE(ABORT, 'Selected services do not fit the appointment')
    WHERE COALESCE((SELECT SUM(planned_minutes) FROM appointment_services
                    WHERE appointment_id = OLD.id), 0) * 60 >
          CAST(strftime('%s', NEW.ends_at) AS INTEGER) - CAST(strftime('%s', NEW.starts_at) AS INTEGER);
    SELECT RAISE(ABORT, 'Some selected services have not been completed')
    WHERE NEW.status = 'completed' AND (
        NOT EXISTS (SELECT 1 FROM appointment_services s WHERE s.appointment_id = OLD.id)
        OR EXISTS (SELECT 1 FROM appointment_services s WHERE s.appointment_id = OLD.id
                   AND NOT EXISTS (SELECT 1 FROM completed_works w
                       WHERE w.appointment_id = s.appointment_id AND w.service_id = s.service_id))
    );
END;

CREATE TRIGGER selected_services_check_insert BEFORE INSERT ON appointment_services
BEGIN
    SELECT RAISE(ABORT, 'Only booked appointments can receive selected services')
    WHERE NOT EXISTS (SELECT 1 FROM appointments a WHERE a.id = NEW.appointment_id AND a.status = 'booked');
    SELECT RAISE(ABORT, 'Service is inactive')
    WHERE NOT EXISTS (SELECT 1 FROM services s WHERE s.id = NEW.service_id AND s.is_active = 1);
    SELECT RAISE(ABORT, 'Selected services do not fit the appointment') WHERE (
        COALESCE((SELECT SUM(planned_minutes) FROM appointment_services
                  WHERE appointment_id = NEW.appointment_id), 0) + NEW.planned_minutes
    ) * 60 > (SELECT CAST(strftime('%s', a.ends_at) AS INTEGER) - CAST(strftime('%s', a.starts_at) AS INTEGER)
              FROM appointments a WHERE a.id = NEW.appointment_id);
END;

CREATE TRIGGER selected_services_check_update BEFORE UPDATE ON appointment_services
BEGIN
    SELECT RAISE(ABORT, 'Completed service agreement cannot be changed')
    WHERE EXISTS (SELECT 1 FROM completed_works w
                  WHERE w.appointment_id = OLD.appointment_id AND w.service_id = OLD.service_id);
    SELECT RAISE(ABORT, 'Only booked appointments can change selected services')
    WHERE NOT EXISTS (SELECT 1 FROM appointments a WHERE a.id = NEW.appointment_id AND a.status = 'booked');
    SELECT RAISE(ABORT, 'Service is inactive')
    WHERE NOT EXISTS (SELECT 1 FROM services s WHERE s.id = NEW.service_id AND s.is_active = 1);
    SELECT RAISE(ABORT, 'Selected services do not fit the appointment') WHERE (
        COALESCE((SELECT SUM(planned_minutes) FROM appointment_services
                  WHERE appointment_id = NEW.appointment_id
                    AND NOT (appointment_id = OLD.appointment_id AND service_id = OLD.service_id)), 0)
        + NEW.planned_minutes
    ) * 60 > (SELECT CAST(strftime('%s', a.ends_at) AS INTEGER) - CAST(strftime('%s', a.starts_at) AS INTEGER)
              FROM appointments a WHERE a.id = NEW.appointment_id);
END;

CREATE TRIGGER completed_works_check_insert BEFORE INSERT ON completed_works
BEGIN
    SELECT RAISE(ABORT, 'Appointment is cancelled or does not exist')
    WHERE NOT EXISTS (SELECT 1 FROM appointments a WHERE a.id = NEW.appointment_id AND a.status <> 'cancelled');
    SELECT RAISE(ABORT, 'Work is outside employment dates') WHERE NOT EXISTS (
        SELECT 1 FROM appointments a JOIN employees e ON e.id = a.employee_id
        WHERE a.id = NEW.appointment_id AND NEW.performed_at >= e.hired_on || ' 00:00:00'
        AND (e.dismissed_on IS NULL OR NEW.performed_at < e.dismissed_on || ' 00:00:00')
    );
    SELECT RAISE(ABORT, 'Work cannot precede the appointment') WHERE
        NEW.performed_at < (SELECT starts_at FROM appointments WHERE id = NEW.appointment_id);
    SELECT RAISE(ABORT, 'Use the employee commission current when recording the work') WHERE
        NEW.commission_percent <> (SELECT e.commission_percent FROM employees e
            JOIN appointments a ON a.employee_id = e.id WHERE a.id = NEW.appointment_id);
END;

-- История фактически выполненных работ не удаляется и не переписывается.
CREATE TRIGGER completed_works_no_update BEFORE UPDATE ON completed_works
BEGIN
    SELECT RAISE(ABORT, 'Completed work is historical and cannot be updated');
END;
CREATE TRIGGER completed_works_no_delete BEFORE DELETE ON completed_works
BEGIN
    SELECT RAISE(ABORT, 'Completed work is historical and cannot be deleted');
END;

-- Представление содержит только фактически выполненные работы, а не все записи.
CREATE VIEW work_income AS
SELECT w.appointment_id, w.service_id, a.employee_id, w.performed_at,
       w.price_cents AS revenue_cents, w.commission_percent,
       CAST(round(w.price_cents * w.commission_percent / 100.0) AS INTEGER) AS salary_cents
FROM completed_works w JOIN appointments a ON a.id = w.appointment_id;

CREATE VIEW employee_totals AS
SELECT e.id AS employee_id, e.last_name, e.first_name, e.hired_on, e.dismissed_on,
       COUNT(w.service_id) AS completed_count,
       COALESCE(SUM(w.revenue_cents), 0) AS revenue_cents,
       COALESCE(SUM(w.salary_cents), 0) AS salary_cents
FROM employees e LEFT JOIN work_income w ON w.employee_id = e.id
GROUP BY e.id, e.last_name, e.first_name, e.hired_on, e.dismissed_on;

-- Демонстрационные данные. Все телефоны и регистрационные номера учебные.
INSERT INTO employees (id, last_name, first_name, phone, hired_on, dismissed_on, commission_percent) VALUES
    (1, 'Катков', 'Богдан', '+70000000001', '2025-09-01', NULL, 40),
    (2, 'Михеев', 'Никита', '+70000000002', '2025-10-01', NULL, 35),
    (3, 'Гудков', 'Максим', '+70000000003', '2025-11-01', '2026-11-24', 30),
    (4, 'Сарайкин', 'Артем', '+70000000004', '2026-01-10', NULL, 25);

INSERT INTO clients (id, full_name, phone) VALUES
    (1, 'Иванов Алексей', '+70000000101'),
    (2, 'Петрова Мария', '+70000000102'),
    (3, 'Сидоров Андрей', '+70000000103'),
    (4, 'Смирнова Елена', '+70000000104');

INSERT INTO vehicles (id, client_id, registration_number, brand, model) VALUES
    (1, 1, 'TEST-001', 'Lada', 'Vesta'),
    (2, 2, 'TEST-002', 'Kia', 'Rio'),
    (3, 3, 'TEST-003', 'Renault', 'Logan'),
    (4, 4, 'TEST-004', 'Hyundai', 'Solaris'),
    (5, 1, 'TEST-005', 'Toyota', 'Corolla');

INSERT INTO services (id, name, duration_minutes, price_cents) VALUES
    (1, 'Замена масла', 60, 250000),
    (2, 'Диагностика', 45, 180000),
    (3, 'Замена тормозных колодок', 90, 400000),
    (4, 'Шиномонтаж', 60, 300000),
    (5, 'Замена аккумулятора', 30, 120000);

INSERT INTO work_shifts (id, employee_id, starts_at, ends_at) VALUES
    (1, 1, '2026-11-23 09:00:00', '2026-11-23 18:00:00'),
    (2, 2, '2026-11-23 10:00:00', '2026-11-23 19:00:00'),
    (3, 3, '2026-11-23 09:00:00', '2026-11-23 17:00:00'),
    (4, 1, '2026-11-25 09:00:00', '2026-11-25 18:00:00'),
    (5, 2, '2026-11-25 11:00:00', '2026-11-25 20:00:00'),
    (6, 4, '2026-11-25 08:00:00', '2026-11-25 16:00:00'),
    (7, 1, '2026-11-26 09:00:00', '2026-11-26 18:00:00'),
    (8, 2, '2026-11-26 10:00:00', '2026-11-26 19:00:00');

INSERT INTO appointments (id, employee_id, vehicle_id, starts_at, ends_at) VALUES
    (1, 1, 1, '2026-11-23 09:00:00', '2026-11-23 10:00:00'),
    (2, 2, 2, '2026-11-23 10:00:00', '2026-11-23 11:30:00'),
    (3, 3, 3, '2026-11-23 09:00:00', '2026-11-23 10:45:00'),
    (4, 1, 2, '2026-11-25 10:00:00', '2026-11-25 11:00:00'),
    (5, 2, 4, '2026-11-25 11:00:00', '2026-11-25 12:15:00'),
    (6, 4, 5, '2026-11-25 09:00:00', '2026-11-25 10:00:00'),
    (7, 1, 1, '2026-11-26 09:00:00', '2026-11-26 10:45:00'),
    (8, 2, 3, '2026-11-26 10:00:00', '2026-11-26 11:00:00');

INSERT INTO appointment_services (appointment_id, service_id, agreed_price_cents, planned_minutes) VALUES
    (1, 1, 250000, 60), (2, 3, 400000, 90),
    (3, 1, 250000, 60), (3, 2, 180000, 45),
    (4, 4, 300000, 60),
    (5, 2, 180000, 45), (5, 5, 120000, 30),
    (6, 4, 300000, 60),
    (7, 1, 250000, 60), (7, 2, 180000, 45),
    (8, 4, 300000, 60);

-- Отменённая запись не занимает время: на её место добавлена новая запись.
UPDATE appointments SET status = 'cancelled' WHERE id = 8;
INSERT INTO appointments (id, employee_id, vehicle_id, starts_at, ends_at)
VALUES (9, 2, 3, '2026-11-26 10:00:00', '2026-11-26 11:00:00');
INSERT INTO appointment_services (appointment_id, service_id, agreed_price_cents, planned_minutes)
VALUES (9, 4, 300000, 60);

INSERT INTO completed_works (appointment_id, service_id, performed_at, price_cents, commission_percent) VALUES
    (1, 1, '2026-11-23 09:55:00', 250000, 40),
    (2, 3, '2026-11-23 11:25:00', 400000, 35),
    (3, 1, '2026-11-23 09:55:00', 250000, 30),
    (3, 2, '2026-11-23 10:35:00', 180000, 30),
    (4, 4, '2026-11-25 10:55:00', 300000, 40),
    (5, 2, '2026-11-25 11:40:00', 180000, 35),
    (5, 5, '2026-11-25 12:10:00', 120000, 35),
    (6, 4, '2026-11-25 09:55:00', 300000, 25);
UPDATE appointments SET status = 'completed' WHERE id IN (1, 2, 3, 4, 5, 6);

COMMIT;

-- Примеры запросов для приложения (выполнять отдельно).
-- 1. Зарплата и выручка за произвольный интервал [начало, конец).
-- Достаточно заменить две даты. LEFT JOIN оставляет также сотрудников без работ.
-- SELECT e.id, e.last_name, e.first_name, e.dismissed_on,
--        COALESCE(SUM(w.revenue_cents), 0) / 100.0 AS revenue_rub,
--        COALESCE(SUM(w.salary_cents), 0) / 100.0 AS salary_rub
-- FROM employees e LEFT JOIN work_income w ON w.employee_id = e.id
--     AND w.performed_at >= '2026-11-23 00:00:00'
--     AND w.performed_at <  '2026-11-26 00:00:00'
-- GROUP BY e.id, e.last_name, e.first_name, e.dismissed_on;
--
-- 2. Статистика популярности и фактической выручки по услугам за период.
-- SELECT s.name, COUNT(w.service_id) AS completed_count,
--        COALESCE(SUM(w.price_cents), 0) / 100.0 AS revenue_rub
-- FROM services s LEFT JOIN completed_works w ON w.service_id = s.id
--     AND w.performed_at >= '2026-11-23 00:00:00'
--     AND w.performed_at <  '2026-11-26 00:00:00'
-- GROUP BY s.id, s.name;
--
-- 3. Кадровый учёт: действующие и уволенные сотрудники с сохранённой историей.
-- SELECT *, CASE WHEN dismissed_on IS NOT NULL AND dismissed_on <= date('now')
--                THEN 'Уволен' ELSE 'Работает' END AS employment_status
-- FROM employee_totals ORDER BY last_name, first_name;
--
-- 4. Фиксация новой выполненной работы: фактическая цена и действующий процент.
-- INSERT INTO completed_works (appointment_id, service_id, price_cents, commission_percent)
-- SELECT s.appointment_id, s.service_id, s.agreed_price_cents, e.commission_percent
-- FROM appointment_services s JOIN appointments a ON a.id = s.appointment_id
-- JOIN employees e ON e.id = a.employee_id
-- WHERE s.appointment_id = :appointment_id AND s.service_id = :service_id;
