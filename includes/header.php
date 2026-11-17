<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$isAdminArea = strpos($currentScript, '/admin/') !== false;
$currentPage = basename($currentScript);
$basePath = $isAdminArea ? '../' : '';

if (!function_exists('headerActiveClass')) {
    function headerActiveClass($condition)
    {
        return $condition ? ' is-active' : '';
    }
}

$headerUserName = $_SESSION['full_name'] ?? '';
$headerBonusPoints = (int)($_SESSION['bonus_points'] ?? 0);
$headerRole = $_SESSION['role'] ?? '';

if (
    isset($_SESSION['client_id']) &&
    $_SESSION['client_id'] &&
    isset($pdo) &&
    $headerRole === 'client'
) {
    $bonusStmt = $pdo->prepare("
        SELECT full_name, bonus_points
        FROM clients
        WHERE id = ?
        LIMIT 1
    ");
    $bonusStmt->execute([(int)$_SESSION['client_id']]);
    $headerClient = $bonusStmt->fetch();

    if ($headerClient) {
        $headerUserName = $headerClient['full_name'];
        $headerBonusPoints = (int)$headerClient['bonus_points'];

        $_SESSION['full_name'] = $headerUserName;
        $_SESSION['bonus_points'] = $headerBonusPoints;
    }
}

$logoHref = $basePath . 'index.php';

if ($headerRole === 'employee') {
    $logoHref = $basePath . 'master-schedule.php';
}

if ($headerRole === 'admin') {
    $logoHref = $basePath . 'admin/index.php';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>CARREPAIR — ремонт автомобилей</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="CARREPAIR — ремонт автомобилей, онлайн-запись, личный кабинет и бонусная система.">
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/style.css">
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/admin.css">
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/home.css">
    <link rel="stylesheet" href="<?= $basePath ?>assets/css/pages.css">
</head>
<body>

<header class="header">
    <div class="container header__inner">
        <a href="<?= $logoHref ?>" class="logo">
            <span class="logo__icon">CR</span>
            <span class="logo__text">CARREPAIR</span>
        </a>

        <button class="burger" type="button" id="burgerBtn" aria-label="Открыть меню">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <nav class="nav nav--center" id="mainNav">
            <?php if (!isset($_SESSION['user_id']) || $headerRole === 'client'): ?>
                <a
                    href="<?= $basePath ?>index.php"
                    class="<?= headerActiveClass(!$isAdminArea && $currentPage === 'index.php') ?>"
                >
                    Главная
                </a>

                <a href="<?= $basePath ?>index.php#services">Услуги</a>
                <a href="<?= $basePath ?>index.php#team">Команда</a>
                <a href="<?= $basePath ?>index.php#faq">FAQ</a>

                <a
                    href="<?= $basePath ?>appointment.php"
                    class="<?= headerActiveClass(!$isAdminArea && $currentPage === 'appointment.php') ?>"
                >
                    Записаться
                </a>

                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a
                        href="<?= $basePath ?>login.php"
                        class="mobile-auth-link<?= headerActiveClass(!$isAdminArea && $currentPage === 'login.php') ?>"
                    >
                        Вход
                    </a>

                    <a
                        href="<?= $basePath ?>register.php"
                        class="mobile-auth-link mobile-auth-link--register<?= headerActiveClass(!$isAdminArea && $currentPage === 'register.php') ?>"
                    >
                        Регистрация
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id']) && $headerRole === 'employee'): ?>
                <a
                    href="<?= $basePath ?>master-schedule.php"
                    class="<?= headerActiveClass(!$isAdminArea && $currentPage === 'master-schedule.php') ?>"
                >
                    Ежедневник
                </a>

                <a href="<?= $basePath ?>master-schedule.php#calendar">Календарь</a>

                <a
                    href="<?= $basePath ?>master-training.php"
                    class="<?= headerActiveClass(!$isAdminArea && $currentPage === 'master-training.php') ?>"
                >
                    Обучение
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id']) && $headerRole === 'admin'): ?>
                <a
                    href="<?= $basePath ?>admin/index.php"
                    class="<?= headerActiveClass($isAdminArea && $currentPage === 'index.php') ?>"
                >
                    Сводка
                </a>

                <a
                    href="<?= $basePath ?>admin/appointments.php"
                    class="<?= headerActiveClass($isAdminArea && $currentPage === 'appointments.php') ?>"
                >
                    Заявки
                </a>

                <a
                    href="<?= $basePath ?>admin/services.php"
                    class="<?= headerActiveClass($isAdminArea && $currentPage === 'services.php') ?>"
                >
                    Услуги
                </a>

                <a
                    href="<?= $basePath ?>admin/schedule.php"
                    class="<?= headerActiveClass($isAdminArea && $currentPage === 'schedule.php') ?>"
                >
                    Календарь
                </a>

                <a
                    href="<?= $basePath ?>admin/employees.php"
                    class="<?= headerActiveClass($isAdminArea && $currentPage === 'employees.php') ?>"
                >
                    Мастера
                </a>
            <?php endif; ?>
        </nav>

        <div class="header-actions">
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a
                    href="<?= $basePath ?>login.php"
                    class="header-login<?= headerActiveClass(!$isAdminArea && $currentPage === 'login.php') ?>"
                >
                    Вход
                </a>

                <a
                    href="<?= $basePath ?>register.php"
                    class="nav__button nav__button--green<?= headerActiveClass(!$isAdminArea && $currentPage === 'register.php') ?>"
                >
                    Регистрация
                </a>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id']) && $headerRole === 'client'): ?>
                <div class="user-menu">
                    <a
                        href="<?= $basePath ?>profile.php"
                        class="user-name<?= headerActiveClass(!$isAdminArea && $currentPage === 'profile.php') ?>"
                    >
                        <?= htmlspecialchars($headerUserName) ?>
                    </a>

                    <button type="button" class="bonus-button" id="bonusHelpBtn">
                        <?= $headerBonusPoints ?> баллов
                    </button>
                </div>

                <a href="<?= $basePath ?>logout.php" class="header-login">Выйти</a>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id']) && $headerRole === 'employee'): ?>
                <span class="user-chip">
                    <em>Сотрудник</em>
                    <?= htmlspecialchars($headerUserName) ?>
                </span>

                <a href="<?= $basePath ?>logout.php" class="header-login">Выйти</a>
            <?php endif; ?>

            <?php if (isset($_SESSION['user_id']) && $headerRole === 'admin'): ?>
                <span class="user-chip">
                    <em>Админ</em>
                    <?= htmlspecialchars($headerUserName) ?>
                </span>

                <a href="<?= $basePath ?>logout.php" class="header-login">Выйти</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<?php if ($headerRole === 'client'): ?>
    <div class="bonus-modal" id="bonusModal" aria-hidden="true">
        <div class="bonus-modal__overlay" data-close-modal></div>

        <div class="bonus-modal__card">
            <button type="button" class="bonus-modal__close" data-close-modal>×</button>

            <span class="label">Бонусная система</span>

            <h2>Как копить и тратить баллы</h2>

            <div class="bonus-rules">
                <div>
                    <strong>Как накопить</strong>
                    <p>После регистрации начисляется 100 баллов.</p>
                    <p>После завершения заказа начисляется процент от суммы услуги.</p>
                </div>

                <div>
                    <strong>Как потратить</strong>
                    <p>Баллы можно использовать как скидку при следующем обращении.</p>
                    <p>Сообщите администратору, что хотите списать баллы при оформлении заказа.</p>
                </div>

                <div>
                    <strong>Где смотреть баланс</strong>
                    <p>Баланс отображается в шапке сайта и в личном кабинете клиента.</p>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="toast" id="toast"></div>

<main>