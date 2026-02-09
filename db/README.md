# Установка базы данных SmartBizSell

## Информация о вашем сервере

- **Сервер**: MySQL 8.0 (Percona Server)
- **Хост**: localhost (via UNIX socket)
- **Пользователь**: u3064951_default
- **Кодировка**: UTF-8 Unicode (utf8mb4)

## Шаги установки

### 1. Создание базы данных

1. Войдите в панель управления reg.ru
2. Перейдите в раздел "Базы данных MySQL"
3. Создайте новую базу данных с именем `u3064951_SmartBizSell`
4. Запомните имя базы данных

### 2. Импорт структуры

**Вариант 1: Через phpMyAdmin**

1. Откройте phpMyAdmin
2. Выберите вашу базу данных в левом меню
3. Перейдите на вкладку "Импорт"
4. Выберите файл `schema.sql`
5. Нажмите "Вперед"

**Вариант 2: Через SQL-запрос**

1. Откройте phpMyAdmin
2. Выберите вашу базу данных
3. Перейдите на вкладку "SQL"
4. Откройте файл `schema.sql` в текстовом редакторе
5. Скопируйте содержимое (начиная с `CREATE TABLE IF NOT EXISTS users`)
6. Вставьте в окно SQL в phpMyAdmin
7. Нажмите "Вперед"

### 3. Настройка config.php

Откройте файл `config.php` и укажите:

```php
define('DB_NAME', 'u3064951_SmartBizSell'); // Имя вашей базы данных
define('DB_USER', 'u3064951_default'); // Уже указано
define('DB_PASS', 'ваш_пароль_от_БД'); // Пароль от базы данных
```

### 4. Проверка

Откройте в браузере:
- `https://ваш-домен.ru/register.php` - должна открыться страница регистрации
- Если видите ошибку подключения - проверьте данные в `config.php`

## Структура таблиц

Полная схема собирается из `schema.sql` (или `install.sql`) и миграций. Порядок применения миграций см. в [INSTALL.md](../INSTALL.md).

| Таблица | Назначение |
|--------|------------|
| **users** | Пользователи (продавцы) |
| **seller_forms** | Анкеты продавцов. Ключевые поля: `company_type` (ENUM 'startup', 'mature'), `data_json`, а также поля сделки, описания, финансов |
| **user_sessions** | Сессии пользователей (опционально) |
| **published_teasers** | Тизеры на модерации и опубликованные. Поля: `card_title` (кастомное название карточки), `views` (счётчик просмотров), `moderation_status`, `seller_form_id`, `moderated_html`, `published_at` |
| **asset_documents** | Документы, привязанные к активам |
| **password_reset_tokens** | Токены для восстановления пароля |
| **blog_posts** | Статьи блога (создаётся миграцией блога) |
| **term_sheet_forms** | Формы Term Sheet (создаётся при использовании функционала) |

## Миграции

Применять **после** импорта `schema.sql` или `install.sql`, в рекомендуемом порядке:

| Файл | Что добавляется |
|------|-----------------|
| `migration_published_teasers.sql` | Таблица `published_teasers` (модерация и публикация тизеров) |
| `migration_add_company_type.sql` | Колонка `seller_forms.company_type` (startup/mature) |
| `migration_add_card_title.sql` | Колонка `published_teasers.card_title` |
| `migration_teaser_views.sql` | Колонка `published_teasers.views` (счётчик просмотров) |
| `migration_asset_documents.sql` | Таблица `asset_documents` |
| `migration_password_reset.sql` | Таблица `password_reset_tokens` |
| `migration_blog.sql` | Таблица `blog_posts` |
| `migration_update_form.sql` | Обновление структуры анкет (при необходимости) |

Подробные шаги выполнения каждой миграции — в [INSTALL.md](../INSTALL.md). Миграция блога также описана в [MIGRATION_INSTRUCTIONS.md](../MIGRATION_INSTRUCTIONS.md).

## Примечания

- Percona Server 8.0 полностью совместим с MySQL 8.0
- Кодировка utf8mb4 уже используется по умолчанию
- SSL не требуется для localhost подключений

