<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';
require_once 'includes/schedule_helpers.php';

$error = '';
$success = '';

$currentClient = null;
$clientCars = [];

if (isset($_SESSION['client_id']) && $_SESSION['client_id']) {
    $clientStmt = $pdo->prepare("
        SELECT *
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");
    $clientStmt->execute([(int)$_SESSION['client_id']]);
    $currentClient = $clientStmt->fetch();

    if ($currentClient) {
        $carsStmt = $pdo->prepare("
            SELECT *
            FROM cars
            WHERE client_id = ?
            ORDER BY id DESC
        ");
        $carsStmt->execute([(int)$currentClient['id']]);
        $clientCars = $carsStmt->fetchAll();
    }
}

$servicesStmt = $pdo->query("
    SELECT
        id,
        name,
        price,
        duration_minutes,
        duration_hours,
        category
    FROM services
    WHERE is_active = 1
    ORDER BY category, name
");
$services = $servicesStmt->fetchAll();

$old = [
    'client_name' => $currentClient['full_name'] ?? '',
    'phone' => $currentClient['phone'] ?? '',
    'service_id' => (int)($_GET['service_id'] ?? 0),
    'preferred_date' => '',
    'preferred_time' => '',
    'employee_id' => '',
    'car_id' => '',
    'car_brand' => '',
    'car_model' => '',
    'car_year' => '',
    'license_plate' => '',
    'work_description' => '',
    'comment' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = [
        'client_name' => trim($_POST['client_name'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'service_id' => (int)($_POST['service_id'] ?? 0),
        'preferred_date' => trim($_POST['preferred_date'] ?? ''),
        'preferred_time' => trim($_POST['preferred_time'] ?? ''),
        'employee_id' => (int)($_POST['employee_id'] ?? 0),
        'car_id' => (int)($_POST['car_id'] ?? 0),
        'car_brand' => trim($_POST['car_brand'] ?? ''),
        'car_model' => trim($_POST['car_model'] ?? ''),
        'car_year' => trim($_POST['car_year'] ?? ''),
        'license_plate' => trim($_POST['license_plate'] ?? ''),
        'work_description' => trim($_POST['work_description'] ?? ''),
        'comment' => trim($_POST['comment'] ?? '')
    ];

    if ($old['client_name'] === '' || $old['phone'] === '') {
        $error = 'Укажите ФИО и телефон.';
    } elseif ($old['service_id'] <= 0) {
        $error = 'Выберите услугу.';
    } elseif (!cr_valid_date($old['preferred_date'])) {
        $error = 'Выберите корректную дату.';
    } elseif (!cr_valid_time($old['preferred_time'])) {
        $error = 'Выберите свободное время.';
    } elseif ($old['employee_id'] <= 0) {
        $error = 'Выберите свободного мастера.';
    } elseif ($old['car_brand'] === '' || $old['car_model'] === '') {
        $error = 'Укажите марку и модель автомобиля.';
    } elseif ($old['work_description'] === '') {
        $error = 'Укажите, что нужно сделать с автомобилем.';
    } else {
        $service = cr_get_service($pdo, $old['service_id']);

        if (!$service) {
            $error = 'Выбранная услуга недоступна.';
        } else {
            $availableMasters = cr_get_available_masters(
                $pdo,
                $old['service_id'],
                $old['preferred_date'],
                $old['preferred_time']
            );

            $selectedMaster = null;

            foreach ($availableMasters as $master) {
                if ((int)$master['id'] === (int)$old['employee_id']) {
                    $selectedMaster = $master;
                    break;
                }
            }

            if (!$selectedMaster) {
                $error = 'Выбранный мастер уже занят. Выберите другое время или мастера.';
            } else {
                $clientId = $currentClient['id'] ?? null;
                $carId = null;

                if ($clientId && $old['car_id'] > 0) {
                    $carCheckStmt = $pdo->prepare("
                        SELECT id
                        FROM cars
                        WHERE id = ?
                          AND client_id = ?
                        LIMIT 1
                    ");
                    $carCheckStmt->execute([$old['car_id'], $clientId]);
                    $carRow = $carCheckStmt->fetch();

                    if ($carRow) {
                        $carId = (int)$carRow['id'];
                    }
                }

                $durationMinutes = cr_service_duration_minutes($service);

                $start = new DateTimeImmutable(
                    $old['preferred_date'] . ' ' . cr_time_short($old['preferred_time']) . ':00'
                );
                $end = $start->modify('+' . $durationMinutes . ' minutes');

                $price = (float)$service['price'];
                $commission = (float)$selectedMaster['commission_percent'];
                $masterIncome = round($price * $commission / 100, 2);

                $createdByRole = $_SESSION['role'] ?? 'client';

                $stmt = $pdo->prepare("
                    INSERT INTO appointments (
                        client_id,
                        car_id,
                        service_id,
                        employee_id,
                        client_name,
                        phone,
                        car_brand,
                        car_model,
                        car_year,
                        license_plate,
                        work_description,
                        preferred_date,
                        preferred_time,
                        start_datetime,
                        end_datetime,
                        service_price_at_time,
                        service_duration_minutes,
                        master_income,
                        payment_status,
                        completed_at,
                        cancel_reason,
                        created_by_role,
                        comment,
                        status
                    )
                    VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        'Не оплачено',
                        NULL,
                        NULL,
                        ?,
                        ?,
                        'Новая'
                    )
                ");

                $stmt->execute([
                    $clientId,
                    $carId,
                    $old['service_id'],
                    $old['employee_id'],
                    $old['client_name'],
                    $old['phone'],
                    $old['car_brand'],
                    $old['car_model'],
                    $old['car_year'] !== '' ? (int)$old['car_year'] : null,
                    $old['license_plate'],
                    $old['work_description'],
                    $old['preferred_date'],
                    cr_time_short($old['preferred_time']) . ':00',
                    $start->format('Y-m-d H:i:s'),
                    $end->format('Y-m-d H:i:s'),
                    $price,
                    $durationMinutes,
                    $masterIncome,
                    $createdByRole,
                    $old['comment']
                ]);

                $appointmentId = (int)$pdo->lastInsertId();

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
                    VALUES (?, NULL, 'Новая', NULL, ?, NULL, ?, ?, 'Клиент создал онлайн-запись')
                ");

                $historyStmt->execute([
                    $appointmentId,
                    $old['employee_id'],
                    $start->format('Y-m-d H:i:s'),
                    $_SESSION['user_id'] ?? null
                ]);

                $success = 'Заявка создана. Администратор подтвердит запись после проверки.';

                $old['preferred_time'] = '';
                $old['employee_id'] = '';
                $old['work_description'] = '';
                $old['comment'] = '';
            }
        }
    }
}

require_once 'includes/header.php';
?>

<section class="form-section appointment-page">
    <div class="container">
        <div class="form-card form-card--wide">
            <span class="label">Онлайн-запись</span>

            <h1>Запись на ремонт</h1>

            <p>
                Выберите услугу, дату, свободное время и мастера.
                Если мастеров нет, дата или время не будут доступны для выбора.
            </p>

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

            <form method="post" action="appointment.php" id="appointmentForm" class="appointment-form">
                <input
                    type="hidden"
                    id="preferred_time"
                    name="preferred_time"
                    value="<?= htmlspecialchars($old['preferred_time']) ?>"
                >

                <input
                    type="hidden"
                    id="employee_id"
                    name="employee_id"
                    value="<?= htmlspecialchars($old['employee_id']) ?>"
                >

                <div class="appointment-block">
                    <h2>1. Контакты клиента</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="client_name">ФИО *</label>
                            <input
                                type="text"
                                id="client_name"
                                name="client_name"
                                value="<?= htmlspecialchars($old['client_name']) ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="phone">Телефон *</label>
                            <input
                                type="text"
                                id="phone"
                                name="phone"
                                value="<?= htmlspecialchars($old['phone']) ?>"
                                placeholder="+7 927 000-00-00"
                                required
                            >
                        </div>
                    </div>
                </div>

                <div class="appointment-block">
                    <h2>2. Услуга и дата</h2>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="service_id">Услуга *</label>

                            <select id="service_id" name="service_id" required>
                                <option value="">Выберите услугу</option>

                                <?php foreach ($services as $service): ?>
                                    <?php
                                        $durationMinutes = (int)($service['duration_minutes'] ?? 0);

                                        if ($durationMinutes <= 0) {
                                            $durationMinutes = (int)$service['duration_hours'] * 60;
                                        }

                                        if ($durationMinutes <= 0) {
                                            $durationMinutes = 60;
                                        }
                                    ?>

                                    <option
                                        value="<?= (int)$service['id'] ?>"
                                        data-price="<?= htmlspecialchars($service['price']) ?>"
                                        data-duration="<?= $durationMinutes ?>"
                                        <?= ((int)$old['service_id'] === (int)$service['id']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($service['name']) ?>
                                        — <?= number_format((float)$service['price'], 0, '.', ' ') ?> ₽
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="preferred_date">Дата *</label>

                            <input
                                type="date"
                                id="preferred_date"
                                name="preferred_date"
                                min="<?= date('Y-m-d') ?>"
                                value="<?= htmlspecialchars($old['preferred_date']) ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="appointment-summary" id="serviceSummary">
                        Выберите услугу, чтобы увидеть длительность и стоимость.
                    </div>
                </div>

                <div class="appointment-block">
                    <h2>3. Свободное время</h2>

                    <div class="dynamic-zone" id="timeZone">
                        <p class="muted-text">Сначала выберите услугу и доступную дату.</p>
                    </div>
                </div>

                <div class="appointment-block">
                    <h2>4. Мастер</h2>

                    <div class="dynamic-zone" id="masterZone">
                        <p class="muted-text">Мастер появится после выбора времени.</p>
                    </div>
                </div>

                <div class="appointment-block">
                    <h2>5. Автомобиль и задача</h2>

                    <?php if (!empty($clientCars)): ?>
                        <div class="form-group">
                            <label for="car_id">Мой автомобиль</label>

                            <select id="car_id" name="car_id">
                                <option value="">Выбрать автомобиль из личного кабинета</option>

                                <?php foreach ($clientCars as $car): ?>
                                    <option
                                        value="<?= (int)$car['id'] ?>"
                                        data-brand="<?= htmlspecialchars($car['brand']) ?>"
                                        data-model="<?= htmlspecialchars($car['model']) ?>"
                                        data-year="<?= htmlspecialchars($car['year'] ?? '') ?>"
                                        data-license="<?= htmlspecialchars($car['license_plate'] ?? '') ?>"
                                        <?= ((int)$old['car_id'] === (int)$car['id']) ? 'selected' : '' ?>
                                    >
                                        <?= htmlspecialchars($car['brand']) ?>
                                        <?= htmlspecialchars($car['model']) ?>

                                        <?php if (!empty($car['license_plate'])): ?>
                                            — <?= htmlspecialchars($car['license_plate']) ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <small class="input-hint">
                                При выборе автомобиля поля ниже заполнятся автоматически.
                            </small>
                        </div>
                    <?php else: ?>
                        <p class="muted-text">
                            В личном кабинете пока нет сохраненных автомобилей.
                            Можно заполнить данные вручную.
                        </p>
                    <?php endif; ?>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="car_brand">Марка автомобиля *</label>

                            <input
                                type="text"
                                id="car_brand"
                                name="car_brand"
                                value="<?= htmlspecialchars($old['car_brand']) ?>"
                                placeholder="Toyota"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="car_model">Модель автомобиля *</label>

                            <input
                                type="text"
                                id="car_model"
                                name="car_model"
                                value="<?= htmlspecialchars($old['car_model']) ?>"
                                placeholder="Camry"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="car_year">Год выпуска</label>

                            <input
                                type="number"
                                id="car_year"
                                name="car_year"
                                min="1980"
                                max="<?= date('Y') + 1 ?>"
                                value="<?= htmlspecialchars($old['car_year']) ?>"
                                placeholder="2018"
                            >
                        </div>

                        <div class="form-group">
                            <label for="license_plate">Госномер</label>

                            <input
                                type="text"
                                id="license_plate"
                                name="license_plate"
                                value="<?= htmlspecialchars($old['license_plate']) ?>"
                                placeholder="А123ВС12"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="work_description">Что нужно сделать? *</label>

                        <textarea
                            id="work_description"
                            name="work_description"
                            placeholder="Например: горит check engine, нужно проверить электрику"
                            required
                        ><?= htmlspecialchars($old['work_description']) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="comment">Комментарий</label>

                        <textarea
                            id="comment"
                            name="comment"
                            placeholder="Дополнительная информация для администратора или мастера"
                        ><?= htmlspecialchars($old['comment']) ?></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn--orange">
                    Отправить заявку
                </button>

                <?php if (!isset($_SESSION['user_id'])): ?>
                    <p class="form-note">
                        Чтобы видеть историю заявок и получать баллы,
                        <a href="register.php">зарегистрируйтесь</a>.
                    </p>
                <?php endif; ?>
            </form>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>