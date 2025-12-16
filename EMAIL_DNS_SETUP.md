# Настройка DNS записей для улучшения доставляемости email

## Проблема
Письма с адреса `no-reply@smartbizsell.ru` попадают в спам.

## Решение
Необходимо настроить DNS записи для домена `smartbizsell.ru`:

### 1. SPF запись (Sender Policy Framework)

**Тип записи:** TXT  
**Имя записи:** `@` (корень домена) или `smartbizsell.ru`  
**Значение:**
```
v=spf1 mx a:mail.smartbizsell.ru ~all
```

**Объяснение:**
- `v=spf1` - версия SPF
- `mx` - разрешает отправку с MX записей домена
- `a:mail.smartbizsell.ru` - разрешает отправку с IP адреса, на который указывает A-запись mail.smartbizsell.ru
- `~all` - мягкий отказ для всех остальных (можно использовать `-all` для жесткого отказа)

**Проверка:**
```bash
nslookup -type=TXT smartbizsell.ru
```
Или через онлайн-сервис: https://mxtoolbox.com/spf.aspx

---

### 2. DKIM запись (DomainKeys Identified Mail)

DKIM обычно настраивается автоматически при создании почтового ящика на хостинге.

**Тип записи:** TXT  
**Имя записи:** `default._domainkey.smartbizsell.ru` (или `selector._domainkey.smartbizsell.ru`)  
**Значение:** (получается из панели управления хостингом)

**Где найти:**
1. Войдите в панель управления хостингом (например, reg.ru, timeweb и т.д.)
2. Перейдите в раздел "Почта" или "Email"
3. Найдите настройки DKIM для домена
4. Скопируйте публичный ключ DKIM

**Проверка:**
```bash
nslookup -type=TXT default._domainkey.smartbizsell.ru
```
Или через онлайн-сервис: https://mxtoolbox.com/dkim.aspx

---

### 3. DMARC запись (Domain-based Message Authentication)

**Тип записи:** TXT  
**Имя записи:** `_dmarc.smartbizsell.ru`  
**Значение (для начала - мониторинг):**
```
v=DMARC1; p=none; rua=mailto:admin@smartbizsell.ru; ruf=mailto:admin@smartbizsell.ru; fo=1
```

**Объяснение:**
- `v=DMARC1` - версия DMARC
- `p=none` - политика: только мониторинг (не блокировать письма)
- `rua=mailto:admin@smartbizsell.ru` - email для агрегированных отчетов
- `ruf=mailto:admin@smartbizsell.ru` - email для отчетов о неудачах
- `fo=1` - отправлять отчеты даже при частичных сбоях

**После проверки (через 1-2 недели) можно изменить на:**
```
v=DMARC1; p=quarantine; rua=mailto:admin@smartbizsell.ru; ruf=mailto:admin@smartbizsell.ru; fo=1; pct=100
```

Или для жесткой политики:
```
v=DMARC1; p=reject; rua=mailto:admin@smartbizsell.ru; ruf=mailto:admin@smartbizsell.ru; fo=1; pct=100
```

**Проверка:**
```bash
nslookup -type=TXT _dmarc.smartbizsell.ru
```
Или через онлайн-сервис: https://mxtoolbox.com/dmarc.aspx

---

### 4. Обратная DNS запись (PTR)

IP адрес почтового сервера должен иметь обратную запись (PTR), указывающую на `mail.smartbizsell.ru`.

**Где настраивать:**
- Обычно настраивается провайдером хостинга автоматически
- Если нет - обратитесь в техподдержку хостинга

**Проверка:**
```bash
# Узнайте IP адрес сервера
nslookup mail.smartbizsell.ru

# Проверьте обратную запись
nslookup IP_АДРЕС_СЕРВЕРА
```

---

## Проверка всех настроек

### Онлайн-сервисы для проверки:

1. **MXToolbox** - https://mxtoolbox.com/
   - SPF: https://mxtoolbox.com/spf.aspx
   - DKIM: https://mxtoolbox.com/dkim.aspx
   - DMARC: https://mxtoolbox.com/dmarc.aspx

2. **Mail Tester** - https://www.mail-tester.com/
   - Отправьте тестовое письмо на указанный адрес
   - Получите детальный отчет о проблемах

3. **Google Postmaster Tools** - https://postmaster.google.com/
   - Регистрация домена для мониторинга доставляемости в Gmail

4. **Microsoft SNDS** - https://sendersupport.olc.protection.outlook.com/snds/
   - Мониторинг доставляемости в Outlook/Hotmail

---

## Временные меры (пока настраиваются DNS записи)

1. **Используйте реальный email для Reply-To:**
   - Вместо `no-reply@smartbizsell.ru` используйте `support@smartbizsell.ru` или `info@smartbizsell.ru`
   - Это улучшит репутацию отправителя

2. **Добавьте физический адрес в письма:**
   - Это требуется по закону во многих странах
   - Улучшает доверие к письмам

3. **Избегайте спам-триггеров в тексте:**
   - Не используйте слова: "бесплатно", "срочно", "акция", "выиграли" и т.д.
   - Не используйте заглавные буквы в теме письма
   - Не используйте множественные восклицательные знаки

---

## После настройки DNS

1. Подождите 24-48 часов для распространения DNS записей
2. Проверьте все записи через онлайн-сервисы
3. Отправьте тестовое письмо через https://www.mail-tester.com/
4. Стремитесь к оценке 10/10

---

## Дополнительные рекомендации

1. **Настройте подписи в письмах:**
   - Добавьте физический адрес компании
   - Добавьте ссылку на отписку (если это массовая рассылка)

2. **Мониторинг репутации:**
   - Регулярно проверяйте репутацию IP адреса через https://mxtoolbox.com/blacklists.aspx
   - Если IP попал в черный список - обратитесь в техподдержку хостинга

3. **Теплинг домена:**
   - Используйте домен только для транзакционных писем
   - Не отправляйте массовые рассылки с этого домена
   - Это улучшит репутацию домена

---

## Контакты для помощи

Если возникли проблемы с настройкой:
1. Обратитесь в техподдержку хостинга (reg.ru, timeweb и т.д.)
2. Проверьте документацию хостинга по настройке почты
3. Используйте онлайн-сервисы для диагностики проблем

