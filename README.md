# AutoMaster

Сайт автосервиса на PHP + MySQL: онлайн-запись, календарь/расписание мастера, админ-панель.

## Демо-аккаунты

| Роль | Email | Пароль | Куда попадает |
|---|---|---|---|
| Администратор | admin@automaster.local | Admin123! | `admin/index.php` (записи, услуги, сотрудники, расписание) |
| Сотрудник (пользователь) | user@automaster.local | User123! | `master-schedule.php` (календарь и записи мастера) |

Клиенты могут зарегистрироваться сами на `register.php`.

## Запуск (XAMPP)

1. Скопируйте проект в `C:\xampp\htdocs\automaster`, запустите Apache и MySQL.
2. Импортируйте базу:
   ```bash
   mysql -uroot < database/schema.sql
   mysql -uroot < database/seed.sql
   ```
3. Откройте http://localhost/automaster/

Параметры БД по умолчанию: `localhost`, `root`, пустой пароль. Переопределяются переменными окружения `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.

## Важно

GitHub Pages не выполняет PHP, поэтому репозиторий — это исходный код; для работающего сайта нужен PHP-хостинг с MySQL.
Демо-пароли публичные — смените их перед реальным использованием.