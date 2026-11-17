SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `appointment_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `old_status` varchar(100) DEFAULT NULL,
  `new_status` varchar(100) DEFAULT NULL,
  `old_employee_id` int(11) DEFAULT NULL,
  `new_employee_id` int(11) DEFAULT NULL,
  `old_start_datetime` datetime DEFAULT NULL,
  `new_start_datetime` datetime DEFAULT NULL,
  `changed_by_user_id` int(11) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_appointment_history_appointment` (`appointment_id`),
  KEY `fk_appointment_history_old_employee` (`old_employee_id`),
  KEY `fk_appointment_history_new_employee` (`new_employee_id`),
  CONSTRAINT `fk_appointment_history_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_appointment_history_new_employee` FOREIGN KEY (`new_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_appointment_history_old_employee` FOREIGN KEY (`old_employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `appointments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `car_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `phone` varchar(100) NOT NULL,
  `car_brand` varchar(100) DEFAULT NULL,
  `car_model` varchar(100) DEFAULT NULL,
  `car_year` int(11) DEFAULT NULL,
  `license_plate` varchar(50) DEFAULT NULL,
  `work_description` text DEFAULT NULL,
  `preferred_date` date DEFAULT NULL,
  `preferred_time` time DEFAULT NULL,
  `start_datetime` datetime DEFAULT NULL,
  `end_datetime` datetime DEFAULT NULL,
  `service_price_at_time` decimal(10,2) NOT NULL DEFAULT 0.00,
  `service_duration_minutes` int(11) NOT NULL DEFAULT 60,
  `master_income` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('Не оплачено','Оплачено','Возврат') NOT NULL DEFAULT 'Не оплачено',
  `completed_at` datetime DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `created_by_role` enum('client','employee','admin') NOT NULL DEFAULT 'client',
  `comment` text DEFAULT NULL,
  `status` enum('Новая','Подтверждена','В работе','Оказана','Перенесена','Отменена','Выполнена') NOT NULL DEFAULT 'Новая',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_appointments_client` (`client_id`),
  KEY `fk_appointments_service` (`service_id`),
  KEY `fk_appointments_car` (`car_id`),
  CONSTRAINT `fk_appointments_car` FOREIGN KEY (`car_id`) REFERENCES `cars` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `bonus_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `points` int(11) NOT NULL,
  `operation_type` enum('Начисление','Списание') NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_bonus_client` (`client_id`),
  KEY `fk_bonus_order` (`order_id`),
  CONSTRAINT `fk_bonus_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bonus_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `brand` varchar(100) NOT NULL,
  `model` varchar(100) NOT NULL,
  `year` int(11) DEFAULT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `license_plate` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cars_vin` (`vin`),
  UNIQUE KEY `uq_cars_license_plate` (`license_plate`),
  KEY `fk_cars_client` (`client_id`),
  CONSTRAINT `fk_cars_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) NOT NULL,
  `phone` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `bonus_points` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `consumables` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit` varchar(50) NOT NULL DEFAULT 'шт.',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `employee_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `weekday_num` tinyint(4) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `break_start` time DEFAULT NULL,
  `break_end` time DEFAULT NULL,
  `is_working` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_weekday` (`employee_id`,`weekday_num`),
  CONSTRAINT `fk_employee_schedule_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `employee_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_employee_service` (`employee_id`,`service_id`),
  KEY `fk_employee_services_service` (`service_id`),
  CONSTRAINT `fk_employee_services_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `employee_time_off` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `time_from` time DEFAULT NULL,
  `time_to` time DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('Активно','Отменено') NOT NULL DEFAULT 'Активно',
  PRIMARY KEY (`id`),
  KEY `fk_employee_time_off_employee` (`employee_id`),
  CONSTRAINT `fk_employee_time_off_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(255) NOT NULL,
  `position` varchar(100) NOT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `employee_number` varchar(100) NOT NULL,
  `hire_date` date DEFAULT NULL,
  `salary` decimal(10,2) DEFAULT 0.00,
  `specialization` varchar(255) DEFAULT NULL,
  `commission_percent` decimal(5,2) NOT NULL DEFAULT 30.00,
  `is_master` tinyint(1) NOT NULL DEFAULT 1,
  `work_status` enum('Работает','В отпуске','Уволен') NOT NULL DEFAULT 'Работает',
  PRIMARY KEY (`id`),
  UNIQUE KEY `employee_number` (`employee_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `news` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `order_consumables` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `consumable_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price_at_time` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provided_by_client` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_order_consumables_order` (`order_id`),
  KEY `fk_order_consumables_consumable` (`consumable_id`),
  CONSTRAINT `fk_order_consumables_consumable` FOREIGN KEY (`consumable_id`) REFERENCES `consumables` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_consumables_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `order_employees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_order_employees_order` (`order_id`),
  KEY `fk_order_employees_employee` (`employee_id`),
  CONSTRAINT `fk_order_employees_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_order_employees_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `order_services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price_at_time` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `fk_order_services_order` (`order_id`),
  KEY `fk_order_services_service` (`service_id`),
  CONSTRAINT `fk_order_services_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `order_spare_parts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `spare_part_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price_at_time` decimal(10,2) NOT NULL DEFAULT 0.00,
  `provided_by_client` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `fk_order_spare_parts_order` (`order_id`),
  KEY `fk_order_spare_parts_part` (`spare_part_id`),
  CONSTRAINT `fk_order_spare_parts_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_spare_parts_part` FOREIGN KEY (`spare_part_id`) REFERENCES `spare_parts` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_number` varchar(100) NOT NULL,
  `client_id` int(11) NOT NULL,
  `car_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `planned_finish_date` date DEFAULT NULL,
  `completion_date` timestamp NULL DEFAULT NULL,
  `status` enum('Ожидает','В работе','На согласовании','Завершен','Отменен') NOT NULL DEFAULT 'Ожидает',
  `repair_type` enum('Техническое обслуживание','Диагностика','Ремонт','Кузовные работы','Шиномонтаж') NOT NULL,
  `problem_description` text DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bonus_points_earned` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `fk_orders_client` (`client_id`),
  KEY `fk_orders_car` (`car_id`),
  KEY `fk_orders_appointment` (`appointment_id`),
  CONSTRAINT `fk_orders_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_car` FOREIGN KEY (`car_id`) REFERENCES `cars` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `reviews` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `rating` int(11) NOT NULL,
  `text` text NOT NULL,
  `status` enum('На проверке','Опубликован','Отклонен') NOT NULL DEFAULT 'На проверке',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_reviews_client` (`client_id`),
  CONSTRAINT `fk_reviews_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `CONSTRAINT_1` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `services` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `category` enum('Техническое обслуживание','Диагностика','Ремонт','Кузовные работы','Шиномонтаж') NOT NULL,
  `duration_hours` int(11) NOT NULL DEFAULT 1,
  `duration_minutes` int(11) NOT NULL DEFAULT 60,
  `bonus_percent` int(11) NOT NULL DEFAULT 5,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `spare_parts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `article` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `in_stock` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) DEFAULT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('client','employee','admin') NOT NULL DEFAULT 'client',
  `status` enum('active','blocked') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_client` (`client_id`),
  CONSTRAINT `fk_users_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;

SET NAMES utf8mb4;

-- Демо-данные: 2 аккаунта (администратор и сотрудник), услуги, мастера, расписание, записи.
-- Логины: admin@automaster.local / Admin123!   user@automaster.local / User123!

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
