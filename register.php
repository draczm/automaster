<?php
require_once 'config/db.php';
require_once 'includes/header.php';

$error = '';

$old = [
    'full_name' => '',
    'phone' => '',
    'email' => '',
    'address' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordRepeat = $_POST['password_repeat'] ?? '';

    $old = [
        'full_name' => $fullName,
        'phone' => $phone,
        'email' => $email,
        'address' => $address
    ];

    if ($fullName === '' || $phone === '' || $email === '' || $password === '') {
        $error = 'Заполните обязательные поля.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Введите корректный email.';
    } elseif (mb_strlen($password) < 6) {
        $error = 'Пароль должен содержать минимум 6 символов.';
    } elseif ($password !== $passwordRepeat) {
        $error = 'Пароли не совпадают.';
    } else {
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$email]);

        if ($checkStmt->fetch()) {
            $error = 'Пользователь с таким email уже зарегистрирован.';
        } else {
            try {
                $pdo->beginTransaction();

                $clientStmt = $pdo->prepare("
                    INSERT INTO clients (full_name, phone, address, email, bonus_points)
                    VALUES (?, ?, ?, ?, 100)
                ");
                $clientStmt->execute([$fullName, $phone, $address, $email]);

                $clientId = (int)$pdo->lastInsertId();
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                $userStmt = $pdo->prepare("
                    INSERT INTO users (client_id, full_name, email, phone, password_hash, role, status)
                    VALUES (?, ?, ?, ?, ?, 'client', 'active')
                ");
                $userStmt->execute([$clientId, $fullName, $email, $phone, $passwordHash]);

                $userId = (int)$pdo->lastInsertId();

                $bonusStmt = $pdo->prepare("
                    INSERT INTO bonus_transactions (client_id, order_id, points, operation_type, description)
                    VALUES (?, NULL, 100, 'Начисление', 'Стартовые баллы за регистрацию')
                ");
                $bonusStmt->execute([$clientId]);

                $pdo->commit();

                $_SESSION['user_id'] = $userId;
                $_SESSION['client_id'] = $clientId;
                $_SESSION['full_name'] = $fullName;
                $_SESSION['role'] = 'client';
                $_SESSION['bonus_points'] = 100;
                $_SESSION['toast_message'] = 'Регистрация выполнена. Вам начислено 100 бонусных баллов.';

                header('Location: profile.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Ошибка регистрации. Попробуйте еще раз.';
            }
        }
    }
}
?>

<section class="form-section">
    <div class="container">
        <div class="form-card">
            <span class="label">Регистрация</span>

            <h1>Создание личного кабинета</h1>

            <p>
                Зарегистрируйтесь, чтобы записываться на услуги, смотреть историю
                обслуживания и получать бонусные баллы.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="register.php" id="registerForm" novalidate>
                <div class="form-group">
                    <label for="full_name">ФИО *</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        value="<?= htmlspecialchars($old['full_name']) ?>"
                        required
                    >
                </div>

                <div class="form-row">
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

                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            value="<?= htmlspecialchars($old['email']) ?>"
                            required
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="address">Адрес</label>
                    <input
                        type="text"
                        id="address"
                        name="address"
                        value="<?= htmlspecialchars($old['address']) ?>"
                        placeholder="г. Йошкар-Ола"
                    >
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Пароль *</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            minlength="6"
                            required
                        >
                        <small class="input-hint">Минимум 6 символов.</small>
                    </div>

                    <div class="form-group">
                        <label for="password_repeat">Повтор пароля *</label>
                        <input
                            type="password"
                            id="password_repeat"
                            name="password_repeat"
                            minlength="6"
                            required
                        >
                        <small class="input-hint">Повторите пароль без ошибок.</small>
                    </div>
                </div>

                <button type="submit" class="btn btn--orange">Зарегистрироваться</button>

                <p class="form-note">
                    Уже есть аккаунт? <a href="login.php">Войти</a>
                </p>
            </form>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>