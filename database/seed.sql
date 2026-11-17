USE `automaster`;
SET NAMES utf8mb4;

-- Демо-данные: 2 аккаунта (администратор и сотрудник), услуги, мастера, расписание, записи.
-- Логины: admin@automaster.local / Admin123!   user@automaster.local / User123!   client@automaster.local / Client123!

INSERT INTO employees (id, full_name, position, phone, email, employee_number, hire_date, salary, specialization, commission_percent, is_master, work_status) VALUES
(1, 'Александр Петров', 'Старший механик', '+7 900 000-00-01', 'user@automaster.local', 'EMP001', '2024-01-10', 60000.00, 'Двигатель, ходовая, ТО', 30.00, 1, 'Работает'),
(2, 'Администратор', 'Администратор', '+7 900 000-00-00', 'admin@automaster.local', 'ADM001', '2023-01-01', 70000.00, NULL, 0.00, 0, 'Работает'),
(3, 'Дмитрий Крылов', 'Мастер-диагност', '+7 900 000-00-03', 'krylov@automaster.local', 'EMP002', '2024-03-15', 58000.00, 'Компьютерная диагностика, электроника', 30.00, 1, 'Работает'),
(4, 'Михаил Волков', 'Автоэлектрик', '+7 900 000-00-04', 'volkov@automaster.local', 'EMP003', '2024-06-01', 55000.00, 'Электрооборудование, свет, стартеры', 30.00, 1, 'Работает'),
(5, 'Сергей Ким', 'Шиномонтажник', '+7 900 000-00-05', 'kim@automaster.local', 'EMP004', '2025-02-10', 48000.00, 'Шиномонтаж, балансировка', 25.00, 1, 'Работает');

INSERT INTO users (id, client_id, employee_id, full_name, email, phone, password_hash, role, status) VALUES
(1, NULL, 2, 'Администратор', 'admin@automaster.local', '+7 900 000-00-00', '$2y$10$nt8RKhDq8FwGTqa7AGcY5u5i0QwxXFi1UQzN3Hm3Mm885wXB9EBUG', 'admin', 'active'),
(2, NULL, 1, 'Александр Петров', 'user@automaster.local', '+7 900 000-00-01', '$2y$10$CoMNsmvR7Ak.39cnkPoXtuGqo4UC5pKJc.cbRuojS8cnn8N7okLEK', 'employee', 'active');

INSERT INTO services (id, name, description, price, category, duration_hours, duration_minutes, bonus_percent, is_active, image) VALUES
(1, 'Замена моторного масла', 'Замена масла и масляного фильтра', 1800.00, 'Техническое обслуживание', 1, 60, 5, 1, 'assets/images/service-1.jpg'),
(2, 'Компьютерная диагностика', 'Считывание и расшифровка ошибок блоков управления', 2000.00, 'Диагностика', 1, 60, 5, 1, 'assets/images/service-2.jpg'),
(3, 'Диагностика двигателя', 'Полная проверка двигателя: компрессия, форсунки, зажигание', 2500.00, 'Диагностика', 2, 120, 5, 1, 'assets/images/service-3.jpg'),
(4, 'Ремонт тормозной системы', 'Замена колодок и дисков, прокачка тормозной жидкости', 4500.00, 'Ремонт', 3, 180, 7, 1, 'assets/images/service-4.jpg'),
(5, 'Замена свечей зажигания', 'Замена комплекта свечей зажигания', 1600.00, 'Техническое обслуживание', 1, 60, 5, 1, 'assets/images/service-5.jpg'),
(6, 'Шиномонтаж (комплект)', 'Снятие, монтаж и балансировка четырёх колёс', 2400.00, 'Шиномонтаж', 1, 60, 5, 1, 'assets/images/service-6.jpg');

INSERT INTO employee_services (employee_id, service_id, is_active) VALUES
(1,1,1),(1,2,1),(1,3,1),(1,4,1),(1,5,1),(1,6,1),
(3,1,1),(3,2,1),(3,3,1),(3,5,1),
(4,2,1),(4,3,1),(4,4,1),(4,5,1),
(5,1,1),(5,6,1);

INSERT INTO employee_schedule (employee_id, weekday_num, start_time, end_time, break_start, break_end, is_working)
SELECT e.id, d.n, '09:00', '18:00', '13:00', '14:00', IF(d.n <= 5, 1, 0)
FROM employees e
JOIN (SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7) d
WHERE e.is_master = 1;

INSERT INTO clients (id, full_name, phone, email, bonus_points) VALUES
(1, 'Иван Сидоров',    '+7 900 111-11-11', 'sidorov@example.com', 90),
(2, 'Ольга Смирнова',  '+7 900 222-22-22', 'smirnova@example.com', 100),
(3, 'Павел Кузнецов',  '+7 900 333-33-33', 'kuznetsov@example.com', 0),
(4, 'Мария Орлова',    '+7 900 444-44-44', 'orlova@example.com', 80),
(5, 'Андрей Васильев', '+7 900 555-55-55', 'vasiliev@example.com', 0),
(6, 'Елена Морозова',  '+7 900 666-66-66', 'morozova@example.com', 0);

