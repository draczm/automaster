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

function adminScalar($pdo, $sql, $params = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function adminMoney($value)
{
    return number_format((float)$value, 0, '.', ' ') . ' ₽';
}

function adminDateTime($value)
{
    if (!$value) {
        return 'не указано';
    }

    return date('d.m.Y H:i', strtotime($value));
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

function chartPercent($value, $max)
{
    $value = (float)$value;
    $max = (float)$max;

    if ($max <= 0) {
        return 0;
    }

    return max(4, min(100, round(($value / $max) * 100, 1)));
}

function svgLinePoints($items, $valueKey, $width = 640, $height = 220, $padding = 28)
{
    $values = array_map(fn($item) => (float)$item[$valueKey], $items);
    $max = max($values);

    if ($max <= 0) {
        $max = 1;
    }

    $count = count($items);

    if ($count <= 1) {
        return '';
    }

    $points = [];

    foreach ($items as $index => $item) {
        $x = $padding + ($index * (($width - $padding * 2) / ($count - 1)));
        $y = $height - $padding - (((float)$item[$valueKey] / $max) * ($height - $padding * 2));

        $points[] = round($x, 1) . ',' . round($y, 1);
    }

    return implode(' ', $points);
}

$today = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-6 days'));

$summary = [
    'appointments_total' => adminScalar($pdo, "SELECT COUNT(*) FROM appointments"),
    'appointments_active' => adminScalar($pdo, "
        SELECT COUNT(*)
        FROM appointments
        WHERE status IN ('Новая', 'Подтверждена', 'В работе', 'Перенесена')
    "),
    'appointments_done' => adminScalar($pdo, "
        SELECT COUNT(*)
        FROM appointments
        WHERE status IN ('Оказана', 'Выполнена')
    "),
    'revenue_done' => adminScalar($pdo, "
        SELECT COALESCE(SUM(service_price_at_time), 0)
        FROM appointments
        WHERE status IN ('Оказана', 'Выполнена')
    ")
];

$revenueRawStmt = $pdo->prepare("
    SELECT
        DATE(start_datetime) AS day_date,
        COALESCE(SUM(service_price_at_time), 0) AS revenue_sum,
        COUNT(*) AS orders_count
    FROM appointments
    WHERE DATE(start_datetime) BETWEEN ? AND ?
      AND status IN ('Оказана', 'Выполнена')
    GROUP BY DATE(start_datetime)
");
$revenueRawStmt->execute([$weekAgo, $today]);
$revenueRaw = $revenueRawStmt->fetchAll(PDO::FETCH_ASSOC);

$revenueMap = [];

foreach ($revenueRaw as $row) {
    $revenueMap[$row['day_date']] = $row;
}

$revenueChart = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));

    $revenueChart[] = [
        'date' => $date,
        'label' => date('d.m', strtotime($date)),
        'revenue' => isset($revenueMap[$date]) ? (float)$revenueMap[$date]['revenue_sum'] : 0,
        'orders' => isset($revenueMap[$date]) ? (int)$revenueMap[$date]['orders_count'] : 0
    ];
}

$maxRevenue = max(array_map(fn($item) => (float)$item['revenue'], $revenueChart));
$linePoints = svgLinePoints($revenueChart, 'revenue');

$statusStmt = $pdo->query("
    SELECT status, COUNT(*) AS count_items
    FROM appointments
    GROUP BY status
    ORDER BY count_items DESC
");
$statusChart = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
$maxStatusCount = max(array_merge([1], array_map(fn($item) => (int)$item['count_items'], $statusChart)));

$weekdayRawStmt = $pdo->prepare("
    SELECT
        DAYOFWEEK(start_datetime) AS weekday_num,
        COUNT(*) AS count_items
    FROM appointments
    WHERE DATE(start_datetime) BETWEEN ? AND ?
    GROUP BY DAYOFWEEK(start_datetime)
");
$weekdayRawStmt->execute([
    date('Y-m-d', strtotime('monday this week')),
    date('Y-m-d', strtotime('friday this week'))
]);
$weekdayRaw = $weekdayRawStmt->fetchAll(PDO::FETCH_ASSOC);

$weekdayMap = [];

foreach ($weekdayRaw as $row) {
    $weekdayMap[(int)$row['weekday_num']] = (int)$row['count_items'];
}

$weekdayChart = [
    ['label' => 'Пн', 'count' => $weekdayMap[2] ?? 0],
    ['label' => 'Вт', 'count' => $weekdayMap[3] ?? 0],
    ['label' => 'Ср', 'count' => $weekdayMap[4] ?? 0],
    ['label' => 'Чт', 'count' => $weekdayMap[5] ?? 0],
    ['label' => 'Пт', 'count' => $weekdayMap[6] ?? 0]
];

$maxWeekdayCount = max(array_merge([1], array_map(fn($item) => (int)$item['count'], $weekdayChart)));

$serviceStatsStmt = $pdo->query("
    SELECT
        services.name,
        COUNT(appointments.id) AS orders_count,
        COALESCE(SUM(
            CASE
                WHEN appointments.status IN ('Оказана', 'Выполнена')
                THEN appointments.service_price_at_time
                ELSE 0
            END
        ), 0) AS revenue_sum
    FROM services
    LEFT JOIN appointments ON appointments.service_id = services.id
    GROUP BY services.id, services.name
    ORDER BY orders_count DESC, revenue_sum DESC
    LIMIT 6
");
$serviceStats = $serviceStatsStmt->fetchAll(PDO::FETCH_ASSOC);
$maxServiceOrders = max(array_merge([1], array_map(fn($item) => (int)$item['orders_count'], $serviceStats)));

$masterStatsStmt = $pdo->query("
    SELECT
        employees.full_name,
        employees.position,
        COUNT(appointments.id) AS orders_count,
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
    WHERE employees.is_master = 1
    GROUP BY employees.id, employees.full_name, employees.position
    ORDER BY orders_count DESC
    LIMIT 5
");
$masterStats = $masterStatsStmt->fetchAll(PDO::FETCH_ASSOC);
$maxMasterOrders = max(array_merge([1], array_map(fn($item) => (int)$item['orders_count'], $masterStats)));

$recentStmt = $pdo->query("
    SELECT
        appointments.*,
        services.name AS service_name,
        employees.full_name AS master_name
    FROM appointments
    LEFT JOIN services ON services.id = appointments.service_id
    LEFT JOIN employees ON employees.id = appointments.employee_id
    ORDER BY appointments.created_at DESC
    LIMIT 8
");
$recentAppointments = $recentStmt->fetchAll();

require_once '../includes/header.php';
?>

<section class="admin-page">
    <div class="container">
        <div class="admin-hero">
            <div>
                <span class="label">Панель администратора</span>
                <h1>Сводка работы автомастерской</h1>
                <p>
                    Здесь отображаются заявки, загрузка мастеров,
                    выручка и состояние текущих заказов.
                </p>
            </div>

            <div class="admin-hero__actions">
                <a href="appointments.php" class="btn btn--orange">
                    Управлять заявками
                </a>

                <a href="schedule.php" class="btn btn--light">
                    Открыть календарь
                </a>
            </div>
        </div>

        <section class="admin-charts-grid">
            <article class="admin-chart-card admin-chart-card--wide">
                <div class="admin-chart-head">
                    <div>
                        <span class="label">Динамика</span>
                        <h2>Выручка за 7 дней</h2>
                    </div>

                    <strong><?= adminMoney(array_sum(array_map(fn($item) => $item['revenue'], $revenueChart))) ?></strong>
                </div>

                <div class="admin-line-chart">
                    <svg viewBox="0 0 640 220" preserveAspectRatio="none" aria-hidden="true">
                        <line x1="28" y1="192" x2="612" y2="192"></line>
                        <line x1="28" y1="120" x2="612" y2="120"></line>
                        <line x1="28" y1="48" x2="612" y2="48"></line>

                        <polyline points="<?= htmlspecialchars($linePoints) ?>"></polyline>

                        <?php foreach ($revenueChart as $index => $item): ?>
                            <?php
                                $x = 28 + ($index * ((640 - 56) / 6));
                                $y = 192 - (($maxRevenue > 0 ? $item['revenue'] / $maxRevenue : 0) * 164);
                            ?>
                            <circle cx="<?= round($x, 1) ?>" cy="<?= round($y, 1) ?>" r="5"></circle>
                        <?php endforeach; ?>
                    </svg>

                    <div class="admin-line-chart__labels">
                        <?php foreach ($revenueChart as $item): ?>
                            <span>
                                <b><?= htmlspecialchars($item['label']) ?></b>
                                <small><?= adminMoney($item['revenue']) ?></small>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </article>

            <article class="admin-chart-card">
                <div class="admin-chart-head">
                    <div>
                        <span class="label">Статусы</span>
                        <h2>Состояние заявок</h2>
                    </div>

                    <strong><?= (int)$summary['appointments_total'] ?></strong>
                </div>

                <div class="admin-status-chart">
                    <?php foreach ($statusChart as $item): ?>
                        <?php $percent = chartPercent($item['count_items'], $maxStatusCount); ?>

                        <div class="admin-status-row">
                            <div class="admin-status-row__top">
                                <span><?= htmlspecialchars($item['status']) ?></span>
                                <b><?= (int)$item['count_items'] ?></b>
                            </div>

                            <div class="admin-chart-track">
                                <i style="width: <?= $percent ?>%;"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-chart-card">
                <div class="admin-chart-head">
                    <div>
                        <span class="label">Неделя</span>
                        <h2>Загрузка по дням</h2>
                    </div>

                    <strong><?= array_sum(array_map(fn($item) => $item['count'], $weekdayChart)) ?></strong>
                </div>

                <div class="admin-day-load">
                    <?php foreach ($weekdayChart as $item): ?>
                        <?php $percent = chartPercent($item['count'], $maxWeekdayCount); ?>

                        <div class="admin-day-load__row">
                            <div class="admin-day-load__name">
                                <b><?= htmlspecialchars($item['label']) ?></b>
                                <span><?= (int)$item['count'] ?> заявок</span>
                            </div>

                            <div class="admin-day-load__track">
                                <i style="width: <?= $percent ?>%;"></i>
                            </div>

                            <strong><?= (int)$item['count'] ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-chart-card">
                <div class="admin-chart-head">
                    <div>
                        <span class="label">Услуги</span>
                        <h2>Популярность</h2>
                    </div>

                    <a href="services.php" class="admin-link">Все услуги</a>
                </div>

                <div class="admin-rank-chart">
                    <?php foreach ($serviceStats as $item): ?>
                        <?php $percent = chartPercent($item['orders_count'], $maxServiceOrders); ?>

                        <div class="admin-rank-row">
                            <div>
                                <strong><?= htmlspecialchars($item['name']) ?></strong>
                                <span><?= (int)$item['orders_count'] ?> заявок · <?= adminMoney($item['revenue_sum']) ?></span>
                            </div>

                            <div class="admin-chart-track">
                                <i style="width: <?= $percent ?>%;"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-chart-card">
                <div class="admin-chart-head">
                    <div>
                        <span class="label">Мастера</span>
                        <h2>Загрузка</h2>
                    </div>

                    <a href="employees.php" class="admin-link">Все мастера</a>
                </div>

                <div class="admin-rank-chart">
                    <?php foreach ($masterStats as $item): ?>
                        <?php $percent = chartPercent($item['orders_count'], $maxMasterOrders); ?>

                        <div class="admin-rank-row">
                            <div>
                                <strong><?= htmlspecialchars($item['full_name']) ?></strong>
                                <span>
                                    <?= htmlspecialchars($item['position']) ?>
                                    · <?= (int)$item['orders_count'] ?> заявок
                                    · <?= adminMoney($item['income_sum']) ?>
                                </span>
                            </div>

                            <div class="admin-chart-track admin-chart-track--blue">
                                <i style="width: <?= $percent ?>%;"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-chart-card admin-chart-card--summary">
                <div class="admin-summary-chart">
                    <div>
                        <span>Активные заявки</span>
                        <strong><?= (int)$summary['appointments_active'] ?></strong>
                    </div>

                    <div>
                        <span>Оказано услуг</span>
                        <strong><?= (int)$summary['appointments_done'] ?></strong>
                    </div>

                    <div>
                        <span>Общая выручка</span>
                        <strong><?= adminMoney($summary['revenue_done']) ?></strong>
                    </div>
                </div>
            </article>
        </section>

        <section class="admin-section-card">
            <div class="admin-section-head">
                <div>
                    <span class="label">Заявки</span>
                    <h2>Последние обращения</h2>
                </div>

                <a href="appointments.php" class="admin-link">
                    Смотреть все
                </a>
            </div>

            <?php if (empty($recentAppointments)): ?>
                <p class="empty-text">Заявок пока нет.</p>
            <?php else: ?>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Клиент</th>
                                <th>Услуга</th>
                                <th>Дата</th>
                                <th>Мастер</th>
                                <th>Статус</th>
                                <th>Сумма</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($recentAppointments as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($item['client_name'] ?? 'Клиент') ?></strong>
                                        <span><?= htmlspecialchars($item['phone'] ?? '') ?></span>
                                    </td>

                                    <td><?= htmlspecialchars($item['service_name'] ?? 'не указана') ?></td>

                                    <td><?= adminDateTime($item['start_datetime']) ?></td>

                                    <td><?= htmlspecialchars($item['master_name'] ?? 'не назначен') ?></td>

                                    <td>
                                        <span class="status-badge <?= adminStatusClass($item['status']) ?>">
                                            <?= htmlspecialchars($item['status']) ?>
                                        </span>
                                    </td>

                                    <td><?= adminMoney($item['service_price_at_time']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</section>

<?php require_once '../includes/footer.php'; ?>