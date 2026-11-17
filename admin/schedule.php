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

function calendarDateRu($value)
{
    return date('d.m', strtotime($value));
}

function calendarFullDateRu($value)
{
    return date('d.m.Y', strtotime($value));
}

function calendarTime($value)
{
    if (!$value) {
        return '—';
    }

    return date('H:i', strtotime($value));
}

function calendarStatusClass($status)
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

function calendarStatusBadgeClass($status)
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

$baseDate = $_GET['week'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate)) {
    $baseDate = date('Y-m-d');
}

$selectedMasterId = (int)($_GET['master_id'] ?? 0);

$weekStart = date('Y-m-d', strtotime('monday this week', strtotime($baseDate)));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +4 days'));

$prevWeek = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeek = date('Y-m-d', strtotime($weekStart . ' +7 days'));
$currentWeek = date('Y-m-d');

$workStart = '08:30';
$workEnd = '21:00';
$slotMinutes = 30;
$slotHeight = 58;

$workStartMinutes = 8 * 60 + 30;
$workEndMinutes = 21 * 60;
$totalWorkMinutes = $workEndMinutes - $workStartMinutes;
$totalCalendarHeight = ($totalWorkMinutes / $slotMinutes) * $slotHeight;

$days = [];

$dayNames = [
    'Понедельник',
    'Вторник',
    'Среда',
    'Четверг',
    'Пятница'
];

for ($i = 0; $i < 5; $i++) {
    $date = date('Y-m-d', strtotime($weekStart . " +{$i} days"));

    $days[] = [
        'date' => $date,
        'name' => $dayNames[$i],
        'short' => mb_substr($dayNames[$i], 0, 2)
    ];
}

$timeSlots = [];

for ($minutes = $workStartMinutes; $minutes < $workEndMinutes; $minutes += $slotMinutes) {
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    $timeSlots[] = sprintf('%02d:%02d', $hours, $mins);
}

