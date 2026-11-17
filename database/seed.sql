USE `automaster`;
SET NAMES utf8mb4;

-- Демо-данные: 2 аккаунта (администратор и сотрудник), услуги, расписание, записи.
-- Логины: admin@automaster.local / Admin123!   user@automaster.local / User123!

INSERT INTO employees (id, full_name, position, phone, email, employee_number, hire_date, salary, specialization, commission_percent, is_master, work_status) VALUES
(1, 'Александр Петров', 'Старший механик', '+7 900 000-00-01', 'user@automaster.local', 'EMP001', '2024-01-10', 60000.00, 'Двигатель, ходовая', 30.00, 1, 'Работает'),
(2, 'Администратор', 'Администратор', '+7 900 000-00-00', 'admin@automaster.local', 'ADM001', '2023-01-01', 70000.00, NULL, 0.00, 0, 'Работает');

INSERT INTO users (id, client_id, employee_id, full_name, email, phone, password_hash, role, status) VALUES
(1, NULL, 2, 'Администратор', 'admin@automaster.local', '+7 900 000-00-00', '$2y$10$nt8RKhDq8FwGTqa7AGcY5u5i0QwxXFi1UQzN3Hm3Mm885wXB9EBUG', 'admin', 'active'),
(2, NULL, 1, 'Александр Петров', 'user@automaster.local', '+7 900 000-00-01', '$2y$10$CoMNsmvR7Ak.39cnkPoXtuGqo4UC5pKJc.cbRuojS8cnn8N7okLEK', 'employee', 'active');

INSERT INTO services (id, name, description, price, category, duration_hours, duration_minutes, bonus_percent, is_active, image) VALUES
(1, 'Замена моторного масла', 'Замена масла и масляного фильтра', 1800.00, 'Техническое обслуживание', 1, 60, 5, 1, 'service-1.jpg'),
(2, 'Компьютерная диагностика', 'Считывание и расшифровка ошибок', 2000.00, 'Диагностика', 1, 60, 5, 1, 'service-2.jpg'),
(3, 'Диагностика двигателя', 'Полная проверка двигателя', 2500.00, 'Диагностика', 2, 120, 5, 1, 'service-3.jpg'),
(4, 'Ремонт тормозной системы', 'Колодки, диски, прокачка', 4500.00, 'Ремонт', 3, 180, 7, 1, 'service-4.jpg'),
(5, 'Замена свечей зажигания', 'Замена комплекта свечей', 1600.00, 'Техническое обслуживание', 1, 60, 5, 1, 'service-5.jpg');

INSERT INTO employee_services (employee_id, service_id, is_active) VALUES (1,1,1),(1,2,1),(1,3,1),(1,4,1),(1,5,1);

INSERT INTO employee_schedule (employee_id, weekday_num, start_time, end_time, break_start, break_end, is_working) VALUES
(1,1,'09:00','18:00','13:00','14:00',1),(1,2,'09:00','18:00','13:00','14:00',1),(1,3,'09:00','18:00','13:00','14:00',1),
(1,4,'09:00','18:00','13:00','14:00',1),(1,5,'09:00','18:00','13:00','14:00',1),
(1,6,'09:00','15:00',NULL,NULL,0),(1,7,'09:00','15:00',NULL,NULL,0);

-- Записи относительно текущей даты, чтобы календарь не был пустым
INSERT INTO appointments (service_id, employee_id, client_name, phone, car_brand, car_model, car_year, license_plate, work_description, preferred_date, preferred_time, start_datetime, end_datetime, service_price_at_time, service_duration_minutes, master_income, payment_status, created_by_role, status) VALUES
(1, 1, 'Иван Сидоров',  '+7 900 111-11-11', 'Toyota', 'Camry', 2018, 'А111АА77', 'Замена масла', CURDATE(), '10:00', TIMESTAMP(CURDATE(), '10:00'), TIMESTAMP(CURDATE(), '11:00'), 1800.00, 60, 540.00, 'Не оплачено', 'admin', 'Подтверждена'),
(2, 1, 'Ольга Смирнова', '+7 900 222-22-22', 'Kia', 'Rio', 2020, 'В222ВВ77', 'Горит check engine', CURDATE() + INTERVAL 1 DAY, '11:00', TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '11:00'), TIMESTAMP(CURDATE() + INTERVAL 1 DAY, '12:00'), 2000.00, 60, 600.00, 'Не оплачено', 'admin', 'Новая'),
(4, 1, 'Павел Кузнецов', '+7 900 333-33-33', 'Lada', 'Vesta', 2019, 'Е333ЕЕ77', 'Скрип тормозов', CURDATE() + INTERVAL 2 DAY, '14:00', TIMESTAMP(CURDATE() + INTERVAL 2 DAY, '14:00'), TIMESTAMP(CURDATE() + INTERVAL 2 DAY, '17:00'), 4500.00, 180, 1350.00, 'Не оплачено', 'admin', 'Подтверждена'),
(5, 1, 'Мария Орлова',  '+7 900 444-44-44', 'Skoda', 'Octavia', 2017, 'К444КК77', 'Замена свечей', CURDATE() - INTERVAL 2 DAY, '09:00', TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '09:00'), TIMESTAMP(CURDATE() - INTERVAL 2 DAY, '10:00'), 1600.00, 60, 480.00, 'Оплачено', 'admin', 'Оказана');