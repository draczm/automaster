<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'client') {
    if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'employee') {
        header('Location: admin/index.php');
        exit;
    }

    header('Location: index.php');
    exit;
}

if (empty($_SESSION['client_id'])) {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}

$clientId = (int)$_SESSION['client_id'];

$clientStmt = $pdo->prepare("
    SELECT *
    FROM clients
    WHERE id = ?
    LIMIT 1
");
$clientStmt->execute([$clientId]);
$client = $clientStmt->fetch();

if (!$client) {
    session_unset();
    session_destroy();

    header('Location: login.php');
    exit;
}

$_SESSION['full_name'] = $client['full_name'];
$_SESSION['bonus_points'] = (int)($client['bonus_points'] ?? 0);

$carError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_car') {
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $vin = trim($_POST['vin'] ?? '');
    $licensePlate = trim($_POST['license_plate'] ?? '');

    if ($brand === '' || $model === '') {
        $carError = 'Укажите марку и модель автомобиля.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO cars (
                    brand,
                    model,
                    year,
                    vin,
                    license_plate,
                    client_id
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $brand,
                $model,
                $year !== '' ? (int)$year : null,
                $vin !== '' ? $vin : null,
                $licensePlate !== '' ? $licensePlate : null,
                $clientId
            ]);

            $_SESSION['toast_message'] = 'Автомобиль добавлен в личный кабинет.';

            header('Location: profile.php');
            exit;
        } catch (Throwable $e) {
            $carError = 'Не удалось добавить автомобиль. Проверьте VIN или госномер.';
        }
    }
}

