# OkayCMS v4.5.2 PHP 8.5 Release

Этот репозиторий содержит OkayCMS v4.5.2, переведённую на актуальный стек PHP 8.5 и свежие Composer-зависимости. Ветка предназначена для релизного использования и дальнейшей поддержки проекта на современном PHP.

## Статус

- Ядро CMS адаптировано под PHP 8.5.
- `vendor` обновлён до актуальных версий через `composer.lock`.
- Фронт, ключевая админка, AJAX и POST-сценарии проверены smoke-тестами.
- В админке усилена защита POST-действий по `session_id`.
- Страница `SystemAdmin` расширена системной информацией о runtime, правах и Composer-пакетах.

## Что вошло в релиз

- Обновление зависимостей до современных major/minor версий:
  - `smarty/smarty` 5.x
  - `mobiledetect/mobiledetectlib` 4.x
  - `phpmailer/phpmailer` 7.x
  - `monolog/monolog` 3.x
  - `aura/sql` 6.x
  - `aura/sqlquery` 3.x
  - `symfony/console`, `symfony/process`, `symfony/lock` 8.x
  - `phpunit/phpunit` 13.x
- Совместимость ядра с новыми API библиотек и с PHP 8.5.
- Совместимость старого кода с новыми namespaced-классами через слой в `Okay/Core/compat/vendor_compat.php`.
- Исправления работы БД, query builder, Smarty, телефонов, переводов, bootstrap-файлов и админских обработчиков.
- Новые smoke-скрипты для быстрой регресс-проверки после деплоя.

## Требования

- PHP `^8.5`
- Composer 2
- MySQL или MariaDB
- Расширения PHP, требуемые `composer.json`:
  - `ext-SimpleXML`
  - `ext-XMLReader`
  - `ext-pdo`
  - `ext-json`
  - `ext-curl`
  - `ext-mbstring`
  - `ext-zip`

## Быстрый старт

1. Клонируйте репозиторий и установите зависимости:

```bash
composer install
```

2. Создайте локальный конфиг на основе базового:

```bash
cp config/config.php config/config.local.php
```

3. В `config/config.local.php` задайте параметры БД, локальный `debug_mode` и прочие environment-specific настройки.

4. Настройте веб-сервер так, чтобы document root указывал на корень проекта.

5. Для новой установки выполните:

```bash
php ok database:deploy
```

## Локальная разработка

- Базовый `config/config.php` в этой ветке хранится в релизном состоянии с `debug_mode=false`.
- Все локальные переопределения должны лежать в `config/config.local.php`.
- Для локальной отладки можно включать `debug_mode=true` только в `config/config.local.php`, не меняя базовый конфиг.

## Smoke-проверки

После обновления PHP, зависимостей или перед релизом выполняйте оба скрипта:

```bash
php tools/php85_smoke_routes.php http://okaycms.local 1
php tools/php85_smoke_scenarios.php http://okaycms.local
```

Что проверяет `tools/php85_smoke_routes.php`:

- статические публичные маршруты
- коллекции брендов, статей и товаров
- динамические URL категорий, брендов, товаров, страниц, авторов и блога
- ожидаемые редиректы на закрытые пользовательские разделы считаются корректным поведением

Что проверяет `tools/php85_smoke_scenarios.php`:

- регистрация пользователя
- логин и logout
- password remind
- subscribe AJAX
- feedback form
- cart AJAX
- wishlist AJAX
- comparison AJAX
- checkout
- admin save-actions
- upload favicon
- блокировку POST без корректного `session_id`

## Ключевые файлы релиза

- `composer.json` и `composer.lock` — обновлённый dependency stack
- `Okay/Core/compat/vendor_compat.php` — backward-compat слой для новых vendor API
- `index.php` и `backend/index.php` — обновлённый bootstrap под PHP 8.5
- `backend/Controllers/SystemAdmin.php` и `backend/design/html/settings_system.tpl` — расширенная системная информация
- `tools/php85_smoke_routes.php` — smoke по URL-слою
- `tools/php85_smoke_scenarios.php` — smoke по реальным пользовательским и админским сценариям

## Проверенный результат

На локальном стенде релиз был проверен на PHP 8.5.3:

- публичные маршруты отвечают корректно
- ключевые страницы админки открываются без fatal/warning
- фронтовые и админские POST/AJAX-сценарии проходят успешно
- `php-fpm` лог после smoke-прогонов остаётся чистым

## Ограничения текущего покрытия

Smoke-прогоны покрывают основной storefront и ключевую админку, но не заменяют полный QA. Отдельно стоит прогонять:

- cron/cli задачи
- импорт и экспорт
- редкие backend-экраны
- сторонние и кастомные модули

## Документация

- Основная документация: [docs/README.md](docs/README.md)
- Проект OkayCMS: [https://okay-cms.com](https://okay-cms.com)
- Исходный upstream-репозиторий: [https://github.com/OkayCMS/OkayCMS](https://github.com/OkayCMS/OkayCMS)

## Лицензия

OkayCMS распространяется по лицензии LGPL-3.0-or-later.

Copyright 2015-2024 OkayCMS
