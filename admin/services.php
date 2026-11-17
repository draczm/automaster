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

$categories = [
    'Техническое обслуживание',
    'Диагностика',
    'Ремонт'
];

$filterSearch = trim($_GET['search'] ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterPriceFrom = trim($_GET['price_from'] ?? '');
$filterPriceTo = trim($_GET['price_to'] ?? '');

function adminMoney($value)
{
    return number_format((float)$value, 0, '.', ' ') . ' ₽';
}

function adminDurationText($minutes)
{
    $minutes = (int)$minutes;

    if ($minutes <= 0) {
        return 'не указано';
    }

    if ($minutes < 60) {
        return $minutes . ' мин.';
    }

    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;

    if ($rest === 0) {
        return $hours . ' ч.';
    }

    return $hours . ' ч. ' . $rest . ' мин.';
}

function adminNormalizeServiceData($post)
{
    return [
        'name' => trim($post['name'] ?? ''),
        'description' => trim($post['description'] ?? ''),
        'price' => (float)str_replace(',', '.', $post['price'] ?? 0),
        'duration_minutes' => (int)($post['duration_minutes'] ?? 0),
        'bonus_percent' => (float)str_replace(',', '.', $post['bonus_percent'] ?? 5),
        'category' => trim($post['category'] ?? ''),
        'image' => trim($post['image'] ?? ''),
        'is_active' => isset($post['is_active']) ? 1 : 0
    ];
}

function adminValidateService($data, $categories)
{
    if ($data['name'] === '') {
        return 'Укажите название услуги.';
    }

    if ($data['description'] === '') {
        return 'Укажите описание услуги.';
    }

    if ($data['price'] <= 0) {
        return 'Стоимость услуги должна быть больше нуля.';
    }

    if ($data['duration_minutes'] <= 0) {
        return 'Укажите длительность услуги в минутах.';
    }

    if (!in_array($data['category'], $categories, true)) {
        return 'Выберите корректную категорию услуги.';
    }

    if ($data['bonus_percent'] < 0 || $data['bonus_percent'] > 50) {
        return 'Процент бонусов должен быть от 0 до 50.';
    }

    return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_service') {
            $data = adminNormalizeServiceData($_POST);
            $validationError = adminValidateService($data, $categories);

            if ($validationError !== '') {
                throw new Exception($validationError);
            }

            $stmt = $pdo->prepare("
                INSERT INTO services (
                    name,
                    description,
                    price,
                    duration_minutes,
                    duration_hours,
                    bonus_percent,
                    category,
                    image,
                    is_active
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $data['name'],
                $data['description'],
                $data['price'],
                $data['duration_minutes'],
                max(1, (int)ceil($data['duration_minutes'] / 60)),
                $data['bonus_percent'],
                $data['category'],
                $data['image'] !== '' ? $data['image'] : null,
                $data['is_active']
            ]);

            $success = 'Услуга добавлена.';
        }

        if ($action === 'update_service') {
            $serviceId = (int)($_POST['service_id'] ?? 0);

            if ($serviceId <= 0) {
                throw new Exception('Услуга не найдена.');
            }

            $data = adminNormalizeServiceData($_POST);
            $validationError = adminValidateService($data, $categories);

            if ($validationError !== '') {
                throw new Exception($validationError);
            }

            $stmt = $pdo->prepare("
                UPDATE services
                SET
                    name = ?,
                    description = ?,
                    price = ?,
                    duration_minutes = ?,
                    duration_hours = ?,
                    bonus_percent = ?,
                    category = ?,
                    image = ?,
                    is_active = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $data['name'],
                $data['description'],
                $data['price'],
                $data['duration_minutes'],
                max(1, (int)ceil($data['duration_minutes'] / 60)),
                $data['bonus_percent'],
                $data['category'],
                $data['image'] !== '' ? $data['image'] : null,
                $data['is_active'],
                $serviceId
            ]);

            $success = 'Услуга обновлена.';
        }

        if ($action === 'toggle_service') {
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $isActive = (int)($_POST['is_active'] ?? 0);

            if ($serviceId <= 0) {
                throw new Exception('Услуга не найдена.');
            }

            $stmt = $pdo->prepare("
                UPDATE services
                SET is_active = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $isActive,
                $serviceId
            ]);

            $success = $isActive ? 'Услуга возвращена на сайт.' : 'Услуга скрыта с сайта.';
        }

        if ($action === 'delete_service') {
            $serviceId = (int)($_POST['service_id'] ?? 0);

            if ($serviceId <= 0) {
                throw new Exception('Услуга не найдена.');
            }

            $countStmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM appointments
                WHERE service_id = ?
            ");
            $countStmt->execute([$serviceId]);
            $appointmentsCount = (int)$countStmt->fetchColumn();

            if ($appointmentsCount > 0) {
                $stmt = $pdo->prepare("
                    UPDATE services
                    SET is_active = 0
                    WHERE id = ?
                ");
                $stmt->execute([$serviceId]);

                $success = 'У услуги есть заявки, поэтому она скрыта, а не удалена.';
            } else {
                $stmt = $pdo->prepare("
                    DELETE FROM services
                    WHERE id = ?
                ");
                $stmt->execute([$serviceId]);

                $success = 'Услуга удалена.';
            }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$where = [];
$params = [];

if ($filterSearch !== '') {
    $where[] = "(
        services.name LIKE ?
        OR services.description LIKE ?
        OR services.category LIKE ?
    )";

    $searchValue = '%' . $filterSearch . '%';

    $params[] = $searchValue;
    $params[] = $searchValue;
    $params[] = $searchValue;
}

if ($filterCategory !== '' && in_array($filterCategory, $categories, true)) {
    $where[] = 'services.category = ?';
    $params[] = $filterCategory;
}

if ($filterStatus === 'active') {
    $where[] = 'services.is_active = 1';
}

if ($filterStatus === 'hidden') {
    $where[] = 'services.is_active = 0';
}

if ($filterPriceFrom !== '' && is_numeric($filterPriceFrom)) {
    $where[] = 'services.price >= ?';
    $params[] = (float)$filterPriceFrom;
}

if ($filterPriceTo !== '' && is_numeric($filterPriceTo)) {
    $where[] = 'services.price <= ?';
    $params[] = (float)$filterPriceTo;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$servicesStmt = $pdo->prepare("
    SELECT
        services.*,
        COUNT(appointments.id) AS appointments_count,
        COALESCE(SUM(
            CASE
                WHEN appointments.status IN ('Оказана', 'Выполнена')
                THEN appointments.service_price_at_time
                ELSE 0
            END
        ), 0) AS revenue_sum
    FROM services
    LEFT JOIN appointments ON appointments.service_id = services.id
    {$whereSql}
    GROUP BY services.id
    ORDER BY services.is_active DESC, services.category, services.name
");
$servicesStmt->execute($params);
$services = $servicesStmt->fetchAll();

$allStatsStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_services,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_services,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS hidden_services
    FROM services
");
$allStats = $allStatsStmt->fetch();

$totalServices = (int)($allStats['total_services'] ?? 0);
$activeServices = (int)($allStats['active_services'] ?? 0);
$hiddenServices = (int)($allStats['hidden_services'] ?? 0);

require_once '../includes/header.php';
?>

<section class="admin-page">
    <div class="container">
        <div class="admin-hero admin-hero--single">
            <div>
                <span class="label">Администрирование</span>

                <h1>Управление услугами</h1>

                <p>
                    Здесь администратор добавляет услуги, меняет стоимость,
                    длительность, бонусы и видимость услуги на сайте.
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

        <div class="admin-stats-grid admin-stats-grid--services">
            <article class="admin-stat-card">
                <span>Всего услуг</span>
                <strong><?= $totalServices ?></strong>
                <p>в базе данных</p>
            </article>

            <article class="admin-stat-card">
                <span>Активные</span>
                <strong><?= $activeServices ?></strong>
                <p>показываются клиентам</p>
            </article>

            <article class="admin-stat-card">
                <span>Скрытые</span>
                <strong><?= $hiddenServices ?></strong>
                <p>не доступны для записи</p>
            </article>

            <article class="admin-stat-card">
                <span>Найдено</span>
                <strong><?= count($services) ?></strong>
                <p>по текущему фильтру</p>
            </article>
        </div>

        <section class="admin-section-card">
            <details class="admin-collapsible">
                <summary>
                    <span>
                        <b>Добавить услугу</b>
                        <small>Название, цена, длительность, бонусы и изображение</small>
                    </span>
                </summary>

                <form method="post" action="services.php" class="admin-service-form admin-service-form--collapsible">
                    <input type="hidden" name="action" value="create_service">

                    <div class="form-group">
                        <label for="new_name">Название *</label>
                        <input
                            type="text"
                            id="new_name"
                            name="name"
                            placeholder="Например: Замена аккумулятора"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="new_category">Категория *</label>
                        <select id="new_category" name="category" required>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= htmlspecialchars($category) ?>">
                                    <?= htmlspecialchars($category) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="new_price">Стоимость, ₽ *</label>
                        <input
                            type="number"
                            id="new_price"
                            name="price"
                            min="1"
                            step="1"
                            placeholder="2500"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="new_duration">Длительность, мин. *</label>
                        <input
                            type="number"
                            id="new_duration"
                            name="duration_minutes"
                            min="15"
                            step="15"
                            placeholder="60"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="new_bonus">Бонусы, %</label>
                        <input
                            type="number"
                            id="new_bonus"
                            name="bonus_percent"
                            min="0"
                            max="50"
                            step="0.5"
                            value="5"
                        >
                    </div>

                    <div class="form-group">
                        <label for="new_image">Картинка</label>
                        <input
                            type="text"
                            id="new_image"
                            name="image"
                            placeholder="assets/images/service-1.jpg"
                        >
                    </div>

                    <div class="form-group admin-service-form__description">
                        <label for="new_description">Описание *</label>
                        <textarea
                            id="new_description"
                            name="description"
                            placeholder="Краткое описание услуги для карточки и формы записи"
                            required
                        ></textarea>
                    </div>

                    <label class="admin-check">
                        <input type="checkbox" name="is_active" checked>
                        <span>Показывать услугу на сайте</span>
                    </label>

                    <button type="submit" class="btn btn--orange">
                        Добавить услугу
                    </button>
                </form>
            </details>
        </section>

        <section class="admin-section-card">
            <div class="admin-section-head admin-section-head--compact">
                <div>
                    <span class="label">Каталог</span>
                    <h2>Список услуг</h2>
                </div>

                <strong class="admin-count">
                    <?= count($services) ?> шт.
                </strong>
            </div>

            <form method="get" action="services.php" class="admin-list-filter admin-list-filter--services">
                <div class="form-group">
                    <label for="search">Поиск</label>
                    <input
                        type="text"
                        id="search"
                        name="search"
                        value="<?= htmlspecialchars($filterSearch) ?>"
                        placeholder="Название, описание, категория"
                    >
                </div>

                <div class="form-group">
                    <label for="category">Категория</label>
                    <select id="category" name="category">
                        <option value="">Все категории</option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= htmlspecialchars($category) ?>"
                                <?= $filterCategory === $category ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status">Статус</label>
                    <select id="status" name="status">
                        <option value="">Все</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Активные</option>
                        <option value="hidden" <?= $filterStatus === 'hidden' ? 'selected' : '' ?>>Скрытые</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="price_from">Цена от</label>
                    <input
                        type="number"
                        id="price_from"
                        name="price_from"
                        min="0"
                        step="1"
                        value="<?= htmlspecialchars($filterPriceFrom) ?>"
                        placeholder="1000"
                    >
                </div>

                <div class="form-group">
                    <label for="price_to">Цена до</label>
                    <input
                        type="number"
                        id="price_to"
                        name="price_to"
                        min="0"
                        step="1"
                        value="<?= htmlspecialchars($filterPriceTo) ?>"
                        placeholder="5000"
                    >
                </div>

                <button type="submit" class="btn btn--orange btn--small">
                    Найти
                </button>

                <a href="services.php" class="btn btn--light btn--small">
                    Сбросить
                </a>
            </form>

            <?php if (empty($services)): ?>
                <p class="empty-text">По выбранным фильтрам услуги не найдены.</p>
            <?php else: ?>
                <div class="admin-services-list">
                    <?php foreach ($services as $service): ?>
                        <?php
                            $duration = (int)($service['duration_minutes'] ?? 0);

                            if ($duration <= 0 && !empty($service['duration_hours'])) {
                                $duration = (int)$service['duration_hours'] * 60;
                            }

                            if ($duration <= 0) {
                                $duration = 60;
                            }
                        ?>

                        <article class="admin-service-card <?= (int)$service['is_active'] === 1 ? '' : 'is-hidden' ?>">
                            <div class="admin-service-card__head">
                                <div>
                                    <span class="admin-card-time">
                                        <?= htmlspecialchars($service['category'] ?? 'Категория не указана') ?>
                                    </span>

                                    <h3><?= htmlspecialchars($service['name']) ?></h3>

                                    <p><?= htmlspecialchars($service['description'] ?? '') ?></p>
                                </div>

                                <span class="status-badge <?= (int)$service['is_active'] === 1 ? 'status-badge--done' : 'status-badge--cancel' ?>">
                                    <?= (int)$service['is_active'] === 1 ? 'Активна' : 'Скрыта' ?>
                                </span>
                            </div>

                            <div class="record-card__meta">
                                <div>
                                    <span>Стоимость</span>
                                    <strong><?= adminMoney($service['price']) ?></strong>
                                </div>

                                <div>
                                    <span>Длительность</span>
                                    <strong><?= adminDurationText($duration) ?></strong>
                                </div>

                                <div>
                                    <span>Бонусы</span>
                                    <strong><?= (float)$service['bonus_percent'] ?>%</strong>
                                </div>

                                <div>
                                    <span>Заявок</span>
                                    <strong><?= (int)$service['appointments_count'] ?></strong>
                                </div>

                                <div>
                                    <span>Выручка</span>
                                    <strong><?= adminMoney($service['revenue_sum']) ?></strong>
                                </div>

                                <div>
                                    <span>Картинка</span>
                                    <strong><?= htmlspecialchars($service['image'] ?: 'не указана') ?></strong>
                                </div>
                            </div>

                            <details class="admin-more">
                                <summary>Редактировать</summary>

                                <form method="post" action="services.php" class="admin-service-edit-form">
                                    <input type="hidden" name="action" value="update_service">
                                    <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">

                                    <div class="form-group">
                                        <label>Название *</label>
                                        <input
                                            type="text"
                                            name="name"
                                            value="<?= htmlspecialchars($service['name']) ?>"
                                            required
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label>Категория *</label>
                                        <select name="category" required>
                                            <?php foreach ($categories as $category): ?>
                                                <option
                                                    value="<?= htmlspecialchars($category) ?>"
                                                    <?= $service['category'] === $category ? 'selected' : '' ?>
                                                >
                                                    <?= htmlspecialchars($category) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label>Стоимость, ₽ *</label>
                                        <input
                                            type="number"
                                            name="price"
                                            min="1"
                                            step="1"
                                            value="<?= htmlspecialchars($service['price']) ?>"
                                            required
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label>Длительность, мин. *</label>
                                        <input
                                            type="number"
                                            name="duration_minutes"
                                            min="15"
                                            step="15"
                                            value="<?= (int)$duration ?>"
                                            required
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label>Бонусы, %</label>
                                        <input
                                            type="number"
                                            name="bonus_percent"
                                            min="0"
                                            max="50"
                                            step="0.5"
                                            value="<?= htmlspecialchars($service['bonus_percent']) ?>"
                                        >
                                    </div>

                                    <div class="form-group">
                                        <label>Картинка</label>
                                        <input
                                            type="text"
                                            name="image"
                                            value="<?= htmlspecialchars($service['image'] ?? '') ?>"
                                            placeholder="assets/images/service-1.jpg"
                                        >
                                    </div>

                                    <div class="form-group admin-service-edit-form__description">
                                        <label>Описание *</label>
                                        <textarea name="description" required><?= htmlspecialchars($service['description'] ?? '') ?></textarea>
                                    </div>

                                    <label class="admin-check">
                                        <input
                                            type="checkbox"
                                            name="is_active"
                                            <?= (int)$service['is_active'] === 1 ? 'checked' : '' ?>
                                        >
                                        <span>Показывать услугу на сайте</span>
                                    </label>

                                    <button type="submit" class="btn btn--orange btn--small">
                                        Сохранить изменения
                                    </button>
                                </form>

                                <div class="admin-service-card__actions">
                                    <form method="post" action="services.php">
                                        <input type="hidden" name="action" value="toggle_service">
                                        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                                        <input
                                            type="hidden"
                                            name="is_active"
                                            value="<?= (int)$service['is_active'] === 1 ? 0 : 1 ?>"
                                        >

                                        <button type="submit" class="btn btn--light btn--small">
                                            <?= (int)$service['is_active'] === 1 ? 'Скрыть услугу' : 'Вернуть на сайт' ?>
                                        </button>
                                    </form>

                                    <form method="post" action="services.php" onsubmit="return confirm('Удалить услугу? Если у нее есть заявки, услуга будет скрыта.');">
                                        <input type="hidden" name="action" value="delete_service">
                                        <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">

                                        <button type="submit" class="btn btn--danger btn--small">
                                            Удалить
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