$mastersStmt = $pdo->query("
    SELECT id, full_name, position
    FROM employees
    WHERE is_master = 1
      AND work_status = 'Работает'
    ORDER BY full_name
");
$masters = $mastersStmt->fetchAll();

$where = [
    'DATE(appointments.start_datetime) BETWEEN ? AND ?'
];

$params = [
    $weekStart,
    $weekEnd
];

if ($selectedMasterId > 0) {
    $where[] = 'appointments.employee_id = ?';
    $params[] = $selectedMasterId;
}

$whereSql = implode(' AND ', $where);

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
    WHERE {$whereSql}
    ORDER BY appointments.start_datetime ASC
");
$stmt->execute($params);
$appointments = $stmt->fetchAll();

$appointmentsByDate = [];

foreach ($days as $day) {
    $appointmentsByDate[$day['date']] = [];
}

foreach ($appointments as $appointment) {
    if (empty($appointment['start_datetime'])) {
        continue;
    }

    $date = date('Y-m-d', strtotime($appointment['start_datetime']));

    if (!isset($appointmentsByDate[$date])) {
        continue;
    }

    $startHour = (int)date('H', strtotime($appointment['start_datetime']));
    $startMinute = (int)date('i', strtotime($appointment['start_datetime']));
    $startMinutes = $startHour * 60 + $startMinute;

    if (!empty($appointment['end_datetime'])) {
        $endHour = (int)date('H', strtotime($appointment['end_datetime']));
        $endMinute = (int)date('i', strtotime($appointment['end_datetime']));
        $endMinutes = $endHour * 60 + $endMinute;
    } else {
        $duration = (int)($appointment['service_duration_minutes'] ?? 60);
        $endMinutes = $startMinutes + max(30, $duration);
    }

    if ($endMinutes <= $workStartMinutes || $startMinutes >= $workEndMinutes) {
        continue;
    }

    $clampedStart = max($startMinutes, $workStartMinutes);
    $clampedEnd = min($endMinutes, $workEndMinutes);

    $top = (($clampedStart - $workStartMinutes) / $slotMinutes) * $slotHeight;
    $height = max(44, (($clampedEnd - $clampedStart) / $slotMinutes) * $slotHeight - 6);

    $appointment['calendar_top'] = (int)$top;
    $appointment['calendar_height'] = (int)$height;
    $appointment['calendar_start'] = sprintf('%02d:%02d', intdiv($startMinutes, 60), $startMinutes % 60);
    $appointment['calendar_end'] = sprintf('%02d:%02d', intdiv($endMinutes, 60), $endMinutes % 60);

    $appointmentsByDate[$date][] = $appointment;
}

$totalAppointments = count($appointments);
$activeAppointments = 0;
$doneAppointments = 0;
$cancelAppointments = 0;

foreach ($appointments as $appointment) {
    if (in_array($appointment['status'], ['Новая', 'Подтверждена', 'В работе', 'Перенесена'], true)) {
        $activeAppointments++;
    }

    if (in_array($appointment['status'], ['Оказана', 'Выполнена'], true)) {
        $doneAppointments++;
    }

    if ($appointment['status'] === 'Отменена') {
        $cancelAppointments++;
    }
}

require_once '../includes/header.php';
?>

<section class="admin-page">
    <div class="container">
        <div class="admin-hero admin-hero--single">
            <div>
                <span class="label">Администрирование</span>

                <h1>Календарь записей</h1>

                <p>
                    Недельное расписание автомастерской с понедельника по пятницу.
                    Рабочее время: с 08:30 до 21:00.
                </p>
            </div>
        </div>

        <div class="admin-stats-grid admin-stats-grid--calendar">
            <article class="admin-stat-card">
                <span>Неделя</span>
                <strong><?= calendarDateRu($weekStart) ?>–<?= calendarDateRu($weekEnd) ?></strong>
                <p><?= date('Y', strtotime($weekStart)) ?> год</p>
            </article>

            <article class="admin-stat-card">
                <span>Всего записей</span>
                <strong><?= (int)$totalAppointments ?></strong>
                <p>по текущей неделе</p>
            </article>

            <article class="admin-stat-card">
                <span>Активные</span>
                <strong><?= (int)$activeAppointments ?></strong>
                <p>новые, подтвержденные, в работе</p>
            </article>

            <article class="admin-stat-card">
                <span>Завершено</span>
                <strong><?= (int)$doneAppointments ?></strong>
                <p><?= (int)$cancelAppointments ?> отменено</p>
            </article>
        </div>

        <section class="admin-section-card admin-calendar-toolbar">
            <div class="admin-calendar-nav">
                <a
                    href="schedule.php?week=<?= htmlspecialchars($prevWeek) ?>&master_id=<?= (int)$selectedMasterId ?>"
                    class="btn btn--light btn--small"
                >
                    ← Прошлая неделя
                </a>

                <a
                    href="schedule.php?week=<?= htmlspecialchars($currentWeek) ?>&master_id=<?= (int)$selectedMasterId ?>"
                    class="btn btn--orange btn--small"
                >
                    Текущая неделя
                </a>

                <a
                    href="schedule.php?week=<?= htmlspecialchars($nextWeek) ?>&master_id=<?= (int)$selectedMasterId ?>"
                    class="btn btn--light btn--small"
                >
                    Следующая неделя →
                </a>
            </div>

            <form method="get" action="schedule.php" class="admin-calendar-filter">
                <input type="hidden" name="week" value="<?= htmlspecialchars($weekStart) ?>">

                <div class="form-group">
                    <label for="master_id">Мастер</label>

                    <select id="master_id" name="master_id">
                        <option value="0">Все мастера</option>

                        <?php foreach ($masters as $master): ?>
                            <option
                                value="<?= (int)$master['id'] ?>"
                                <?= $selectedMasterId === (int)$master['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($master['full_name']) ?>
                                — <?= htmlspecialchars($master['position']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn--orange btn--small">
                    Показать
                </button>
            </form>
        </section>

        <section class="admin-section-card admin-calendar-section">
            <div class="admin-section-head">
                <div>
                    <span class="label">Расписание</span>
                    <h2>Пн–Пт, <?= calendarFullDateRu($weekStart) ?> — <?= calendarFullDateRu($weekEnd) ?></h2>
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
                    <div class="admin-calendar-corner">
                        Время
                    </div>

                    <?php foreach ($days as $day): ?>
                        <div class="admin-calendar-day-head <?= $day['date'] === date('Y-m-d') ? 'is-today' : '' ?>">
                            <strong><?= htmlspecialchars($day['name']) ?></strong>
                            <span><?= calendarFullDateRu($day['date']) ?></span>
                        </div>
                    <?php endforeach; ?>

                    <div
                        class="admin-calendar-time-column"
                        style="height: <?= (int)$totalCalendarHeight ?>px;"
                    >
                        <?php foreach ($timeSlots as $index => $slot): ?>
                            <div
                                class="admin-calendar-time"
                                style="top: <?= (int)($index * $slotHeight) ?>px;"
                            >
                                <?= htmlspecialchars($slot) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php foreach ($days as $day): ?>
                        <div
                            class="admin-calendar-day-column <?= $day['date'] === date('Y-m-d') ? 'is-today' : '' ?>"
                            style="height: <?= (int)$totalCalendarHeight ?>px;"
                        >
                            <?php foreach ($timeSlots as $index => $slot): ?>
                                <div
                                    class="admin-calendar-line"
                                    style="top: <?= (int)($index * $slotHeight) ?>px;"
                                ></div>
                            <?php endforeach; ?>

                            <?php if (empty($appointmentsByDate[$day['date']])): ?>
                                <div class="admin-calendar-empty-day">
                                    Записей нет
                                </div>
                            <?php endif; ?>

                            <?php foreach ($appointmentsByDate[$day['date']] as $appointment): ?>
                                <article
                                    class="admin-calendar-event <?= calendarStatusClass($appointment['status']) ?>"
                                    style="top: <?= (int)$appointment['calendar_top'] ?>px; height: <?= (int)$appointment['calendar_height'] ?>px;"
                                >
                                    <div class="admin-calendar-event__time">
                                        <?= htmlspecialchars($appointment['calendar_start']) ?>
                                        —
                                        <?= htmlspecialchars($appointment['calendar_end']) ?>
                                    </div>

                                    <strong>
                                        <?= htmlspecialchars($appointment['service_name'] ?? 'Услуга не указана') ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars($appointment['client_name'] ?? 'Клиент') ?>
                                    </span>

                                    <span>
                                        <?= htmlspecialchars($appointment['car_brand'] ?? '') ?>
                                        <?= htmlspecialchars($appointment['car_model'] ?? '') ?>
                                    </span>

                                    <span>
                                        <?= htmlspecialchars($appointment['master_name'] ?? 'мастер не назначен') ?>
                                    </span>

                                    <div class="admin-calendar-event__bottom">
                                        <em class="status-badge <?= calendarStatusBadgeClass($appointment['status']) ?>">
                                            <?= htmlspecialchars($appointment['status']) ?>
                                        </em>

                                        <a href="appointments.php?search=<?= urlencode($appointment['phone'] ?? '') ?>">
                                            Открыть
                                        </a>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>
</section>

<?php require_once '../includes/footer.php'; ?>