# TarakCMS

Лека PHP CMS с MySQL и плъгин система. Изисква PHP 8.2+, PDO MySQL и MySQL 8/MariaDB.

## Инсталиране
1. Копирайте папката на уеб сървъра (DocumentRoot насочен към `public/`).
2. Създайте MySQL база данни и потребител.
3. Копирайте `config/config.example.php` в `config/config.php` и попълнете данните.
4. Дайте права за запис на `storage/` и `plugins/` само ако са нужни за вашата среда.
5. Отворете `/install.php`, създайте първия администратор, след това изтрийте `public/install.php`.
6. В продукционна среда активирайте HTTPS и настройте уеб сървъра да блокира достъпа до `config/`, `storage/` и `plugins/`.

## Плъгини
Всеки плъгин е папка в `plugins/` и трябва да има `plugin.json` и `plugin.php`.
В админ панела отворете **Плъгини** и натиснете „Открий плъгини“, после активирайте желания плъгин.

Пример:
```json
{
  "name": "Hello Plugin",
  "slug": "hello",
  "version": "1.0.0",
  "description": "Добавя поздрав към сайта.",
  "author": "Your Name"
}
```

В `plugin.php`:
```php
<?php
add_filter('site_footer', function (string $html): string {
    return $html . '<p class="plugin-message">Powered by Hello Plugin</p>';
});
```

## Важно за сигурността
- Плъгините изпълняват PHP код със същите права като CMS. Инсталирайте само доверени плъгини.
- Това е начален проект, не е одитиран за продукционна употреба.
- За продукционна версия добавете CSRF защита във всички форми, rate limiting, логване, миграции и безопасен механизъм за обновяване.
