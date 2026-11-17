<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';
require_once 'includes/schedule_helpers.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] === 'admin') {
    header('Location: admin/index.php');
    exit;
}

if ($_SESSION['role'] !== 'employee' || empty($_SESSION['employee_id'])) {
    header('Location: index.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$employeeId = (int)$_SESSION['employee_id'];

$error = '';
$success = '';

$selectedDate = $_GET['date'] ?? date('Y-m-d');

if (!cr_valid_date($selectedDate)) {
    $selectedDate = date('Y-m-d');
}

function msDateRu($value)
{
    if (!$value) {
        return 'не указано';
    }

    return date('d.m.Y', strtotime($value));
}

function msDateTimeRu($value)
{
    if (!$value) {
        return 'не указано';
    }

    return date('d.m.Y H:i', strtotime($value));
}

function msTimeRu($value)
{
    if (!$value) {
        return '—';
    }

    return date('H:i', strtotime($value));
}

function msStatusClass($status)
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

function msTimeStatusClass($status)
{
    return match ($status) {
        'Оказана', 'Выполнена' => 'master-record-time--done',
        'В работе' => 'master-record-time--work',
        'Отменена' => 'master-record-time--cancel',
        'Подтверждена' => 'master-record-time--confirmed',
        'Новая' => 'master-record-time--new',
        'Перенесена' => 'master-record-time--moved',
        default => 'master-record-time--default'
    };
}

function msGetAppointmentForMaster($pdo, $appointmentId, $employeeId)
{
    $stmt = $pdo->prepare("
        SELECT
            appointments.*,
            services.name AS service_name,
            services.bonus_percent,
            services.duration_minutes,
            services.duration_hours,
            employees.full_name AS master_name
        FROM appointments
        LEFT JOIN services ON services.id = appointments.service_id
        LEFT JOIN employees ON employees.id = appointments.employee_id
        WHERE appointments.id = ?
          AND appointments.employee_id = ?
        LIMIT 1
    ");

    $stmt->execute([(int)$appointmentId, (int)$employeeId]);

    return $stmt->fetch();
}

function msGetMasterScheduleForService($pdo, $employeeId, $serviceId, $date)
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

function msHasAppointmentConflict($pdo, $employeeId, $startDatetime, $endDatetime, $excludeAppointmentId)
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

function msMasterAvailableExcluding($pdo, $master, $date, $time, $durationMinutes, $excludeAppointmentId)
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

    if (msHasAppointmentConflict($pdo, $master['id'], $startDatetime, $endDatetime, $excludeAppointmentId)) {
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

function msAwardBonuses($pdo, $appointment)
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

    $bonusPercent = (float)($appointment['bonus_percent'] ?? 5);
    $price = (float)($appointment['service_price_at_time'] ?? 0);
    $points = (int)round($price * $bonusPercent / 100);

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

            $allowedStatuses = ['Подтверждена', 'В работе', 'Оказана', 'Отменена'];

            if ($appointmentId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
                throw new Exception('Некорректный статус.');
            }

            $appointment = msGetAppointmentForMaster($pdo, $appointmentId, $employeeId);

            if (!$appointment) {
                throw new Exception('Запись не найдена.');
            }

            $oldStatus = $appointment['status'];
            $completedAtSql = $newStatus === 'Оказана' ? ', completed_at = NOW(), payment_status = "Оплачено"' : '';

            $stmt = $pdo->prepare("
                UPDATE appointments
                SET status = ?
                    {$completedAtSql}
                WHERE id = ?
                  AND employee_id = ?
            ");

            $stmt->execute([
                $newStatus,
                $appointmentId,
                $employeeId
            ]);

            $historyStmt = $pdo->prepare("
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

            $historyStmt->execute([
                $appointmentId,
                $oldStatus,
                $newStatus,
                $employeeId,
                $employeeId,
                $appointment['start_datetime'],
                $appointment['start_datetime'],
                $userId,
                'Мастер изменил статус записи'
            ]);

            if ($newStatus === 'Оказана' && $oldStatus !== 'Оказана' && $oldStatus !== 'Выполнена') {
                msAwardBonuses($pdo, $appointment);
            }

            $success = 'Статус записи обновлен.';
        }

        if ($action === 'move_appointment') {
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $newDate = trim($_POST['new_date'] ?? '');
            $newTime = trim($_POST['new_time'] ?? '');

            if ($appointmentId <= 0 || !cr_valid_date($newDate) || !cr_valid_time($newTime)) {
                throw new Exception('Укажите корректную дату и время.');
            }

            $appointment = msGetAppointmentForMaster($pdo, $appointmentId, $employeeId);

            if (!$appointment) {
                throw new Exception('Запись не найдена.');
            }

            $service = cr_get_service($pdo, (int)$appointment['service_id']);

            if (!$service) {
                throw new Exception('Услуга не найдена.');
            }

            $durationMinutes = cr_service_duration_minutes($service);

            $master = msGetMasterScheduleForService(
                $pdo,
                $employeeId,
                (int)$appointment['service_id'],
                $newDate
            );

            if (!$master) {
                throw new Exception('В выбранный день мастер не выполняет эту услугу.');
            }

            if (!msMasterAvailableExcluding($pdo, $master, $newDate, $newTime, $durationMinutes, $appointmentId)) {
                throw new Exception('На это время мастер недоступен.');
            }

            $start = new DateTimeImmutable($newDate . ' ' . cr_time_full($newTime));
            $end = $start->modify('+' . $durationMinutes . ' minutes');

            $stmt = $pdo->prepare("
                UPDATE appointments
                SET
                    preferred_date = ?,
                    preferred_time = ?,
                    start_datetime = ?,
                    end_datetime = ?,
                    status = 'Перенесена'
                WHERE id = ?
                  AND employee_id = ?
            ");

            $stmt->execute([
                $newDate,
                cr_time_full($newTime),
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                $appointmentId,
                $employeeId
            ]);

            $historyStmt = $pdo->prepare("
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
                VALUES (?, ?, 'Перенесена', ?, ?, ?, ?, ?, 'Мастер перенес запись')
            ");

            $historyStmt->execute([
                $appointmentId,
                $appointment['status'],
                $employeeId,
                $employeeId,
                $appointment['start_datetime'],
                $start->format('Y-m-d H:i:s'),
                $userId
            ]);

            $success = 'Запись перенесена.';
            $selectedDate = $newDate;
        }

        if ($action === 'transfer_master') {
            $appointmentId = (int)($_POST['appointment_id'] ?? 0);
            $newEmployeeId = (int)($_POST['new_employee_id'] ?? 0);

            if ($appointmentId <= 0 || $newEmployeeId <= 0) {
                throw new Exception('Выберите мастера для передачи записи.');
            }

            $appointment = msGetAppointmentForMaster($pdo, $appointmentId, $employeeId);

            if (!$appointment) {
                throw new Exception('Запись не найдена.');
            }

            $date = date('Y-m-d', strtotime($appointment['start_datetime']));
            $time = date('H:i', strtotime($appointment['start_datetime']));
            $durationMinutes = (int)$appointment['service_duration_minutes'];

            if ($durationMinutes <= 0) {
                $service = cr_get_service($pdo, (int)$appointment['service_id']);
                $durationMinutes = cr_service_duration_minutes($service);
            }

            $newMaster = msGetMasterScheduleForService(
                $pdo,
                $newEmployeeId,
                (int)$appointment['service_id'],
                $date
            );

            if (!$newMaster) {
                throw new Exception('Выбранный мастер не выполняет эту услугу в этот день.');
            }

            if (!msMasterAvailableExcluding($pdo, $newMaster, $date, $time, $durationMinutes, $appointmentId)) {
                throw new Exception('Выбранный мастер занят на это время.');
            }

            $stmt = $pdo->prepare("
                UPDATE appointments
                SET employee_id = ?,
                    status = 'Перенесена'
                WHERE id = ?
                  AND employee_id = ?
            ");

            $stmt->execute([
                $newEmployeeId,
                $appointmentId,
                $employeeId
            ]);

            $historyStmt = $pdo->prepare("
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
                VALUES (?, ?, 'Перенесена', ?, ?, ?, ?, ?, 'Мастер передал запись другому мастеру')
            ");

            $historyStmt->execute([
                $appointmentId,
                $appointment['status'],
                $employeeId,
                $newEmployeeId,
                $appointment['start_datetime'],
                $appointment['start_datetime'],
                $userId
            ]);

            $success = 'Запись передана другому мастеру.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$employeeStmt = $pdo->prepare("
    SELECT *
    FROM employees
    WHERE id = ?
    LIMIT 1
");
$employeeStmt->execute([$employeeId]);
$employee = $employeeStmt->fetch();

$appointmentsStmt = $pdo->prepare("
    SELECT
        appointments.*,
        services.name AS service_name,
        services.category AS service_category,
        clients.full_name AS client_full_name,
        employees.full_name AS master_name
    FROM appointments
    LEFT JOIN services ON services.id = appointments.service_id
    LEFT JOIN clients ON clients.id = appointments.client_id
    LEFT JOIN employees ON employees.id = appointments.employee_id
    WHERE appointments.employee_id = ?
      AND DATE(appointments.start_datetime) = ?
    ORDER BY appointments.start_datetime ASC
");
$appointmentsStmt->execute([$employeeId, $selectedDate]);
$appointments = $appointmentsStmt->fetchAll();

$dayStart = $selectedDate . ' 00:00:00';
$dayEnd = $selectedDate . ' 23:59:59';

$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($selectedDate))) . ' 00:00:00';
$weekEnd = date('Y-m-d', strtotime('sunday this week', strtotime($selectedDate))) . ' 23:59:59';

$monthStart = date('Y-m-01', strtotime($selectedDate)) . ' 00:00:00';
$monthEnd = date('Y-m-t', strtotime($selectedDate)) . ' 23:59:59';

function msStats($pdo, $employeeId, $start, $end)
{
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status IN ('Оказана', 'Выполнена') THEN 1 ELSE 0 END) AS done_count,
            SUM(CASE WHEN status IN ('Новая', 'Подтверждена', 'В работе', 'Перенесена') THEN 1 ELSE 0 END) AS active_count,
            SUM(CASE WHEN status = 'Отменена' THEN 1 ELSE 0 END) AS canceled_count,
            SUM(CASE WHEN status IN ('Оказана', 'Выполнена') THEN master_income ELSE 0 END) AS income_sum
        FROM appointments
        WHERE employee_id = ?
          AND start_datetime BETWEEN ? AND ?
    ");

    $stmt->execute([(int)$employeeId, $start, $end]);

    return $stmt->fetch();
}

$dayStats = msStats($pdo, $employeeId, $dayStart, $dayEnd);
$weekStats = msStats($pdo, $employeeId, $weekStart, $weekEnd);
$monthStats = msStats($pdo, $employeeId, $monthStart, $monthEnd);

$allMastersStmt = $pdo->query("
    SELECT id, full_name, position
    FROM employees
    WHERE is_master = 1
      AND work_status = 'Работает'
    ORDER BY full_name
");
$allMasters = $allMastersStmt->fetchAll();

/* ---------- Недельный календарь мастера ---------- */
function msCalClass($status)
{
    return match ($status) {
        'Оказана', 'Выполнена' => 'admin-calendar-event--done',
        'В работе' => 'admin-calendar-event--work',
        'Отменена' => 'admin-calendar-event--cancel',
        'Подтверждена' => 'admin-calendar-event--confirmed',
        'Перенесена' => 'admin-calendar-event--moved',
        'Новая' => 'admin-calendar-event--new',
        default => 'admin-calendar-event--default'
    };
}

$calWeekStart = date('Y-m-d', strtotime('monday this week', strtotime($selectedDate)));
$calWeekEnd = date('Y-m-d', strtotime($calWeekStart . ' +4 days'));
$calPrevWeek = date('Y-m-d', strtotime($calWeekStart . ' -7 days'));
$calNextWeek = date('Y-m-d', strtotime($calWeekStart . ' +7 days'));
$calToday = date('Y-m-d');

$calStartMin = 9 * 60;
$calEndMin = 19 * 60;
$calSlot = 30;
$calSlotH = 52;
$calHeight = (int)((($calEndMin - $calStartMin) / $calSlot) * $calSlotH);
$calDayNames = ['Понедельник', 'Вторник', 'Среда', 'Четверг', 'Пятница'];

$calDays = [];
for ($i = 0; $i < 5; $i++) {
    $d = date('Y-m-d', strtotime($calWeekStart . " +{$i} days"));
    $calDays[] = ['date' => $d, 'name' => $calDayNames[$i], 'items' => []];
}

$calStmt = $pdo->prepare("
    SELECT appointments.*, services.name AS service_name
    FROM appointments
    LEFT JOIN services ON services.id = appointments.service_id
    WHERE appointments.employee_id = ?
      AND DATE(appointments.start_datetime) BETWEEN ? AND ?
    ORDER BY appointments.start_datetime ASC
");
$calStmt->execute([(int)$employeeId, $calWeekStart, $calWeekEnd]);

foreach ($calStmt->fetchAll() as $row) {
    $rowDate = date('Y-m-d', strtotime($row['start_datetime']));
    $s = (int)date('H', strtotime($row['start_datetime'])) * 60 + (int)date('i', strtotime($row['start_datetime']));
    $e = !empty($row['end_datetime'])
        ? (int)date('H', strtotime($row['end_datetime'])) * 60 + (int)date('i', strtotime($row['end_datetime']))
        : $s + max(30, (int)($row['service_duration_minutes'] ?? 60));

    if ($e <= $calStartMin || $s >= $calEndMin) {
        continue;
    }

    $cs = max($s, $calStartMin);
    $ce = min($e, $calEndMin);
    $row['cal_top'] = (int)((($cs - $calStartMin) / $calSlot) * $calSlotH);
    $row['cal_height'] = (int)max(44, (($ce - $cs) / $calSlot) * $calSlotH - 6);
    $row['cal_start'] = sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    $row['cal_end'] = sprintf('%02d:%02d', intdiv($e, 60), $e % 60);

    foreach ($calDays as &$calDay) {
        if ($calDay['date'] === $rowDate) {
            $calDay['items'][] = $row;
        }
    }
    unset($calDay);
}

require_once 'includes/header.php';
?>

<section class="master-page">
    <div class="container">
        <div class="master-hero">
            <div>
                <span class="label">Кабинет мастера</span>

                <h1>Ежедневник</h1>

                <p>
                    <?= htmlspecialchars($employee['full_name'] ?? 'Мастер') ?>,
                    здесь показаны записи на выбранную дату, статусы работ и краткая статистика.
                </p>
            </div>

            <form method="get" action="master-schedule.php" class="master-date-form">
                <label for="date">Дата</label>

                <input
                    type="date"
                    id="date"
                    name="date"
                    value="<?= htmlspecialchars($selectedDate) ?>"
                >

                <button type="submit" class="btn btn--orange btn--small">
                    Показать
                </button>
            </form>
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

        <div class="master-stats-grid">
            <article class="master-stat-card">
                <span>День</span>
                <strong><?= (int)($dayStats['done_count'] ?? 0) ?></strong>
                <p>оказано услуг</p>
                <b><?= number_format((float)($dayStats['income_sum'] ?? 0), 0, '.', ' ') ?> ₽</b>
            </article>

            <article class="master-stat-card">
                <span>Неделя</span>
                <strong><?= (int)($weekStats['done_count'] ?? 0) ?></strong>
                <p>оказано услуг</p>
                <b><?= number_format((float)($weekStats['income_sum'] ?? 0), 0, '.', ' ') ?> ₽</b>
            </article>

            <article class="master-stat-card">
                <span>Месяц</span>
                <strong><?= (int)($monthStats['done_count'] ?? 0) ?></strong>
                <p>оказано услуг</p>
                <b><?= number_format((float)($monthStats['income_sum'] ?? 0), 0, '.', ' ') ?> ₽</b>
            </article>

            <article class="master-stat-card">
                <span>Активные</span>
                <strong><?= (int)($dayStats['active_count'] ?? 0) ?></strong>
                <p>на выбранную дату</p>
                <b><?= (int)($dayStats['canceled_count'] ?? 0) ?> отменено</b>
            </article>
        </div>

        <section class="master-section-card master-calendar" id="calendar">
            <div class="profile-section-head">
                <div>
                    <span class="label">Календарь</span>
                    <h2>Неделя <?= date('d.m', strtotime($calWeekStart)) ?>–<?= date('d.m.Y', strtotime($calWeekEnd)) ?></h2>
                </div>

                <div class="master-calendar-nav">
                    <a href="master-schedule.php?date=<?= htmlspecialchars($calPrevWeek) ?>" class="btn btn--light btn--small">← Прошлая</a>
                    <a href="master-schedule.php?date=<?= htmlspecialchars($calToday) ?>" class="btn btn--orange btn--small">Сегодня</a>
                    <a href="master-schedule.php?date=<?= htmlspecialchars($calNextWeek) ?>" class="btn btn--light btn--small">Следующая →</a>
                </div>
            </div>

            <div class="admin-calendar-legend">
                <span><i class="legend-dot legend-dot--new"></i>Новая</span>
                <span><i class="legend-dot legend-dot--confirmed"></i>Подтверждена</span>
                <span><i class="legend-dot legend-dot--work"></i>В работе</span>
                <span><i class="legend-dot legend-dot--done"></i>Оказана</span>
                <span><i class="legend-dot legend-dot--moved"></i>Перенесена</span>
                <span><i class="legend-dot legend-dot--cancel"></i>Отменена</span>
            </div>

            <div class="admin-calendar-scroll">
                <div class="admin-calendar-grid">
                    <div class="admin-calendar-corner">Время</div>

                    <?php foreach ($calDays as $calDay): ?>
                        <a
                            href="master-schedule.php?date=<?= htmlspecialchars($calDay['date']) ?>"
                            class="admin-calendar-day-head <?= $calDay['date'] === $calToday ? 'is-today' : '' ?> <?= $calDay['date'] === $selectedDate ? 'is-selected' : '' ?>"
                        >
                            <strong><?= htmlspecialchars($calDay['name']) ?></strong>
                            <span><?= date('d.m.Y', strtotime($calDay['date'])) ?></span>
                        </a>
                    <?php endforeach; ?>

                    <div class="admin-calendar-time-column" style="height: <?= $calHeight ?>px;">
                        <?php for ($m = $calStartMin, $idx = 0; $m < $calEndMin; $m += $calSlot, $idx++): ?>
                            <div class="admin-calendar-time" style="top: <?= (int)($idx * $calSlotH) ?>px;">
                                <?= sprintf('%02d:%02d', intdiv($m, 60), $m % 60) ?>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <?php foreach ($calDays as $calDay): ?>
                        <div
                            class="admin-calendar-day-column <?= $calDay['date'] === $calToday ? 'is-today' : '' ?>"
                            style="height: <?= $calHeight ?>px;"
                        >
                            <?php for ($m = $calStartMin, $idx = 0; $m < $calEndMin; $m += $calSlot, $idx++): ?>
                                <div class="admin-calendar-line" style="top: <?= (int)($idx * $calSlotH) ?>px;"></div>
                            <?php endfor; ?>

                            <?php if (empty($calDay['items'])): ?>
                                <div class="admin-calendar-empty-day">Записей нет</div>
                            <?php endif; ?>

                            <?php foreach ($calDay['items'] as $ev): ?>
                                <a
                                    href="master-schedule.php?date=<?= htmlspecialchars($calDay['date']) ?>"
                                    class="admin-calendar-event <?= msCalClass($ev['status']) ?>"
                                    style="top: <?= (int)$ev['cal_top'] ?>px; height: <?= (int)$ev['cal_height'] ?>px;"
                                    data-service="<?= htmlspecialchars($ev['service_name'] ?? 'Услуга') ?>"
                                    data-client="<?= htmlspecialchars($ev['client_name'] ?? '') ?>"
                                    data-phone="<?= htmlspecialchars($ev['phone'] ?? '') ?>"
                                    data-car="<?= htmlspecialchars(trim(($ev['car_brand'] ?? '') . ' ' . ($ev['car_model'] ?? ''))) ?>"
                                    data-plate="<?= htmlspecialchars($ev['license_plate'] ?? '') ?>"
                                    data-master="<?= htmlspecialchars($employee['full_name'] ?? '') ?>"
                                    data-date="<?= date('d.m.Y', strtotime($ev['start_datetime'])) ?>"
                                    data-time="<?= htmlspecialchars($ev['cal_start'] . ' — ' . $ev['cal_end']) ?>"
                                    data-status="<?= htmlspecialchars($ev['status']) ?>"
                                    data-price="<?= number_format((float)($ev['master_income'] ?? 0), 0, '.', ' ') ?> ₽"
                                    data-price-label="Оплата мастера"
                                    data-pay="<?= htmlspecialchars($ev['payment_status'] ?? '') ?>"
                                    data-comment="<?= htmlspecialchars($ev['work_description'] ?? '') ?>"
                                    data-href="master-schedule.php?date=<?= htmlspecialchars($calDay['date']) ?>"
                                    data-href-label="Открыть день и управлять записью"
                                >
                                    <div class="admin-calendar-event__time"><?= htmlspecialchars($ev['cal_start']) ?> — <?= htmlspecialchars($ev['cal_end']) ?></div>
                                    <strong><?= htmlspecialchars($ev['service_name'] ?? 'Услуга') ?></strong>
                                    <span><?= htmlspecialchars($ev['client_name'] ?? 'Клиент') ?></span>
                                    <span><?= htmlspecialchars(trim(($ev['car_brand'] ?? '') . ' ' . ($ev['car_model'] ?? ''))) ?></span>
                                    <div class="admin-calendar-event__bottom">
                                        <em class="status-badge <?= msStatusClass($ev['status']) ?>"><?= htmlspecialchars($ev['status']) ?></em>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="master-section-card">
            <div class="profile-section-head">
                <div>
                    <span class="label">Записи</span>
                    <h2><?= msDateRu($selectedDate) ?></h2>
                </div>
            </div>

            <?php if (empty($appointments)): ?>
                <p class="empty-text">На выбранную дату записей нет.</p>
            <?php else: ?>
                <div class="master-records">
                    <?php foreach ($appointments as $item): ?>
                        <?php
                            $date = $item['start_datetime'] ? date('Y-m-d', strtotime($item['start_datetime'])) : $selectedDate;
                            $time = $item['start_datetime'] ? date('H:i', strtotime($item['start_datetime'])) : '';
                        ?>

                        <article class="master-record-card">
                            <div class="master-record-time <?= msTimeStatusClass($item['status']) ?>">
                                <strong><?= msTimeRu($item['start_datetime']) ?></strong>
                                <span><?= msTimeRu($item['end_datetime']) ?></span>
                            </div>

                            <div class="master-record-content">
                                <div class="record-card__top">
                                    <div>
                                        <h3><?= htmlspecialchars($item['service_name'] ?? 'Услуга не указана') ?></h3>

                                        <p>
                                            <?= htmlspecialchars($item['client_name'] ?: ($item['client_full_name'] ?? 'Клиент')) ?>
                                            ·
                                            <?= htmlspecialchars($item['phone'] ?? 'телефон не указан') ?>
                                        </p>
                                    </div>

                                    <span class="status-badge <?= msStatusClass($item['status']) ?>">
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
                                        <span>Оплата мастера</span>
                                        <strong><?= number_format((float)$item['master_income'], 0, '.', ' ') ?> ₽</strong>
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

                                <details class="master-more">
                                    <summary>Еще</summary>

                                    <div class="master-actions">
                                        <form method="post" action="master-schedule.php?date=<?= htmlspecialchars($selectedDate) ?>" class="master-inline-form">
                                            <input type="hidden" name="action" value="change_status">
                                            <input type="hidden" name="appointment_id" value="<?= (int)$item['id'] ?>">

                                            <select name="status">
                                                <option value="Подтверждена" <?= $item['status'] === 'Подтверждена' ? 'selected' : '' ?>>
                                                    Подтверждена
                                                </option>

                                                <option value="В работе" <?= $item['status'] === 'В работе' ? 'selected' : '' ?>>
                                                    В работе
                                                </option>

                                                <option value="Оказана" <?= $item['status'] === 'Оказана' ? 'selected' : '' ?>>
                                                    Оказана
                                                </option>

                                                <option value="Отменена" <?= $item['status'] === 'Отменена' ? 'selected' : '' ?>>
                                                    Отменена
                                                </option>
                                            </select>

                                            <button type="submit" class="btn btn--orange btn--small">
                                                Сохранить статус
                                            </button>
                                        </form>

                                        <form method="post" action="master-schedule.php?date=<?= htmlspecialchars($selectedDate) ?>" class="master-inline-form master-inline-form--move">
                                            <input type="hidden" name="action" value="move_appointment">
                                            <input type="hidden" name="appointment_id" value="<?= (int)$item['id'] ?>">

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

                                            <button type="submit" class="btn btn--light btn--small">
                                                Перенести
                                            </button>
                                        </form>

                                        <form method="post" action="master-schedule.php?date=<?= htmlspecialchars($selectedDate) ?>" class="master-inline-form">
                                            <input type="hidden" name="action" value="transfer_master">
                                            <input type="hidden" name="appointment_id" value="<?= (int)$item['id'] ?>">

                                            <select name="new_employee_id">
                                                <option value="">Передать мастеру</option>

                                                <?php foreach ($allMasters as $master): ?>
                                                    <?php if ((int)$master['id'] === $employeeId) continue; ?>

                                                    <option value="<?= (int)$master['id'] ?>">
                                                        <?= htmlspecialchars($master['full_name']) ?>
                                                        — <?= htmlspecialchars($master['position']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>

                                            <button type="submit" class="btn btn--light btn--small">
                                                Передать
                                            </button>
                                        </form>
                                    </div>
                                </details>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>