-- Третий аккаунт: покупатель (клиент) Иван Сидоров
INSERT INTO users (id, client_id, employee_id, full_name, email, phone, password_hash, role, status) VALUES
(3, 1, NULL, 'Иван Сидоров', 'client@automaster.local', '+7 900 111-11-11', '$2y$10$QF9ZNe1lNUQbMzUMiFkC4ex6XBQoPCzvdyDhwz1uXIoAPPMsTS2zm', 'client', 'active');

INSERT INTO cars (client_id, brand, model, year, vin, license_plate) VALUES
(1, 'Toyota', 'Camry', 2018, 'JTNB11HK103000001', 'А111АА77'),
(1, 'Lada', 'Granta', 2021, 'XTA219010M0000002', 'Т777ТТ77');

INSERT INTO bonus_transactions (client_id, points, operation_type, description) VALUES
(1, 100, 'Начисление', 'Приветственный бонус за регистрацию'),
(1, 90, 'Начисление', 'Бонус за услугу «Замена моторного масла»'),
(1, 100, 'Списание', 'Списание баллов при оплате');

-- Записи относительно текущей даты, чтобы календарь не был пустым
CREATE TEMPORARY TABLE tmp_a (off_days INT, t TIME, svc INT, emp INT, cli INT, car VARCHAR(40), model VARCHAR(40), plate VARCHAR(20), st VARCHAR(30), pay VARCHAR(20));
INSERT INTO tmp_a VALUES
(-1,'09:00',1,1,1,'Toyota','Camry','А111АА77','Оказана','Оплачено'),
(-1,'10:00',2,3,2,'Kia','Rio','В222ВВ77','Оказана','Оплачено'),
(-1,'14:00',4,1,3,'Lada','Vesta','Е333ЕЕ77','Оказана','Оплачено'),
(0,'09:00',1,1,1,'Toyota','Camry','А111АА77','Подтверждена','Не оплачено'),
(0,'10:00',2,3,2,'Kia','Rio','В222ВВ77','В работе','Не оплачено'),
(0,'11:00',6,5,4,'Skoda','Octavia','К444КК77','Подтверждена','Не оплачено'),
(0,'14:00',3,1,5,'Hyundai','Solaris','М555ММ77','Подтверждена','Не оплачено'),
(0,'15:00',5,3,6,'Renault','Duster','Н666НН77','Новая','Не оплачено'),
(1,'09:00',2,1,2,'Kia','Rio','В222ВВ77','Новая','Не оплачено'),
(1,'10:00',3,3,3,'Lada','Vesta','Е333ЕЕ77','Подтверждена','Не оплачено'),
(1,'14:00',4,4,5,'Hyundai','Solaris','М555ММ77','Подтверждена','Не оплачено'),
(2,'09:00',5,1,4,'Skoda','Octavia','К444КК77','Новая','Не оплачено'),
(2,'11:00',1,5,6,'Renault','Duster','Н666НН77','Подтверждена','Не оплачено'),
(2,'15:00',2,4,1,'Toyota','Camry','А111АА77','Новая','Не оплачено'),
(3,'10:00',6,5,2,'Kia','Rio','В222ВВ77','Новая','Не оплачено'),
(3,'14:00',1,1,3,'Lada','Vesta','Е333ЕЕ77','Отменена','Не оплачено'),
(7,'10:00',3,1,5,'Hyundai','Solaris','М555ММ77','Новая','Не оплачено'),
(8,'09:00',2,3,1,'Toyota','Camry','А111АА77','Подтверждена','Не оплачено');

INSERT INTO appointments (client_id, service_id, employee_id, client_name, phone, car_brand, car_model, license_plate, work_description, preferred_date, preferred_time, start_datetime, end_datetime, service_price_at_time, service_duration_minutes, master_income, payment_status, completed_at, created_by_role, status)
SELECT a.cli, a.svc, a.emp, c.full_name, c.phone, a.car, a.model, a.plate, s.name,
       CURDATE() + INTERVAL a.off_days DAY, a.t,
       TIMESTAMP(CURDATE() + INTERVAL a.off_days DAY, a.t),
       TIMESTAMP(CURDATE() + INTERVAL a.off_days DAY, a.t) + INTERVAL s.duration_minutes MINUTE,
       s.price, s.duration_minutes, ROUND(s.price * 0.30, 2), a.pay,
       IF(a.st = 'Оказана', TIMESTAMP(CURDATE() + INTERVAL a.off_days DAY, a.t) + INTERVAL s.duration_minutes MINUTE, NULL),
       'admin', a.st
FROM tmp_a a
JOIN clients c ON c.id = a.cli
JOIN services s ON s.id = a.svc;
DROP TEMPORARY TABLE tmp_a;
