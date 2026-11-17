<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';

function isLoginEmailValid($email)
{
    $email = trim($email);

    if ($email === '') {
        return false;
    }

    if (!str_contains($email, '@')) {
        return false;
    }

    [$login, $domain] = explode('@', $email, 2);

    return $login !== '' && $domain !== '';
}

if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: admin/index.php');
        exit;
    }

    if ($_SESSION['role'] === 'employee') {
        header('Location: master-schedule.php');
        exit;
    }

    header('Location: profile.php');
    exit;
}

$error = '';
$oldEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $oldEmail = $email;

    if ($email === '' || $password === '') {
        $error = 'Введите email и пароль.';
    } elseif (!isLoginEmailValid($email)) {
        $error = 'Введите корректный email.';
    } else {
        $stmt = $pdo->prepare("
            SELECT
                users.id,
                users.client_id,
                users.employee_id,
                users.full_name,
                users.email,
                users.password_hash,
                users.role,
                users.status,
                clients.bonus_points
            FROM users
            LEFT JOIN clients ON clients.id = users.client_id
            WHERE users.email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Неверный email или пароль.';
        } elseif ($user['status'] !== 'active') {
            $error = 'Аккаунт заблокирован.';
        } else {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['client_id'] = $user['client_id'] ? (int)$user['client_id'] : null;
            $_SESSION['employee_id'] = $user['employee_id'] ? (int)$user['employee_id'] : null;
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['bonus_points'] = (int)($user['bonus_points'] ?? 0);
            $_SESSION['toast_message'] = 'Вы успешно вошли в аккаунт.';

            if ($user['role'] === 'admin') {
                header('Location: admin/index.php');
                exit;
            }

            if ($user['role'] === 'employee') {
                header('Location: master-schedule.php');
                exit;
            }

            header('Location: profile.php');
            exit;
        }
    }
}

require_once 'includes/header.php';
?>

<section class="form-section">
    <div class="container">
        <div class="form-card">
            <span class="label">Авторизация</span>

            <h1>Вход в личный кабинет</h1>

            <p>
                Войдите, чтобы смотреть свои записи, историю обслуживания,
                статусы заказов и накопленные бонусные баллы.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" id="loginForm" novalidate>
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input
                        type="text"
                        id="email"
                        name="email"
                        value="<?= htmlspecialchars($oldEmail) ?>"
                        placeholder="test1@email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Пароль *</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Введите пароль"
                        required
                    >
                </div>

                <button type="submit" class="btn btn--orange">
                    Войти
                </button>

                <p class="form-note">
                    Нет аккаунта? <a href="register.php">Зарегистрироваться</a>
                </p>
            </form>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>