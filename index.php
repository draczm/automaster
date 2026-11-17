<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/db.php';

$services = [];
$employees = [];

try {
    $servicesStmt = $pdo->query("
        SELECT
            id,
            name,
            description,
            price,
            category,
            image,
            duration_minutes,
            duration_hours
        FROM services
        WHERE is_active = 1
        ORDER BY category, name
        LIMIT 6
    ");
    $services = $servicesStmt->fetchAll();
} catch (Throwable $e) {
    $services = [];
}

try {
    $employeesStmt = $pdo->query("
        SELECT
            id,
            full_name,
            position,
            specialization
        FROM employees
        WHERE is_master = 1
          AND work_status = 'Работает'
        ORDER BY full_name
        LIMIT 3
    ");
    $employees = $employeesStmt->fetchAll();
} catch (Throwable $e) {
    $employees = [];
}

function homeMoney($value)
{
    return number_format((float)$value, 0, '.', ' ') . ' ₽';
}

function homeDuration($service)
{
    $minutes = (int)($service['duration_minutes'] ?? 0);

    if ($minutes <= 0 && !empty($service['duration_hours'])) {
        $minutes = (int)$service['duration_hours'] * 60;
    }

    if ($minutes <= 0) {
        return 'от 1 часа';
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

function homeServiceImage($service, $index)
{
    if (!empty($service['image'])) {
        return $service['image'];
    }

    $fallbacks = [
        'assets/img/services/service-1.jpg',
        'assets/img/services/service-2.jpg',
        'assets/img/services/service-3.jpg',
        'assets/img/services/service-4.jpg',
        'assets/img/services/service-5.jpg',
        'assets/img/services/service-6.jpg'
    ];

    return $fallbacks[$index % count($fallbacks)];
}

function homeInitials($name)
{
    $parts = preg_split('/\s+/u', trim($name));
    $letters = '';

    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= mb_substr($part, 0, 1);
        }

        if (mb_strlen($letters) >= 2) {
            break;
        }
    }

    return mb_strtoupper($letters ?: 'CR');
}

require_once 'includes/header.php';
?>

<section class="hero home-hero">
    <div class="container hero__inner">
        <div class="hero__content">
            <span class="label">Автомастерская полного цикла</span>

            <h1>Запись на сервис без звонков и ожидания</h1>

            <p>
                CARREPAIR помогает выбрать услугу, дату, время и мастера.
                Клиент видит историю обслуживания, а сотрудники работают с заявками
                через понятный личный кабинет.
            </p>

            <div class="hero__actions">
                <a href="appointment.php" class="btn btn--orange">
                    Записаться на услугу
                </a>

                <a href="#services" class="btn btn--light">
                    Смотреть услуги
                </a>
            </div>

            <div class="home-hero__metrics">
                <div>
                    <span>08:30–21:00</span>
                    <p>рабочее время</p>
                </div>

                <div>
                    <span>Онлайн</span>
                    <p>выбор даты и мастера</p>
                </div>

                <div>
                    <span>Бонусы</span>
                    <p>за выполненные услуги</p>
                </div>
            </div>
        </div>

        <div class="hero__media">
            <div class="hero__card home-hero__panel">
                <div class="home-hero__top">
                    <span>Сегодня</span>
                    <strong>Свободные окна</strong>
                </div>

                <div class="home-hero__schedule">
                    <div>
                        <span>09:00</span>
                        <p>Диагностика двигателя</p>
                    </div>

                    <div>
                        <span>12:30</span>
                        <p>Замена масла</p>
                    </div>

                    <div>
                        <span>16:00</span>
                        <p>Ремонт тормозной системы</p>
                    </div>
                </div>

                <div class="home-hero__note">
                    <span></span>
                    <p>Время показывается только при наличии свободного мастера.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="home-strip">
    <div class="container home-strip__grid">
        <div>
            <span>01</span>
            <p>Клиент выбирает услугу и удобное время.</p>
        </div>

        <div>
            <span>02</span>
            <p>Система показывает доступных мастеров.</p>
        </div>

        <div>
            <span>03</span>
            <p>Администратор и мастер видят запись в расписании.</p>
        </div>
    </div>
</section>

<section class="services" id="services">
    <div class="container">
        <div class="section-head services__head">
            <span class="label">Услуги</span>

            <h2>Основные направления сервиса</h2>

            <p>
                В каталоге отображаются активные услуги с ценой и временем выполнения.
                После выбора услуги система подбирает доступные даты и мастеров.
            </p>
        </div>

        <?php if (empty($services)): ?>
            <p class="empty-text">Услуги пока не добавлены.</p>
        <?php else: ?>
            <div class="services__grid">
                <?php foreach ($services as $index => $service): ?>
                    <article class="service-card">
                        <img
                            src="<?= htmlspecialchars(homeServiceImage($service, $index)) ?>"
                            alt="<?= htmlspecialchars($service['name']) ?>"
                            onerror="this.closest('.service-card').classList.add('service-card--no-image'); this.remove();"
                        >

                        <div class="service-card__body">
                            <div class="service-card__meta">
                                <span><?= htmlspecialchars($service['category'] ?? 'Услуга') ?></span>
                                <span><?= htmlspecialchars(homeDuration($service)) ?></span>
                            </div>

                            <h3><?= htmlspecialchars($service['name']) ?></h3>

                            <p><?= htmlspecialchars($service['description'] ?? 'Описание услуги будет добавлено позже.') ?></p>

                            <div class="service-card__bottom">
                                <strong class="service-card__price">
                                    <?= homeMoney($service['price']) ?>
                                </strong>

                                <a href="appointment.php?service_id=<?= (int)$service['id'] ?>" class="btn btn--light btn--small">
                                    Выбрать
                                </a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="home-benefits">
    <div class="container">
        <div class="section-head">
            <span class="label">Преимущества</span>

            <h2>Сайт закрывает основные процессы автомастерской</h2>
        </div>

        <div class="home-benefits__grid">
            <article class="benefit-card">
                <span>Запись</span>
                <h3>Выбор даты, времени и мастера</h3>
                <p>Клиент видит только доступные интервалы, где есть свободный специалист.</p>
            </article>

            <article class="benefit-card">
                <span>Кабинет</span>
                <h3>История обслуживания</h3>
                <p>Пользователь хранит автомобили, прошлые ТО и историю начисления баллов.</p>
            </article>

            <article class="benefit-card">
                <span>Мастер</span>
                <h3>Ежедневник работ</h3>
                <p>Мастер видит записи на день, меняет статусы и контролирует выполнение услуг.</p>
            </article>

            <article class="benefit-card">
                <span>Админ</span>
                <h3>Управление сервисом</h3>
                <p>Администратор управляет услугами, заявками, мастерами, календарем и статистикой.</p>
            </article>
        </div>
    </div>
</section>

<section class="team" id="team">
    <div class="container">
        <div class="section-head team__head">
            <span class="label">Команда</span>

            <h2>Мастера сервиса</h2>

            <p>
                Специалисты привязаны к услугам и рабочему графику.
                Поэтому система не предлагает время, если мастер занят.
            </p>
        </div>

        <?php if (empty($employees)): ?>
            <p class="empty-text">Мастера пока не добавлены.</p>
        <?php else: ?>
            <div class="team__grid">
                <?php foreach ($employees as $employee): ?>
                    <article class="team-card home-master-card">
                        <div class="home-master-card__avatar">
                            <?= htmlspecialchars(homeInitials($employee['full_name'])) ?>
                        </div>

                        <span><?= htmlspecialchars($employee['position']) ?></span>

                        <h3><?= htmlspecialchars($employee['full_name']) ?></h3>

                        <p>
                            <?= htmlspecialchars($employee['specialization'] ?: 'Техническое обслуживание, диагностика и ремонт автомобилей.') ?>
                        </p>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<section class="home-process">
    <div class="container home-process__inner">
        <div>
            <span class="label">Как это работает</span>

            <h2>От заявки до завершенной услуги</h2>

            <p>
                Система фиксирует обращение клиента, назначает мастера,
                показывает запись в календаре и сохраняет результат в истории обслуживания.
            </p>
        </div>

        <div class="home-process__steps">
            <div>
                <span>1</span>
                <p>Клиент выбирает услугу, автомобиль, дату и время.</p>
            </div>

            <div>
                <span>2</span>
                <p>Мастер получает запись в ежедневнике и меняет статус работы.</p>
            </div>

            <div>
                <span>3</span>
                <p>После оказания услуги клиент получает историю ТО и бонусные баллы.</p>
            </div>
        </div>
    </div>
</section>

<section class="faq" id="faq">
    <div class="container">
        <div class="section-head faq__head">
            <span class="label">FAQ</span>

            <h2>Частые вопросы</h2>
        </div>

        <div class="faq__list">
            <details class="faq-item">
                <summary>Можно ли выбрать конкретного мастера?</summary>
                <p>
                    Да. Если несколько мастеров свободны на выбранное время,
                    система позволит выбрать подходящего специалиста.
                </p>
            </details>

            <details class="faq-item">
                <summary>Почему некоторые даты не показываются?</summary>
                <p>
                    Дата отображается только тогда, когда на ней есть свободные мастера
                    для выбранной услуги.
                </p>
            </details>

            <details class="faq-item">
                <summary>Где посмотреть прошлые обслуживания?</summary>
                <p>
                    История обслуживания доступна в личном кабинете клиента после входа.
                </p>
            </details>

            <details class="faq-item">
                <summary>Как работают бонусные баллы?</summary>
                <p>
                    Баллы начисляются за выполненные услуги и отображаются в шапке сайта
                    и личном кабинете.
                </p>
            </details>
        </div>
    </div>
</section>

<section class="home-cta">
    <div class="container home-cta__inner">
        <div>
            <span class="label">Онлайн-запись</span>

            <h2>Выберите услугу и подходящее время</h2>

            <p>
                Форма записи автоматически учитывает длительность услуги,
                график мастеров и уже занятые интервалы.
            </p>
        </div>

        <a href="appointment.php" class="btn btn--orange">
            Перейти к записи
        </a>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>