$carsStmt = $pdo->prepare("
    SELECT *
    FROM cars
    WHERE client_id = ?
    ORDER BY id DESC
");
$carsStmt->execute([$clientId]);
$cars = $carsStmt->fetchAll();

$activeStmt = $pdo->prepare("
    SELECT
        appointments.*,
        services.name AS service_name,
        services.category AS service_category,
        services.price AS service_price,
        employees.full_name AS master_name,
        employees.position AS master_position
    FROM appointments
    LEFT JOIN services ON services.id = appointments.service_id
    LEFT JOIN employees ON employees.id = appointments.employee_id
    WHERE appointments.client_id = ?
      AND appointments.status IN ('Новая', 'Подтверждена', 'В работе', 'Перенесена')
    ORDER BY appointments.start_datetime ASC, appointments.created_at DESC
");
$activeStmt->execute([$clientId]);
$activeAppointments = $activeStmt->fetchAll();

$historyStmt = $pdo->prepare("
    SELECT
        appointments.*,
        services.name AS service_name,
        services.category AS service_category,
        services.price AS service_price,
        employees.full_name AS master_name,
        employees.position AS master_position
    FROM appointments
    LEFT JOIN services ON services.id = appointments.service_id
    LEFT JOIN employees ON employees.id = appointments.employee_id
    WHERE appointments.client_id = ?
      AND appointments.status IN ('Оказана', 'Выполнена', 'Отменена')
    ORDER BY COALESCE(appointments.completed_at, appointments.start_datetime, appointments.created_at) DESC
");
$historyStmt->execute([$clientId]);
$serviceHistory = $historyStmt->fetchAll();

$bonusStmt = $pdo->prepare("
    SELECT
        bonus_transactions.*,
        orders.order_number
    FROM bonus_transactions
    LEFT JOIN orders ON orders.id = bonus_transactions.order_id
    WHERE bonus_transactions.client_id = ?
    ORDER BY bonus_transactions.created_at DESC
");
$bonusStmt->execute([$clientId]);
$bonusHistory = $bonusStmt->fetchAll();

$bonusStatsStmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN operation_type = 'Начисление' THEN points ELSE 0 END) AS earned_points,
        SUM(CASE WHEN operation_type = 'Списание' THEN ABS(points) ELSE 0 END) AS spent_points
    FROM bonus_transactions
    WHERE client_id = ?
");
$bonusStatsStmt->execute([$clientId]);
$bonusStats = $bonusStatsStmt->fetch();

function profileDateRu($value)
{
    if (!$value) {
        return 'не указано';
    }

    return date('d.m.Y', strtotime($value));
}

function profileDateTimeRu($value)
{
    if (!$value) {
        return 'не указано';
    }

    return date('d.m.Y H:i', strtotime($value));
}

function profileStatusClass($status)
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

$createdDate = $client['created_at'] ?? ($client['created_date'] ?? null);

$toastMessage = $_SESSION['toast_message'] ?? '';
unset($_SESSION['toast_message']);

require_once 'includes/header.php';
?>

<section class="profile-page">
    <div class="container">
        <div class="profile-hero">
            <div>
                <span class="label">Личный кабинет</span>

                <h1><?= htmlspecialchars($client['full_name']) ?></h1>

                <p>
                    Здесь отображаются автомобили, активные записи, предыдущие ТО,
                    история обслуживания и движение бонусных баллов.
                </p>
            </div>

            <div class="profile-bonus-card">
                <span>Баланс бонусов</span>
                <strong><?= (int)($client['bonus_points'] ?? 0) ?></strong>
                <button type="button" id="bonusHelpBtnProfile">Как использовать баллы?</button>
            </div>
        </div>

        <div class="profile-grid">
            <aside class="profile-side">
                <div class="profile-card">
                    <h2>Данные клиента</h2>

                    <div class="profile-info-list">
                        <div>
                            <span>Телефон</span>
                            <strong><?= htmlspecialchars($client['phone'] ?? 'не указан') ?></strong>
                        </div>

                        <div>
                            <span>Email</span>
                            <strong><?= htmlspecialchars($client['email'] ?? 'не указан') ?></strong>
                        </div>

                        <div>
                            <span>Адрес</span>
                            <strong><?= htmlspecialchars($client['address'] ?? 'не указан') ?></strong>
                        </div>

                        <div>
                            <span>Дата регистрации</span>
                            <strong><?= profileDateRu($createdDate) ?></strong>
                        </div>
                    </div>
                </div>

                <div class="profile-card">
                    <h2>Автомобили</h2>

                    <?php if ($carError): ?>
                        <div class="alert alert-error">
                            <?= htmlspecialchars($carError) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="profile.php" class="mini-car-form">
                        <input type="hidden" name="action" value="add_car">

                        <div class="form-group">
                            <label for="brand">Марка *</label>
                            <input type="text" id="brand" name="brand" placeholder="Toyota" required>
                        </div>

                        <div class="form-group">
                            <label for="model">Модель *</label>
                            <input type="text" id="model" name="model" placeholder="Camry" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="year">Год</label>
                                <input
                                    type="number"
                                    id="year"
                                    name="year"
                                    min="1980"
                                    max="<?= date('Y') + 1 ?>"
                                    placeholder="2018"
                                >
                            </div>

                            <div class="form-group">
                                <label for="license_plate">Госномер</label>
                                <input type="text" id="license_plate" name="license_plate" placeholder="А123ВС12">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="vin">VIN</label>
                            <input type="text" id="vin" name="vin" placeholder="XTA...">
                        </div>

                        <button type="submit" class="btn btn--orange btn--small">
                            Добавить авто
                        </button>
                    </form>

                    <?php if (empty($cars)): ?>
                        <p class="empty-text">Автомобили пока не добавлены.</p>
                    <?php else: ?>
                        <div class="car-list">
                            <?php foreach ($cars as $car): ?>
                                <div class="car-item">
                                    <strong>
                                        <?= htmlspecialchars($car['brand']) ?>
                                        <?= htmlspecialchars($car['model']) ?>
                                    </strong>

                                    <span>
                                        <?= htmlspecialchars($car['year'] ?? 'год не указан') ?>
                                        ·
                                        <?= htmlspecialchars($car['license_plate'] ?? 'номер не указан') ?>
                                    </span>

                                    <?php if (!empty($car['vin'])): ?>
                                        <span>VIN: <?= htmlspecialchars($car['vin']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="profile-card">
                    <h2>Бонусы</h2>

                    <div class="profile-info-list">
                        <div>
                            <span>Начислено всего</span>
                            <strong><?= (int)($bonusStats['earned_points'] ?? 0) ?> баллов</strong>
                        </div>

                        <div>
                            <span>Списано всего</span>
                            <strong><?= (int)($bonusStats['spent_points'] ?? 0) ?> баллов</strong>
                        </div>

                        <div>
                            <span>Текущий баланс</span>
                            <strong><?= (int)($client['bonus_points'] ?? 0) ?> баллов</strong>
                        </div>
                    </div>
                </div>
            </aside>

            <div class="profile-main">
                <section class="profile-section-card">
                    <div class="profile-section-head">
                        <div>
                            <span class="label">Записи</span>
                            <h2>Активные записи</h2>
                        </div>

                        <a href="appointment.php" class="btn btn--orange">Новая запись</a>
                    </div>

                    <?php if (empty($activeAppointments)): ?>
                        <p class="empty-text">Активных записей нет.</p>
                    <?php else: ?>
                        <div class="records-list">
                            <?php foreach ($activeAppointments as $item): ?>
                                <article class="record-card">
                                    <div class="record-card__top">
                                        <div>
                                            <h3><?= htmlspecialchars($item['service_name'] ?? 'Услуга не указана') ?></h3>

                                            <p>
                                                <?= htmlspecialchars($item['car_brand'] ?? '') ?>
                                                <?= htmlspecialchars($item['car_model'] ?? '') ?>

                                                <?php if (!empty($item['license_plate'])): ?>
                                                    · <?= htmlspecialchars($item['license_plate']) ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>

                                        <span class="status-badge <?= profileStatusClass($item['status']) ?>">
                                            <?= htmlspecialchars($item['status']) ?>
                                        </span>
                                    </div>

                                    <div class="record-card__meta">
                                        <div>
                                            <span>Дата и время</span>
                                            <strong><?= profileDateTimeRu($item['start_datetime']) ?></strong>
                                        </div>

                                        <div>
                                            <span>Мастер</span>
                                            <strong><?= htmlspecialchars($item['master_name'] ?? 'не назначен') ?></strong>
                                        </div>

                                        <div>
                                            <span>Стоимость</span>
                                            <strong><?= number_format((float)$item['service_price_at_time'], 0, '.', ' ') ?> ₽</strong>
                                        </div>
                                    </div>

                                    <?php if (!empty($item['work_description'])): ?>
                                        <p class="record-card__comment">
                                            <?= htmlspecialchars($item['work_description']) ?>
                                        </p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="profile-section-card">
                    <div class="profile-section-head">
                        <div>
                            <span class="label">ТО и ремонты</span>
                            <h2>История обслуживания</h2>
                        </div>
                    </div>

                    <?php if (empty($serviceHistory)): ?>
                        <p class="empty-text">История обслуживания пока пустая.</p>
                    <?php else: ?>
                        <div class="records-list">
                            <?php foreach ($serviceHistory as $item): ?>
                                <article class="record-card record-card--history">
                                    <div class="record-card__top">
                                        <div>
                                            <h3><?= htmlspecialchars($item['service_name'] ?? 'Услуга не указана') ?></h3>

                                            <p>
                                                <?= htmlspecialchars($item['car_brand'] ?? '') ?>
                                                <?= htmlspecialchars($item['car_model'] ?? '') ?>

                                                <?php if (!empty($item['car_year'])): ?>
                                                    · <?= (int)$item['car_year'] ?> г.
                                                <?php endif; ?>

                                                <?php if (!empty($item['license_plate'])): ?>
                                                    · <?= htmlspecialchars($item['license_plate']) ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>

                                        <span class="status-badge <?= profileStatusClass($item['status']) ?>">
                                            <?= htmlspecialchars($item['status']) ?>
                                        </span>
                                    </div>

                                    <div class="record-card__meta">
                                        <div>
                                            <span>Дата обслуживания</span>
                                            <strong>
                                                <?= profileDateTimeRu($item['completed_at'] ?: $item['start_datetime']) ?>
                                            </strong>
                                        </div>

                                        <div>
                                            <span>Мастер</span>
                                            <strong><?= htmlspecialchars($item['master_name'] ?? 'не назначен') ?></strong>
                                        </div>

                                        <div>
                                            <span>Стоимость</span>
                                            <strong><?= number_format((float)$item['service_price_at_time'], 0, '.', ' ') ?> ₽</strong>
                                        </div>
                                    </div>

                                    <?php if (!empty($item['work_description'])): ?>
                                        <p class="record-card__comment">
                                            <?= htmlspecialchars($item['work_description']) ?>
                                        </p>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="profile-section-card">
                    <div class="profile-section-head">
                        <div>
                            <span class="label">Бонусы</span>
                            <h2>История начисления баллов</h2>
                        </div>
                    </div>

                    <?php if (empty($bonusHistory)): ?>
                        <p class="empty-text">Операций с бонусами пока нет.</p>
                    <?php else: ?>
                        <div class="bonus-history-list">
                            <?php foreach ($bonusHistory as $bonus): ?>
                                <article class="bonus-history-item">
                                    <div>
                                        <strong>
                                            <?= htmlspecialchars($bonus['description'] ?? 'Бонусная операция') ?>
                                        </strong>

                                        <span>
                                            <?= profileDateTimeRu($bonus['created_at']) ?>

                                            <?php if (!empty($bonus['order_number'])): ?>
                                                · заказ <?= htmlspecialchars($bonus['order_number']) ?>
                                            <?php endif; ?>
                                        </span>
                                    </div>

                                    <div class="bonus-points <?= $bonus['operation_type'] === 'Списание' ? 'bonus-points--minus' : 'bonus-points--plus' ?>">
                                        <?= $bonus['operation_type'] === 'Списание' ? '-' : '+' ?>
                                        <?= abs((int)$bonus['points']) ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>
    </div>
</section>

<?php if ($toastMessage): ?>
    <script>
        window.addEventListener('DOMContentLoaded', function () {
            if (window.showToast) {
                window.showToast(<?= json_encode($toastMessage, JSON_UNESCAPED_UNICODE) ?>);
            }
        });
    </script>
<?php endif; ?>

<script>
    window.addEventListener('DOMContentLoaded', function () {
        const profileBonusBtn = document.getElementById('bonusHelpBtnProfile');
        const headerBonusBtn = document.getElementById('bonusHelpBtn');

        if (profileBonusBtn && headerBonusBtn) {
            profileBonusBtn.addEventListener('click', function () {
                headerBonusBtn.click();
            });
        }
    });
</script>

<?php require_once 'includes/footer.php'; ?>