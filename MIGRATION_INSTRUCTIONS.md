# Инструкция по выполнению миграции блога

## Способ 1: Через PHP-скрипт (Рекомендуется)

### Вариант A: Через браузер

1. Убедитесь, что вы авторизованы как модератор
2. Откройте в браузере:
   ```
   https://smartbizsell.ru/run_migration_blog.php
   ```
3. Скрипт автоматически выполнит миграцию и покажет результаты

### Вариант B: Через командную строку (SSH)

Если у вас есть доступ к серверу по SSH:

```bash
cd /path/to/SmartBizSell
php run_migration_blog.php
```

## Способ 2: Через phpMyAdmin

1. Войдите в phpMyAdmin вашего хостинга
2. Выберите базу данных `u3064951_SmartBizSell`
3. Перейдите на вкладку **SQL**
4. Откройте файл `db/migration_blog.sql` в текстовом редакторе
5. Скопируйте весь SQL-код
6. Вставьте в поле SQL в phpMyAdmin
7. Нажмите **Выполнить**

## Способ 3: Через командную строку MySQL

Если у вас есть доступ к MySQL через командную строку:

```bash
# Подключение к MySQL
mysql -u u3064951_default -p u3064951_SmartBizSell

# Введите пароль (m6t7EWLS9q89mbRv)

# Выполните миграцию
source /path/to/SmartBizSell/db/migration_blog.sql;

# Или напрямую:
mysql -u u3064951_default -p u3064951_SmartBizSell < /path/to/SmartBizSell/db/migration_blog.sql
```

## Способ 4: Через SSH с прямой загрузкой SQL

```bash
# Подключитесь к серверу по SSH
ssh user@your-server.com

# Перейдите в директорию проекта
cd /path/to/SmartBizSell

# Выполните SQL через MySQL
mysql -u u3064951_default -p u3064951_SmartBizSell < db/migration_blog.sql
```

## Что создается

Миграция создает таблицу `blog_posts` со следующими полями:

- `id` - уникальный идентификатор статьи
- `title` - заголовок статьи
- `slug` - URL-friendly версия заголовка (уникальный)
- `content` - полный текст статьи
- `excerpt` - краткое описание
- `author_id` - ID автора (связь с таблицей users)
- `category` - категория статьи
- `tags` - теги через запятую
- `meta_title` - SEO заголовок
- `meta_description` - SEO описание
- `keywords` - ключевые слова
- `views` - количество просмотров
- `published_at` - дата публикации
- `updated_at` - дата обновления (автоматически)
- `created_at` - дата создания (автоматически)
- `status` - статус (draft, published, archived)

Также создаются индексы для оптимизации поиска:
- Индекс по `published_at` и `status`
- Индекс по `category` и `status`
- Полнотекстовый индекс по `title`, `content`, `excerpt`

## Проверка результата

После выполнения миграции проверьте:

1. Таблица создана:
   ```sql
   SHOW TABLES LIKE 'blog_posts';
   ```

2. Структура таблицы:
   ```sql
   DESCRIBE blog_posts;
   ```

3. Индексы:
   ```sql
   SHOW INDEXES FROM blog_posts;
   ```

## Возможные ошибки

### Ошибка: "Table 'blog_posts' already exists"
- Это нормально, если таблица уже была создана ранее
- Скрипт `run_migration_blog.php` автоматически пропускает такие ошибки

### Ошибка: "Access denied"
- Убедитесь, что у пользователя БД есть права на создание таблиц
- Проверьте правильность учетных данных в `config.php`

### Ошибка: "Foreign key constraint fails"
- Убедитесь, что таблица `users` существует
- Проверьте, что в таблице `users` есть поле `id`

## После выполнения миграции

1. Удалите или защитите файл `run_migration_blog.php` (он больше не нужен)
2. Или переименуйте его в `run_migration_blog.php.disabled`

## Безопасность

⚠️ **Важно**: После выполнения миграции рекомендуется:
- Удалить файл `run_migration_blog.php` с сервера
- Или ограничить доступ к нему через `.htaccess`:
  ```apache
  <Files "run_migration_blog.php">
      Require ip YOUR_IP_ADDRESS
  </Files>
  ```

