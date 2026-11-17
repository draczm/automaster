<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$error = '';
$success = '';

$weekdays = [
    1 => 'Пн',
    2 => 'Вт',
    3 => 'Ср',
    4 => 'Чт',
    5 => 'Пт',
    6 => 'Сб',
    7 => 'Вс'
];

$filterSearch = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterServiceId = (int)($_GET['service_id'] ?? 0);

function adminMoney($value)
{
    return number_format((float)$value, 0, '.', ' ') . ' ₽';
}

function adminShortDate($value)
{
    if (!$value) {
        return 'не указано';
    }

    return date('d.m.Y', strtotime($value));
}

function adminSimpleEmailValid($email)
{
    if ($email === '') {
        return false;
    }

    if (!str_contains($email, '@')) {
        return false;
    }

    [$left, $right] = explode('@', $email, 2);

    return $left !== '' && $right !== '';
}

function adminGenerateEmployeeNumber()
{
    return 'EMP' . date('ymd') . random_int(100, 999);
}

function adminSaveEmployeeServices($pdo, $employeeId, $serviceIds)
{
    $deleteStmt = $pdo->prepare("
        DELETE FROM employee_services
        WHERE employee_id = ?
    ");
    $deleteStmt->execute([(int)$employeeId]);

    if (empty($serviceIds)) {
        return;
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO employee_services (
            employee_id,
            service_id,
            is_active
        )
        VALUES (?, ?, 1)
    ");

    foreach ($serviceIds as $serviceId) {
        $insertStmt->execute([
            (int)$employeeId,
            (int)$serviceId
        ]);
    }
}

function adminSaveEmployeeSchedule($pdo, $employeeId, $schedule)
{
    $deleteStmt = $pdo->prepare("
        DELETE FROM employee_schedule
        WHERE employee_id = ?
    ");
    $deleteStmt->execute([(int)$employeeId]);

    $insertStmt = $pdo->prepare("
        INSERT INTO employee_schedule (
            employee_id,
            weekday_num,
            start_time,
            end_time,
            break_start,
            break_end,
            is_working
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    for ($day = 1; $day <= 7; $day++) {
        $dayData = $schedule[$day] ?? [];

        $isWorking = isset($dayData['is_working']) ? 1 : 0;
        $startTime = trim($dayData['start_time'] ?? '08:30');
        $endTime = trim($dayData['end_time'] ?? '21:00');
        $breakStart = trim($dayData['break_start'] ?? '');
        $breakEnd = trim($dayData['break_end'] ?? '');

        if ($startTime === '') {
            $startTime = '08:30';
        }

        if ($endTime === '') {
            $endTime = '21:00';
        }

        $insertStmt->execute([
            (int)$employeeId,
            $day,
            $startTime . ':00',
            $endTime . ':00',
            $breakStart !== '' ? $breakStart . ':00' : null,
            $breakEnd !== '' ? $breakEnd . ':00' : null,
            $isWorking
        ]);
    }
}

function adminDefaultSchedulePost()
{
    $schedule = [];

    for ($day = 1; $day <= 7; $day++) {
        $schedule[$day] = [
            'is_working' => $day <= 5 ? 1 : 0,
            'start_time' => '08:30',
            'end_time' => '21:00',
            'break_start' => '13:00',
            'break_end' => '14:00'
        ];
    }

    return $schedule;
}

$servicesStmt = $pdo->query("
    SELECT id, name, category, is_active
    FROM services
    ORDER BY is_active DESC, category, name
");
$allServices = $servicesStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_employee') {
            $fullName = trim($_POST['full_name'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $employeeNumber = trim($_POST['employee_number'] ?? '');
            $hireDate = trim($_POST['hire_date'] ?? date('Y-m-d'));
            $salary = (float)str_replace(',', '.', $_POST['salary'] ?? 0);
            $specialization = trim($_POST['specialization'] ?? '');
            $commissionPercent = (float)str_replace(',', '.', $_POST['commission_percent'] ?? 30);
            $workStatus = trim($_POST['work_status'] ?? 'Работает');
            $password = trim($_POST['password'] ?? '123456');
            $serviceIds = $_POST['service_ids'] ?? [];
            $schedule = $_POST['schedule'] ?? adminDefaultSchedulePost();

            if ($fullName === '') {
                throw new Exception('Укажите ФИО мастера.');
            }

            if ($position === '') {
                throw new Exception('Укажите должность мастера.');
            }

            if (!adminSimpleEmailValid($email)) {
                throw new Exception('Укажите корректный email.');
            }

            if (mb_strlen($password) < 6) {
                throw new Exception('Пароль должен быть не короче 6 символов.');
            }

            if ($employeeNumber === '') {
                $employeeNumber = adminGenerateEmployeeNumber();
            }

            if ($commissionPercent < 0 || $commissionPercent > 100) {
                throw new Exception('Процент оплаты должен быть от 0 до 100.');
            }

            $checkUserStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE email = ?
            ");
            $checkUserStmt->execute([$email]);

            if ((int)$checkUserStmt->fetchColumn() > 0) {
                throw new Exception('Пользователь с таким email уже существует.');
            }

            $checkEmployeeStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM employees
                WHERE employee_number = ?
            ");
            $checkEmployeeStmt->execute([$employeeNumber]);

            if ((int)$checkEmployeeStmt->fetchColumn() > 0) {
                throw new Exception('Табельный номер уже используется.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO employees (
                    full_name,
                    position,
                    phone,
                    email,
                    employee_number,
                    hire_date,
                    salary,
                    specialization,
                    commission_percent,
                    is_master,
                    work_status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");

            $stmt->execute([
                $fullName,
                $position,
                $phone,
                $email,
                $employeeNumber,
                $hireDate,
                $salary,
                $specialization,
                $commissionPercent,
                $workStatus
            ]);

            $employeeId = (int)$pdo->lastInsertId();

            $userStmt = $pdo->prepare("
                INSERT INTO users (
                    client_id,
                    employee_id,
                    full_name,
                    email,
                    phone,
                    password_hash,
                    role,
                    status
                )
                VALUES (NULL, ?, ?, ?, ?, ?, 'employee', 'active')
            ");

            $userStmt->execute([
                $employeeId,
                $fullName,
                $email,
                $phone,
                password_hash($password, PASSWORD_DEFAULT)
            ]);

            adminSaveEmployeeServices($pdo, $employeeId, $serviceIds);
            adminSaveEmployeeSchedule($pdo, $employeeId, $schedule);

            $success = 'Мастер добавлен. Логин: ' . $email . ', пароль: ' . $password . '.';
        }

        if ($action === 'update_employee') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $fullName = trim($_POST['full_name'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $employeeNumber = trim($_POST['employee_number'] ?? '');
            $hireDate = trim($_POST['hire_date'] ?? date('Y-m-d'));
            $salary = (float)str_replace(',', '.', $_POST['salary'] ?? 0);
            $specialization = trim($_POST['specialization'] ?? '');
            $commissionPercent = (float)str_replace(',', '.', $_POST['commission_percent'] ?? 30);
            $workStatus = trim($_POST['work_status'] ?? 'Работает');
            $newPassword = trim($_POST['new_password'] ?? '');

            if ($employeeId <= 0) {
                throw new Exception('Мастер не найден.');
            }

            if ($fullName === '' || $position === '') {
                throw new Exception('Заполните ФИО и должность.');
            }

            if (!adminSimpleEmailValid($email)) {
                throw new Exception('Укажите корректный email.');
            }

            if ($employeeNumber === '') {
                throw new Exception('Укажите табельный номер.');
            }

            if ($commissionPercent < 0 || $commissionPercent > 100) {
                throw new Exception('Процент оплаты должен быть от 0 до 100.');
            }

            if ($newPassword !== '' && mb_strlen($newPassword) < 6) {
                throw new Exception('Новый пароль должен быть не короче 6 символов.');
            }

            $checkEmployeeStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM employees
                WHERE employee_number = ?
                  AND id <> ?
            ");
            $checkEmployeeStmt->execute([$employeeNumber, $employeeId]);

            if ((int)$checkEmployeeStmt->fetchColumn() > 0) {
                throw new Exception('Табельный номер уже используется другим мастером.');
            }

            $checkUserStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE email = ?
                  AND (employee_id IS NULL OR employee_id <> ?)
            ");
            $checkUserStmt->execute([$email, $employeeId]);

            if ((int)$checkUserStmt->fetchColumn() > 0) {
                throw new Exception('Email уже используется другим пользователем.');
            }

            $stmt = $pdo->prepare("
                UPDATE employees
                SET
                    full_name = ?,
                    position = ?,
                    phone = ?,
                    email = ?,
                    employee_number = ?,
                    hire_date = ?,
                    salary = ?,
                    specialization = ?,
                    commission_percent = ?,
                    is_master = 1,
                    work_status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $fullName,
                $position,
                $phone,
                $email,
                $employeeNumber,
                $hireDate,
                $salary,
                $specialization,
                $commissionPercent,
                $workStatus,
                $employeeId
            ]);

            $userUpdateStmt = $pdo->prepare("
                UPDATE users
                SET
                    full_name = ?,
                    email = ?,
                    phone = ?
                WHERE employee_id = ?
                  AND role = 'employee'
            ");

            $userUpdateStmt->execute([
                $fullName,
                $email,
                $phone,
                $employeeId
            ]);

            if ($newPassword !== '') {
                $passwordStmt = $pdo->prepare("
                    UPDATE users
                    SET password_hash = ?
                    WHERE employee_id = ?
                      AND role = 'employee'
                ");

                $passwordStmt->execute([
                    password_hash($newPassword, PASSWORD_DEFAULT),
                    $employeeId
                ]);
            }

            $success = 'Данные мастера обновлены.';
        }

        if ($action === 'update_services') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $serviceIds = $_POST['service_ids'] ?? [];

            if ($employeeId <= 0) {
                throw new Exception('Мастер не найден.');
            }

            adminSaveEmployeeServices($pdo, $employeeId, $serviceIds);

            $success = 'Услуги мастера обновлены.';
        }

        if ($action === 'update_schedule') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $schedule = $_POST['schedule'] ?? [];

            if ($employeeId <= 0) {
                throw new Exception('Мастер не найден.');
            }

            adminSaveEmployeeSchedule($pdo, $employeeId, $schedule);

            $success = 'График мастера обновлен.';
        }

        if ($action === 'toggle_employee') {
            $employeeId = (int)($_POST['employee_id'] ?? 0);
            $workStatus = trim($_POST['work_status'] ?? 'Не работает');

            if ($employeeId <= 0) {
                throw new Exception('Мастер не найден.');
            }

            $stmt = $pdo->prepare("
                UPDATE employees
                SET work_status = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $workStatus,
                $employeeId
            ]);

            $success = 'Статус мастера обновлен.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$where = [
    'employees.is_master = 1'
];

$params = [];

if ($filterSearch !== '') {
    $where[] = "(
        employees.full_name LIKE ?
        OR employees.position LIKE ?
        OR employees.email LIKE ?
        OR employees.phone LIKE ?
        OR employees.specialization LIKE ?
        OR employees.employee_number LIKE ?
    )";

    $searchValue = '%' . $filterSearch . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

if ($filterStatus === 'active') {
    $where[] = "employees.work_status = 'Работает'";
}

if ($filterStatus === 'inactive') {
    $where[] = "employees.work_status <> 'Работает'";
}

if ($filterServiceId > 0) {
    $where[] = "
        EXISTS (
            SELECT 1
            FROM employee_services
            WHERE employee_services.employee_id = employees.id
              AND employee_services.service_id = ?
              AND employee_services.is_active = 1
        )
    ";

    $params[] = $filterServiceId;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$employeesStmt = $pdo->prepare("
    SELECT
        employees.*,
        COUNT(DISTINCT appointments.id) AS appointments_count,
        SUM(CASE WHEN appointments.status IN ('Оказана', 'Выполнена') THEN 1 ELSE 0 END) AS done_count,
        COALESCE(SUM(
            CASE
                WHEN appointments.status IN ('Оказана', 'Выполнена')
                THEN appointments.master_income
                ELSE 0
            END
        ), 0) AS income_sum
    FROM employees
    LEFT JOIN appointments ON appointments.employee_id = employees.id
    {$whereSql}
    GROUP BY employees.id
    ORDER BY
        CASE WHEN employees.work_status = 'Работает' THEN 0 ELSE 1 END,
        employees.full_name
");
$employeesStmt->execute($params);
$employees = $employeesStmt->fetchAll();

$employeeIds = array_map(fn($employee) => (int)$employee['id'], $employees);

$employeeServices = [];
$employeeSchedules = [];

if (!empty($employeeIds)) {
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

    $employeeServicesStmt = $pdo->prepare("
        SELECT employee_id, service_id
        FROM employee_services
        WHERE employee_id IN ({$placeholders})
          AND is_active = 1
    ");
    $employeeServicesStmt->execute($employeeIds);

    foreach ($employeeServicesStmt->fetchAll() as $row) {
        $employeeServices[(int)$row['employee_id']][] = (int)$row['service_id'];
    }

    $scheduleStmt = $pdo->prepare("
        SELECT *
        FROM employee_schedule
        WHERE employee_id IN ({$placeholders})
        ORDER BY weekday_num
    ");
    $scheduleStmt->execute($employeeIds);

    foreach ($scheduleStmt->fetchAll() as $row) {
        $employeeSchedules[(int)$row['employee_id']][(int)$row['weekday_num']] = $row;
    }
}

$totalEmployees = count($employees);
$activeEmployees = 0;
$inactiveEmployees = 0;
$totalDone = 0;

foreach ($employees as $employee) {
    if ($employee['work_status'] === 'Работает') {
        $activeEmployees++;
    } else {
        $inactiveEmployees++;
    }

    $totalDone += (int)$employee['done_count'];
}

require_once '../includes/header.php';
?>

<section class="admin-page">
    <div class="container">
        <div class="admin-hero admin-hero--single">
            <div>
                <span class="label">Администрирование</span>

                <h1>Управление мастерами</h1>

                <p>
                    Здесь администратор добавляет мастеров, назначает услуги,
                    задает график работы и контролирует активность сотрудников.
                </p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <div class="admin-stats-grid admin-stats-grid--employees">
            <article class="admin-stat-card">
                <span>Найдено мастеров</span>
                <strong><?= (int)$totalEmployees ?></strong>
                <p>по текущему фильтру</p>
            </article>

            <article class="admin-stat-card">
                <span>Работают</span>
                <strong><?= (int)$activeEmployees ?></strong>
                <p>доступны для записи</p>
            </article>

            <article class="admin-stat-card">
                <span>Не работают</span>
                <strong><?= (int)$inactiveEmployees ?></strong>
                <p>скрыты из расписания</p>
            </article>

            <article class="admin-stat-card">
                <span>Оказано услуг</span>
                <strong><?= (int)$totalDone ?></strong>
                <p>по найденным мастерам</p>
            </article>
        </div>

        <section class="admin-section-card">
            <details class="admin-collapsible">
                <summary>
                    <span>
                        <b>Добавить мастера</b>
                        <small>ФИО, доступ, услуги и график работы</small>
                    </span>
                </summary>

                <form method="post" action="employees.php" class="admin-employee-form">
                    <input type="hidden" name="action" value="create_employee">

                    <div class="form-group">
                        <label>ФИО *</label>
                        <input type="text" name="full_name" placeholder="Александр Петров" required>
                    </div>

                    <div class="form-group">
                        <label>Должность *</label>
                        <input type="text" name="position" placeholder="Автомеханик" required>
                    </div>

                    <div class="form-group">
                        <label>Email *</label>
                        <input type="text" name="email" placeholder="master@email" required>
                    </div>

                    <div class="form-group">
                        <label>Пароль *</label>
                        <input type="text" name="password" value="123456" minlength="6" required>
                    </div>

                    <div class="form-group">
                        <label>Телефон</label>
                        <input type="text" name="phone" placeholder="+7 900 000-00-00">
                    </div>

                    <div class="form-group">
                        <label>Табельный номер</label>
                        <input type="text" name="employee_number" placeholder="Заполнится автоматически">
                    </div>

                    <div class="form-group">
                        <label>Дата приема</label>
                        <input type="date" name="hire_date" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="form-group">
                        <label>Оклад, ₽</label>
                        <input type="number" name="salary" min="0" step="1000" value="50000">
                    </div>

                    <div class="form-group">
                        <label>Процент оплаты, %</label>
                        <input type="number" name="commission_percent" min="0" max="100" step="0.5" value="30">
                    </div>

                    <div class="form-group">
                        <label>Статус</label>
                        <select name="work_status">
                            <option value="Работает">Работает</option>
                            <option value="Не работает">Не работает</option>
                        </select>
                    </div>

                    <div class="form-group admin-employee-form__wide">
                        <label>Специализация</label>
                        <textarea name="specialization" placeholder="Диагностика, техническое обслуживание, ремонт тормозной системы"></textarea>
                    </div>

                    <div class="admin-employee-form__wide">
                        <label>Услуги мастера</label>

                        <div class="admin-service-check-grid">
                            <?php foreach ($allServices as $service): ?>
                                <label class="admin-check admin-check--compact">
                                    <input
                                        type="checkbox"
                                        name="service_ids[]"
                                        value="<?= (int)$service['id'] ?>"
                                        <?= (int)$service['is_active'] === 1 ? 'checked' : '' ?>
                                    >

                                    <span><?= htmlspecialchars($service['name']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="admin-employee-form__wide">
                        <label>График работы</label>

                        <div class="admin-schedule-editor">
                            <div class="admin-schedule-labels">
                                <span>День</span>
                                <span>Статус</span>
                                <span>Начало работы</span>
                                <span>Конец работы</span>
                                <span>Начало перерыва</span>
                                <span>Конец перерыва</span>
                            </div>

                            <?php foreach ($weekdays as $dayNumber => $dayName): ?>
                                <div class="admin-schedule-row">
                                    <strong><?= htmlspecialchars($dayName) ?></strong>

                                    <label class="admin-check admin-check--mini">
                                        <input
                                            type="checkbox"
                                            name="schedule[<?= $dayNumber ?>][is_working]"
                                            <?= $dayNumber <= 5 ? 'checked' : '' ?>
                                        >
                                        <span>Работает</span>
                                    </label>

                                    <input
                                        type="time"
                                        name="schedule[<?= $dayNumber ?>][start_time]"
                                        value="08:30"
                                        aria-label="Начало работы"
                                    >

                                    <input
                                        type="time"
                                        name="schedule[<?= $dayNumber ?>][end_time]"
                                        value="21:00"
                                        aria-label="Конец работы"
                                    >

                                    <input
                                        type="time"
                                        name="schedule[<?= $dayNumber ?>][break_start]"
                                        value="13:00"
                                        aria-label="Начало перерыва"
                                    >

                                    <input
                                        type="time"
                                        name="schedule[<?= $dayNumber ?>][break_end]"
                                        value="14:00"
                                        aria-label="Конец перерыва"
                                    >
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="submit" class="btn btn--orange">
                        Добавить мастера
                    </button>
                </form>
            </details>
        </section>

        <section class="admin-section-card">
            <div class="admin-section-head admin-section-head--compact">
                <div>
                    <span class="label">Сотрудники</span>
                    <h2>Список мастеров</h2>
                </div>

                <strong class="admin-count">
                    <?= count($employees) ?> шт.
                </strong>
            </div>

            <form method="get" action="employees.php" class="admin-list-filter admin-list-filter--employees">
                <div class="form-group">
                    <label for="search">Поиск</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($filterSearch) ?>"
                        placeholder="ФИО, должность, email, телефон"
                    >
                </div>

                <div class="form-group">
                    <label for="status">Статус</label>
                    <select id="status" name="status">
                        <option value="">Все</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Работают</option>
                        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Не работают</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="service_id">Услуга</label>
                    <select id="service_id" name="service_id">
                        <option value="0">Все услуги</option>

                        <?php foreach ($allServices as $service): ?>
                            <option
                                value="<?= (int)$service['id'] ?>"
                                <?= $filterServiceId === (int)$service['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($service['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn--orange btn--small">
                    Найти
                </button>

                <a href="employees.php" class="btn btn--light btn--small">
                    Сбросить
                </a>
            </form>

            <?php if (empty($employees)): ?>
                <p class="empty-text">По выбранным фильтрам мастера не найдены.</p>
            <?php else: ?>
                <div class="admin-employees-list">
                    <?php foreach ($employees as $employee): ?>
                        <?php
                            $currentServiceIds = $employeeServices[(int)$employee['id']] ?? [];
                            $currentSchedule = $employeeSchedules[(int)$employee['id']] ?? [];
                            $isActive = $employee['work_status'] === 'Работает';
                        ?>

                        <article class="admin-employee-card <?= $isActive ? '' : 'is-hidden' ?>">
                            <div class="admin-employee-card__head">
                                <div>
                                    <span class="admin-card-time">
                                        <?= htmlspecialchars($employee['position']) ?>
                                    </span>

                                    <h3><?= htmlspecialchars($employee['full_name']) ?></h3>

                                    <p><?= htmlspecialchars($employee['specialization'] ?: 'Специализация не указана') ?></p>
                                </div>

                                <span class="status-badge <?= $isActive ? 'status-badge--done' : 'status-badge--cancel' ?>">
                                    <?= htmlspecialchars($employee['work_status']) ?>
                                </span>
                            </div>

                            <div class="record-card__meta">
                                <div>
                                    <span>Email</span>
                                    <strong><?= htmlspecialchars($employee['email'] ?: 'не указан') ?></strong>
                                </div>

                                <div>
                                    <span>Телефон</span>
                                    <strong><?= htmlspecialchars($employee['phone'] ?: 'не указан') ?></strong>
                                </div>

                                <div>
                                    <span>Табельный</span>
                                    <strong><?= htmlspecialchars($employee['employee_number']) ?></strong>
                                </div>

                                <div>
                                    <span>Оклад</span>
                                    <strong><?= adminMoney($employee['salary']) ?></strong>
                                </div>

                                <div>
                                    <span>Процент</span>
                                    <strong><?= (float)$employee['commission_percent'] ?>%</strong>
                                </div>

                                <div>
                                    <span>Услуги</span>
                                    <strong><?= count($currentServiceIds) ?></strong>
                                </div>

                                <div>
                                    <span>Заявок</span>
                                    <strong><?= (int)$employee['appointments_count'] ?></strong>
                                </div>

                                <div>
                                    <span>Оказано</span>
                                    <strong><?= (int)$employee['done_count'] ?></strong>
                                </div>

                                <div>
                                    <span>Доход</span>
                                    <strong><?= adminMoney($employee['income_sum']) ?></strong>
                                </div>

                                <div>
                                    <span>Принят</span>
                                    <strong><?= adminShortDate($employee['hire_date']) ?></strong>
                                </div>
                            </div>

                            <details class="admin-more">
                                <summary>Управление</summary>

                                <div class="admin-employee-actions">
                                    <form method="post" action="employees.php" class="admin-employee-edit-form">
                                        <input type="hidden" name="action" value="update_employee">
                                        <input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">

                                        <div class="form-group">
                                            <label>ФИО *</label>
                                            <input type="text" name="full_name" value="<?= htmlspecialchars($employee['full_name']) ?>" required>
                                        </div>

                                        <div class="form-group">
                                            <label>Должность *</label>
                                            <input type="text" name="position" value="<?= htmlspecialchars($employee['position']) ?>" required>
                                        </div>

                                        <div class="form-group">
                                            <label>Email *</label>
                                            <input type="text" name="email" value="<?= htmlspecialchars($employee['email'] ?? '') ?>" required>
                                        </div>

                                        <div class="form-group">
                                            <label>Новый пароль</label>
                                            <input type="text" name="new_password" placeholder="Оставьте пустым">
                                        </div>

                                        <div class="form-group">
                                            <label>Телефон</label>
                                            <input type="text" name="phone" value="<?= htmlspecialchars($employee['phone'] ?? '') ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Табельный номер</label>
                                            <input type="text" name="employee_number" value="<?= htmlspecialchars($employee['employee_number']) ?>" required>
                                        </div>

                                        <div class="form-group">
                                            <label>Дата приема</label>
                                            <input type="date" name="hire_date" value="<?= date('Y-m-d', strtotime($employee['hire_date'])) ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Оклад, ₽</label>
                                            <input type="number" name="salary" min="0" step="1000" value="<?= htmlspecialchars($employee['salary']) ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Процент, %</label>
                                            <input type="number" name="commission_percent" min="0" max="100" step="0.5" value="<?= htmlspecialchars($employee['commission_percent']) ?>">
                                        </div>

                                        <div class="form-group">
                                            <label>Статус</label>
                                            <select name="work_status">
                                                <option value="Работает" <?= $employee['work_status'] === 'Работает' ? 'selected' : '' ?>>Работает</option>
                                                <option value="Не работает" <?= $employee['work_status'] !== 'Работает' ? 'selected' : '' ?>>Не работает</option>
                                            </select>
                                        </div>

                                        <div class="form-group admin-employee-form__wide">
                                            <label>Специализация</label>
                                            <textarea name="specialization"><?= htmlspecialchars($employee['specialization'] ?? '') ?></textarea>
                                        </div>

                                        <button type="submit" class="btn btn--orange btn--small">
                                            Сохранить данные
                                        </button>
                                    </form>

                                    <form method="post" action="employees.php" class="admin-employee-services-form">
                                        <input type="hidden" name="action" value="update_services">
                                        <input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">

                                        <h4>Услуги мастера</h4>

                                        <div class="admin-service-check-grid">
                                            <?php foreach ($allServices as $service): ?>
                                                <label class="admin-check admin-check--compact">
                                                    <input
                                                        type="checkbox"
                                                        name="service_ids[]"
                                                        value="<?= (int)$service['id'] ?>"
                                                        <?= in_array((int)$service['id'], $currentServiceIds, true) ? 'checked' : '' ?>
                                                    >

                                                    <span><?= htmlspecialchars($service['name']) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>

                                        <button type="submit" class="btn btn--light btn--small">
                                            Сохранить услуги
                                        </button>
                                    </form>

                                    <form method="post" action="employees.php" class="admin-employee-schedule-form">
                                        <input type="hidden" name="action" value="update_schedule">
                                        <input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">

                                        <h4>График работы</h4>

                                        <div class="admin-schedule-editor">
                                            <div class="admin-schedule-labels">
                                                <span>День</span>
                                                <span>Статус</span>
                                                <span>Начало работы</span>
                                                <span>Конец работы</span>
                                                <span>Начало перерыва</span>
                                                <span>Конец перерыва</span>
                                            </div>

                                            <?php foreach ($weekdays as $dayNumber => $dayName): ?>
                                                <?php
                                                    $row = $currentSchedule[$dayNumber] ?? null;

                                                    $rowIsWorking = $row ? (int)$row['is_working'] === 1 : $dayNumber <= 5;
                                                    $startTime = $row ? date('H:i', strtotime($row['start_time'])) : '08:30';
                                                    $endTime = $row ? date('H:i', strtotime($row['end_time'])) : '21:00';
                                                    $breakStart = !empty($row['break_start']) ? date('H:i', strtotime($row['break_start'])) : '13:00';
                                                    $breakEnd = !empty($row['break_end']) ? date('H:i', strtotime($row['break_end'])) : '14:00';
                                                ?>

                                                <div class="admin-schedule-row">
                                                    <strong><?= htmlspecialchars($dayName) ?></strong>

                                                    <label class="admin-check admin-check--mini">
                                                        <input
                                                            type="checkbox"
                                                            name="schedule[<?= $dayNumber ?>][is_working]"
                                                            <?= $rowIsWorking ? 'checked' : '' ?>
                                                        >
                                                        <span>Работает</span>
                                                    </label>

                                                    <input type="time" name="schedule[<?= $dayNumber ?>][start_time]" value="<?= htmlspecialchars($startTime) ?>" aria-label="Начало работы">
                                                    <input type="time" name="schedule[<?= $dayNumber ?>][end_time]" value="<?= htmlspecialchars($endTime) ?>" aria-label="Конец работы">
                                                    <input type="time" name="schedule[<?= $dayNumber ?>][break_start]" value="<?= htmlspecialchars($breakStart) ?>" aria-label="Начало перерыва">
                                                    <input type="time" name="schedule[<?= $dayNumber ?>][break_end]" value="<?= htmlspecialchars($breakEnd) ?>" aria-label="Конец перерыва">
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <button type="submit" class="btn btn--light btn--small">
                                            Сохранить график
                                        </button>
                                    </form>

                                    <form method="post" action="employees.php" class="admin-employee-toggle-form">
                                        <input type="hidden" name="action" value="toggle_employee">
                                        <input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">
                                        <input type="hidden" name="work_status" value="<?= $isActive ? 'Не работает' : 'Работает' ?>">

                                        <button type="submit" class="<?= $isActive ? 'btn btn--danger btn--small' : 'btn btn--orange btn--small' ?>">
                                            <?= $isActive ? 'Отключить мастера' : 'Вернуть в работу' ?>
                                        </button>
                                    </form>
                                </div>
                            </details>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<?php require_once '../includes/footer.php'; ?>