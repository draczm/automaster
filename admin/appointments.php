<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';
require_once '../includes/schedule_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../index.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$error = '';
$success = '';

function adminDateTime($value)
{
    if (!$value) {
        return 'не указано';
    }

    return date('d.m.Y H:i', strtotime($value));
}

function adminTime($value)
{
    if (!$value) {
        return '—';
    }

    return date('H:i', strtotime($value));
}

function adminMoney($value)
{
    return number_format((float)$value, 0, '.', ' ') . ' ₽';
}

function adminStatusClass($status)
{
    return match ($status) {
        'Новая' => 'status-badge--new',
        'Подтверждена' => 'status-badge--confirmed',
        'В работе' => 'status-badge--work',
        'Оказана', 'Выполнена' => 'status-badge--done',
        'Перенесена' => 'status-badge--moved',
        'Отменена' => 'status-badge--cancel',
        default => 'status-badge--default'
    };
}

function adminGetAppointment($pdo, $appointmentId)
{
    $stmt = $pdo->prepare("
        SELECT
            appointments.*,
            services.name AS service_name,
            employees.full_name AS master_name
        FROM appointments
        LEFT JOIN services ON services.id = appointments.service_id
        LEFT JOIN employees ON employees.id = appointments.employee_id
        WHERE appointments.id = ?
        LIMIT 1
    ");

    $stmt->execute([(int)$appointmentId]);

    return $stmt->fetch();
}

function adminGetMasterScheduleForService($pdo, $employeeId, $serviceId, $date)
{
    $weekday = (int)date('N', strtotime($date));

    $stmt = $pdo->prepare("
        SELECT
            employees.id,
            employees.full_name,
            employees.position,
            employees.specialization,
            employees.commission_percent,
            employee_schedule.start_time,
            employee_schedule.end_time,
            employee_schedule.break_start,
            employee_schedule.break_end
        FROM employees
        INNER JOIN employee_services
            ON employee_services.employee_id = employees.id
        INNER JOIN employee_schedule
            ON employee_schedule.employee_id = employees.id
        WHERE employees.id = ?
          AND employee_services.service_id = ?
          AND employee_services.is_active = 1
          AND employees.is_master = 1
          AND employees.work_status = 'Работает'
          AND employee_schedule.weekday_num = ?
          AND employee_schedule.is_working = 1
        LIMIT 1
    ");

    $stmt->execute([(int)$employeeId, (int)$serviceId, $weekday]);

    return $stmt->fetch();
}

function adminHasAppointmentConflict($pdo, $employeeId, $startDatetime, $endDatetime, $excludeAppointmentId)
{
    $stmt = $pdo->prepare("
        SELECT id
        FROM appointments
        WHERE employee_id = ?
          AND id <> ?
          AND status IN ('Новая', 'Подтверждена', 'В работе', 'Перенесена')
          AND start_datetime IS NOT NULL
          AND end_datetime IS NOT NULL
          AND ? < end_datetime
          AND ? > start_datetime
        LIMIT 1
    ");

    $stmt->execute([
        (int)$employeeId,
        (int)$excludeAppointmentId,
        $startDatetime,
        $endDatetime
    ]);

    return (bool)$stmt->fetch();
}

function adminMasterAvailableExcluding($pdo, $master, $date, $time, $durationMinutes, $excludeAppointmentId)
{
    $start = new DateTimeImmutable($date . ' ' . cr_time_full($time));
    $end = $start->modify('+' . (int)$durationMinutes . ' minutes');

    $workStart = new DateTimeImmutable($date . ' ' . cr_time_full($master['start_time']));
    $workEnd = new DateTimeImmutable($date . ' ' . cr_time_full($master['end_time']));

    if ($start < $workStart || $end > $workEnd) {
        return false;
    }

    if ($master['break_start'] !== null && $master['break_end'] !== null) {
        $breakStart = new DateTimeImmutable($date . ' ' . cr_time_full($master['break_start']));
        $breakEnd = new DateTimeImmutable($date . ' ' . cr_time_full($master['break_end']));

        if ($start < $breakEnd && $end > $breakStart) {
            return false;
        }
    }

    $startDatetime = $start->format('Y-m-d H:i:s');
    $endDatetime = $end->format('Y-m-d H:i:s');

    if (adminHasAppointmentConflict($pdo, $master['id'], $startDatetime, $endDatetime, $excludeAppointmentId)) {
        return false;
    }

    if (cr_master_has_time_off_conflict(
        $pdo,
        $master['id'],
        $date,
        $start->format('H:i:s'),
        $end->format('H:i:s')
    )) {
        return false;
    }

    return true;
}

function adminAddHistory($pdo, $appointment, $newStatus, $newEmployeeId, $newStartDatetime, $userId, $comment)
{
    $stmt = $pdo->prepare("
        INSERT INTO appointment_history (
            appointment_id,
            old_status,
            new_status,
            old_employee_id,
            new_employee_id,
            old_start_datetime,
            new_start_datetime,
            changed_by_user_id,
            comment
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        (int)$appointment['id'],
        $appointment['status'],
        $newStatus,
        $appointment['employee_id'],
        $newEmployeeId,
        $appointment['start_datetime'],
        $newStartDatetime,
        $userId,
        $comment
    ]);
}

function adminAwardBonuses($pdo, $appointment)
{
    if (empty($appointment['client_id'])) {
        return;
    }

    $checkStmt = $pdo->prepare("
        SELECT id
        FROM bonus_transactions
        WHERE appointment_id = ?
          AND operation_type = 'Начисление'
        LIMIT 1
    ");
    $checkStmt->execute([(int)$appointment['id']]);

    if ($checkStmt->fetch()) {
        return;
    }

    $price = (float)($appointment['service_price_at_time'] ?? 0);
    $points = (int)round($price * 0.05);

    if ($points <= 0) {
        return;
    }

    $description = 'Начисление за услугу: ' . ($appointment['service_name'] ?? 'обслуживание автомобиля');

    $bonusStmt = $pdo->prepare("
        INSERT INTO bonus_transactions (
            client_id,
            order_id,
            appointment_id,
            points,
            operation_type,
            description
        )
        VALUES (?, NULL, ?, ?, 'Начисление', ?)
    ");

    $bonusStmt->execute([
        (int)$appointment['client_id'],
        (int)$appointment['id'],
        $points,
        $description
    ]);

    $clientStmt = $pdo->prepare("
        UPDATE clients
        SET bonus_points = bonus_points + ?
        WHERE id = ?
    ");

    $clientStmt->execute([
        $points,
        (int)$appointment['client_id']
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'change_status') {
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $newStatus = trim($_POST['status'] ?? '');
            $cancelReason = trim($_POST['cancel_reason'] ?? '');

            $allowedStatuses = ['Новая', 'Подтверждена', 'В работе', 'Оказана', 'Перенесена', 'Отменена'];

            if ($appointmentId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
                throw new Exception('Некорректный статус заявки.');
            }

            $appointment = adminGetAppointment($pdo, $appointmentId);

            if (!$appointment) {
                throw new Exception('Заявка не найдена.');
            }

            $completedAtSql = $newStatus === 'Оказана' ? ', completed_at = NOW(), payment_status = "Оплачено"' : '';
            $cancelSql = $newStatus === 'Отменена' ? ', cancel_reason = ?' : '';

            if ($newStatus === 'Отменена') {
                $stmt = $pdo->prepare("
                    UPDATE appointments
                    SET status = ?
                        {$completedAtSql}
                        {$cancelSql}
                    WHERE id = ?
                ");

                $stmt->execute([
                    $newStatus,
                    $cancelReason !== '' ? $cancelReason : 'Отменено администратором',
                    $appointmentId
                ]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE appointments
                    SET status = ?
                        {$completedAtSql}
                    WHERE id = ?
                ");

                $stmt->execute([
                    $newStatus,
                    $appointmentId
                ]);
            }

            adminAddHistory(
                $pdo,
                $appointment,
                $newStatus,
                $appointment['employee_id'],
                $appointment['start_datetime'],
                $userId,
                'Администратор изменил статус заявки'
            );

            if ($newStatus === 'Оказана' && $appointment['status'] !== 'Оказана' && $appointment['status'] !== 'Выполнена') {
                adminAwardBonuses($pdo, $appointment);
            }

            $success = 'Статус заявки обновлен.';
        }

        if ($action === 'move_appointment') {
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $newDate = trim($_POST['new_date'] ?? '');
            $newTime = trim($_POST['new_time'] ?? '');
            $newEmployeeId = (int)($_POST['new_employee_id'] ?? 0);

            if ($appointmentId <= 0 || !cr_valid_date($newDate) || !cr_valid_time($newTime)) {
                throw new Exception('Укажите корректную дату и время.');
            }

            $appointment = adminGetAppointment($pdo, $appointmentId);

            if (!$appointment) {
                throw new Exception('Заявка не найдена.');
            }

            if ($newEmployeeId <= 0) {
                $newEmployeeId = (int)$appointment['employee_id'];
            }

            if ($newEmployeeId <= 0) {
                throw new Exception('Выберите мастера для переноса заявки.');
            }

            $service = cr_get_service($pdo, (int)$appointment['service_id']);

            if (!$service) {
                throw new Exception('Услуга не найдена.');
            }

            $durationMinutes = (int)$appointment['service_duration_minutes'];

            if ($durationMinutes <= 0) {
                $durationMinutes = cr_service_duration_minutes($service);
            }

            $master = adminGetMasterScheduleForService(
                $pdo,
                $newEmployeeId,
                (int)$appointment['service_id'],
                $newDate
            );

            if (!$master) {
                throw new Exception('Выбранный мастер не работает с этой услугой в выбранный день.');
            }

            if (!adminMasterAvailableExcluding($pdo, $master, $newDate, $newTime, $durationMinutes, $appointmentId)) {
                throw new Exception('На это время мастер недоступен.');
            }

            $start = new DateTimeImmutable($newDate . ' ' . cr_time_full($newTime));
            $end = $start->modify('+' . $durationMinutes . ' minutes');

            $masterIncome = round((float)$appointment['service_price_at_time'] * (float)$master['commission_percent'] / 100, 2);

            $stmt = $pdo->prepare("
                UPDATE appointments
                SET
                    employee_id = ?,
                    preferred_date = ?,
                    preferred_time = ?,
                    start_datetime = ?,
                    end_datetime = ?,
                    master_income = ?,
                    status = 'Перенесена'
                WHERE id = ?
            ");

            $stmt->execute([
                $newEmployeeId,
                $newDate,
                cr_time_full($newTime),
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $masterIncome,
                $appointmentId
            ]);

            adminAddHistory(
                $pdo,
                $appointment,
                'Перенесена',
                $newEmployeeId,
                $start->format('Y-m-d H:i:s'),
                $userId,
                'Администратор перенес заявку'
            );

            $success = 'Заявка перенесена.';
        }

        if ($action === 'assign_master') {
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $newEmployeeId = (int)($_POST['new_employee_id'] ?? 0);

            if ($appointmentId <= 0 || $newEmployeeId <= 0) {
                throw new Exception('Выберите мастера.');
            }

            $appointment = adminGetAppointment($pdo, $appointmentId);

            if (!$appointment) {
                throw new Exception('Заявка не найдена.');
            }

            if (!$appointment['start_datetime']) {
                throw new Exception('У заявки не указано время начала.');
            }

            $date = date('Y-m-d', strtotime($appointment['start_datetime']));
            $time = date('H:i', strtotime($appointment['start_datetime']));

            $durationMinutes = (int)$appointment['service_duration_minutes'];

            if ($durationMinutes <= 0) {
                $service = cr_get_service($pdo, (int)$appointment['service_id']);
                $durationMinutes = cr_service_duration_minutes($service);
            }

            $master = adminGetMasterScheduleForService(
                $pdo,
                $newEmployeeId,
                (int)$appointment['service_id'],
                $date
            );

            if (!$master) {
                throw new Exception('Выбранный мастер не выполняет эту услугу в этот день.');
            }

            if (!adminMasterAvailableExcluding($pdo, $master, $date, $time, $durationMinutes, $appointmentId)) {
                throw new Exception('Выбранный мастер занят на это время.');
            }

            $masterIncome = round((float)$appointment['service_price_at_time'] * (float)$master['commission_percent'] / 100, 2);

            $stmt = $pdo->prepare("
                UPDATE appointments
                SET employee_id = ?,
                    master_income = ?,
                    status = 'Перенесена'
                WHERE id = ?
            ");

            $stmt->execute([
                $newEmployeeId,
                $masterIncome,
                $appointmentId
            ]);

            adminAddHistory(
                $pdo,
                $appointment,
                'Перенесена',
                $newEmployeeId,
                $appointment['start_datetime'],
                $userId,
                'Администратор назначил другого мастера'
            );

            $success = 'Мастер назначен.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$statusFilter = trim($_GET['status'] ?? '');
$dateFilter = trim($_GET['date'] ?? '');
$searchFilter = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if ($statusFilter !== '') {
    $where[] = 'appointments.status = ?';
    $params[] = $statusFilter;
}

if ($dateFilter !== '' && cr_valid_date($dateFilter)) {
    $where[] = 'DATE(appointments.start_datetime) = ?';
    $params[] = $dateFilter;
}

if ($searchFilter !== '') {
    $where[] = "(
        appointments.client_name LIKE ?
        OR appointments.phone LIKE ?
        OR appointments.car_brand LIKE ?
        OR appointments.car_model LIKE ?
        OR appointments.license_plate LIKE ?
    )";

    $like = '%' . $searchFilter . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT
        appointments.*,
        services.name AS service_name,
        services.category AS service_category,
        employees.full_name AS master_name,
        employees.position AS master_position
    FROM appointments
    LEFT JOIN services ON services.id = appointments.service_id
    LEFT JOIN employees ON employees.id = appointments.employee_id
    {$whereSql}
    ORDER BY appointments.start_datetime DESC, appointments.created_at DESC
");
$stmt->execute($params);
$appointments = $stmt->fetchAll();

$mastersStmt = $pdo->query("
    SELECT id, full_name, position
    FROM employees
    WHERE is_master = 1
      AND work_status = 'Работает'
    ORDER BY full_name
");
$masters = $mastersStmt->fetchAll();

require_once '../includes/header.php';
?>

<section class="admin-page">
    <div class="container">
        <div class="admin-hero">
            <div>
                <span class="label">Администрирование</span>

                <h1>Управление заявками</h1>

                <p>
                    Администратор видит все записи, меняет статусы,
                    переносит заявки и назначает мастеров.
                </p>
            </div>

            <div class="admin-hero__actions">
                <a href="index.php" class="btn btn--light">Сводка</a>
                <a href="../appointment.php" class="btn btn--orange">Новая запись</a>
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

        <section class="admin-section-card">
            <form method="get" action="appointments.php" class="admin-filter-form">
                <div class="form-group">
                    <label for="status">Статус</label>

                    <select id="status" name="status">
                        <option value="">Все статусы</option>
                        <?php foreach (['Новая', 'Подтверждена', 'В работе', 'Оказана', 'Перенесена', 'Отменена'] as $status): ?>
                            <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>>
                                <?= $status ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="date">Дата</label>

                    <input
                        type="date"
                        id="date"
                        name="date"
                        value="<?= htmlspecialchars($dateFilter) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="search">Поиск</label>

                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($searchFilter) ?>"
                        placeholder="Клиент, телефон, авто, номер"
                    >
                </div>

                <button type="submit" class="btn btn--orange btn--small">
                    Найти
                </button>

                <a href="appointments.php" class="btn btn--light btn--small">
                    Сбросить
                </a>
            </form>
        </section>

        <section class="admin-section-card">
            <div class="admin-section-head">
                <div>
                    <span class="label">Заявки</span>
                    <h2>Список записей</h2>
                </div>

                <strong class="admin-count">
                    <?= count($appointments) ?> шт.
                </strong>
            </div>

            <?php if (empty($appointments)): ?>
                <p class="empty-text">Заявки не найдены.</p>
            <?php else: ?>
                <div class="admin-appointment-list">
                    <?php foreach ($appointments as $item): ?>
                        <?php
                            $date = $item['start_datetime'] ? date('Y-m-d', strtotime($item['start_datetime'])) : date('Y-m-d');
                            $time = $item['start_datetime'] ? date('H:i', strtotime($item['start_datetime'])) : '';
                        ?>

                        <article class="admin-appointment-card">
                            <div class="admin-appointment-card__top">
                                <div>
                                    <span class="admin-card-time">
                                        <?= adminDateTime($item['start_datetime']) ?>
                                    </span>

                                    <h3><?= htmlspecialchars($item['service_name'] ?? 'Услуга не указана') ?></h3>

                                    <p>
                                        <?= htmlspecialchars($item['client_name'] ?? 'Клиент') ?>
                                        ·
                                        <?= htmlspecialchars($item['phone'] ?? 'телефон не указан') ?>
                                    </p>
                                </div>

                                <span class="status-badge <?= adminStatusClass($item['status']) ?>">
                                    <?= htmlspecialchars($item['status']) ?>
                                </span>
                            </div>

                            <div class="record-card__meta">
                                <div>
                                    <span>Автомобиль</span>
                                    <strong>
                                        <?= htmlspecialchars($item['car_brand'] ?? '') ?>
                                        <?= htmlspecialchars($item['car_model'] ?? '') ?>
                                    </strong>
                                </div>

                                <div>
                                    <span>Госномер</span>
                                    <strong><?= htmlspecialchars($item['license_plate'] ?: 'не указан') ?></strong>
                                </div>

                                <div>
                                    <span>Мастер</span>
                                    <strong><?= htmlspecialchars($item['master_name'] ?? 'не назначен') ?></strong>
                                </div>

                                <div>
                                    <span>Стоимость</span>
                                    <strong><?= adminMoney($item['service_price_at_time']) ?></strong>
                                </div>

                                <div>
                                    <span>Оплата</span>
                                    <strong><?= htmlspecialchars($item['payment_status'] ?? 'не указано') ?></strong>
                                </div>

                                <div>
                                    <span>Создано</span>
                                    <strong><?= adminDateTime($item['created_at']) ?></strong>
                                </div>
                            </div>

                            <?php if (!empty($item['work_description'])): ?>
                                <p class="record-card__comment">
                                    <?= htmlspecialchars($item['work_description']) ?>
                                </p>
                            <?php endif; ?>

                            <?php if (!empty($item['comment'])): ?>
                                <p class="record-card__comment">
                                    <?= htmlspecialchars($item['comment']) ?>
                                </p>
                            <?php endif; ?>

                            <details class="admin-more">
                                <summary>Управление</summary>

                                <div class="admin-actions-grid">
                                    <form method="post" action="appointments.php" class="admin-action-form">
                                        <input type="hidden" name="action" value="change_status">
                                        <input type="hidden" name="appointment_id" value="<?= (int)$item['id'] ?>">

                                        <label>Статус</label>

                                        <select name="status">
                                            <?php foreach (['Новая', 'Подтверждена', 'В работе', 'Оказана', 'Перенесена', 'Отменена'] as $status): ?>
                                                <option value="<?= $status ?>" <?= $item['status'] === $status ? 'selected' : '' ?>>
                                                    <?= $status ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <input
                                            type="text"
                                            name="cancel_reason"
                                            placeholder="Причина отмены, если нужна"
                                        >

                                        <button type="submit" class="btn btn--orange btn--small">
                                            Сохранить статус
                                        </button>
                                    </form>

                                    <form method="post" action="appointments.php" class="admin-action-form">
                                        <input type="hidden" name="action" value="move_appointment">
                                        <input type="hidden" name="appointment_id" value="<?= (int)$item['id'] ?>">

                                        <label>Перенос</label>

                                        <input
                                            type="date"
                                            name="new_date"
                                            value="<?= htmlspecialchars($date) ?>"
                                            min="<?= date('Y-m-d') ?>"
                                        >

                                        <input
                                            type="time"
                                            name="new_time"
                                            value="<?= htmlspecialchars($time) ?>"
                                        >

                                        <select name="new_employee_id">
                                            <option value="">Текущий мастер</option>

                                            <?php foreach ($masters as $master): ?>
                                                <option value="<?= (int)$master['id'] ?>" <?= (int)$item['employee_id'] === (int)$master['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($master['full_name']) ?>
                                                    — <?= htmlspecialchars($master['position']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit" class="btn btn--light btn--small">
                                            Перенести
                                        </button>
                                    </form>

                                    <form method="post" action="appointments.php" class="admin-action-form">
                                        <input type="hidden" name="action" value="assign_master">
                                        <input type="hidden" name="appointment_id" value="<?= (int)$item['id'] ?>">

                                        <label>Назначить мастера</label>

                                        <select name="new_employee_id" required>
                                            <option value="">Выберите мастера</option>

                                            <?php foreach ($masters as $master): ?>
                                                <option value="<?= (int)$master['id'] ?>" <?= (int)$item['employee_id'] === (int)$master['id'] ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($master['full_name']) ?>
                                                    — <?= htmlspecialchars($master['position']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>

                                        <button type="submit" class="btn btn--light btn--small">
                                            Назначить
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