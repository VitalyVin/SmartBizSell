<?php
/**
 * Личный кабинет продавца
 * 
 * Функциональность:
 * - Просмотр статистики по анкетам
 * - Список всех анкет пользователя с фильтрацией по статусу
 * - Переход к созданию новой анкеты
 * - Переход к настройкам профиля
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

/**
 * Проверка авторизации пользователя
 * Если пользователь не авторизован, происходит редирект на страницу входа
 */
if (!isLoggedIn()) {
    redirectToLogin();
}

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    redirectToLogin();
}

/**
 * Получение всех анкет текущего пользователя из базы данных
 * Анкеты сортируются по дате создания (новые первыми)
 */
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, asset_name, status, created_at, updated_at, submitted_at 
        FROM seller_forms 
        WHERE user_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $forms = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching forms: " . $e->getMessage());
    $forms = [];
}

$activeForms = array_values(array_filter($forms, fn($f) => $f['status'] !== 'draft'));
$draftForms = array_values(array_filter($forms, fn($f) => $f['status'] === 'draft'));

/**
 * Маппинг статусов анкет для отображения
 * Каждый статус имеет текстовое название и цвет для визуального отображения
 */
$statusLabels = [
    'draft' => 'Черновик',
    'submitted' => 'Отправлена',
    'review' => 'На проверке',
    'approved' => 'Одобрена',
    'rejected' => 'Отклонена'
];

$statusColors = [
    'draft' => '#86868B',
    'submitted' => '#007AFF',
    'review' => '#FF9500',
    'approved' => '#34C759',
    'rejected' => '#FF3B30'
];

/**
 * Вспомогательные функции для DCF
 */

/**
 * Преобразует значение в число с плавающей точкой
 * Обрабатывает различные форматы: null, пустые строки, числа, строки с пробелами и запятыми
 * 
 * @param mixed $value Значение для преобразования
 * @return float Преобразованное число (0.0 если значение невалидно)
 */
function dcf_to_float($value): float {
    if ($value === null || $value === '') {
        return 0.0;
    }
    if (is_numeric($value)) {
        return (float)$value;
    }
    // Нормализация: удаление пробелов и замена запятой на точку
    $normalized = str_replace([' ', ' '], '', (string)$value);
    $normalized = str_replace(',', '.', $normalized);
    return is_numeric($normalized) ? (float)$normalized : 0.0;
}

/**
 * Преобразует массив строк в ассоциативный массив по метрикам
 * Используется для быстрого доступа к данным по названию метрики
 * 
 * @param array|null $rows Массив строк с полем 'metric'
 * @return array Ассоциативный массив [метрика => строка данных]
 */
function dcf_rows_by_metric(?array $rows): array {
    $result = [];
    foreach ($rows ?? [] as $row) {
        if (!empty($row['metric'])) {
            $result[$row['metric']] = $row;
        }
    }
    return $result;
}

/**
 * Строит временной ряд значений из строки данных
 * Преобразует данные из формата с ключами (fact_2022, budget_2025 и т.д.) в временной ряд
 * 
 * @param array $row Строка данных с ключами периодов
 * @param array $order Маппинг ключей периодов на метки (например, 'fact_2022' => '2022')
 * @return array Временной ряд [метка => значение]
 */
function dcf_build_series(array $row, array $order): array {
    $series = [];
    foreach ($order as $key => $label) {
        $series[$label] = dcf_to_float($row[$key] ?? 0);
    }
    return $series;
}

/**
 * Унификация значений (поддержка новых ключей 2022_fact и legacy fact_2022)
 * Позволяет использовать как старый формат (fact_2022), так и новый (2022_fact)
 * 
 * @param array $row Строка данных
 * @param array $keys Массив ключей для проверки (в порядке приоритета)
 * @return string Первое найденное непустое значение или пустая строка
 */
function pickValue(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($row[$key]) && $row[$key] !== '') {
            return $row[$key];
        }
    }
    return '';
}

/**
 * Конвертирует финансовые данные из нового формата (ключи: revenue, cost_of_sales и т.д.)
 * в старый формат (массив строк с полем 'metric')
 * Обеспечивает обратную совместимость с существующей логикой DCF
 * 
 * @param array $financial Финансовые данные в новом или старом формате
 * @return array Финансовые данные в старом формате (массив строк с 'metric')
 */
function convertFinancialRows(array $financial): array
{
    if (empty($financial)) {
        return [];
    }

    // Если данные уже в старом формате, возвращаем как есть
    $first = reset($financial);
    if (is_array($first) && isset($first['metric'])) {
        return $financial;
    }

    $map = [
        'revenue' => 'Выручка',
        'cost_of_sales' => 'Себестоимость продаж',
        'commercial_expenses' => 'Коммерческие расходы',
        'management_expenses' => 'Управленческие расходы',
        'sales_profit' => 'Прибыль от продаж',
        'depreciation' => 'Амортизация',
        'fixed_assets_acquisition' => 'Приобретение основных средств',
    ];

    $fields = [
        'unit'         => ['unit'],
        'fact_2022'    => ['fact_2022', '2022_fact'],
        'fact_2023'    => ['fact_2023', '2023_fact'],
        'fact_2024'    => ['fact_2024', '2024_fact'],
        'fact_2025_9m' => ['fact_2025_9m', '2025_q3_fact'],
        'budget_2025'  => ['budget_2025', '2025_budget'],
        'budget_2026'  => ['budget_2026', '2026_budget'],
    ];

    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($financial[$key]) || !is_array($financial[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        foreach ($fields as $legacyKey => $aliases) {
            $row[$legacyKey] = pickValue($financial[$key], $aliases);
        }
        $result[] = $row;
    }

    return $result;
}

/**
 * Конвертирует балансовые данные из нового формата (ключи: fixed_assets, inventory и т.д.)
 * в старый формат (массив строк с полем 'metric')
 * Обеспечивает обратную совместимость с существующей логикой DCF
 * 
 * @param array $balance Балансовые данные в новом или старом формате
 * @return array Балансовые данные в старом формате (массив строк с 'metric')
 */
function convertBalanceRows(array $balance): array
{
    if (empty($balance)) {
        return [];
    }

    // Если данные уже в старом формате, возвращаем как есть
    $first = reset($balance);
    if (is_array($first) && isset($first['metric'])) {
        return $balance;
    }

    $map = [
        'fixed_assets' => 'Основные средства',
        'inventory'    => 'Запасы',
        'receivables'  => 'Дебиторская задолженность',
        'payables'     => 'Кредиторская задолженность',
        'loans'        => 'Кредиты и займы',
        'cash'         => 'Денежные средства',
        'net_assets'   => 'Чистые активы',
    ];

    $fields = [
        'unit'         => ['unit'],
        'fact_2022'    => ['fact_2022', '2022_fact'],
        'fact_2023'    => ['fact_2023', '2023_fact'],
        'fact_2024'    => ['fact_2024', '2024_fact'],
        'fact_2025_9m' => ['fact_2025_9m', '2025_q3_fact'],
    ];

    $result = [];
    foreach ($map as $key => $metric) {
        if (!isset($balance[$key]) || !is_array($balance[$key])) {
            continue;
        }
        $row = ['metric' => $metric];
        foreach ($fields as $legacyKey => $aliases) {
            $row[$legacyKey] = pickValue($balance[$key], $aliases);
        }
        $result[] = $row;
    }

    return $result;
}

/**
 * Извлекает финансовые и балансовые данные из формы
 * Приоритет: сначала проверяет старые поля (financial_results, balance_indicators),
 * затем data_json (новый формат хранения всех данных формы)
 * 
 * @param array $form Данные формы из базы данных
 * @return array Массив [финансовые данные, балансовые данные] в унифицированном формате
 */
function extractFinancialAndBalance(array $form): array
{
    // Пытаемся получить данные из старых полей
    $financial = json_decode($form['financial_results'] ?? '[]', true);
    $balance   = json_decode($form['balance_indicators'] ?? '[]', true);

    // Если старые поля пусты, пытаемся получить из data_json (новый формат)
    if (empty($form['data_json']) === false) {
        $decoded = json_decode($form['data_json'], true);
        if (empty($financial) && isset($decoded['financial']) && is_array($decoded['financial'])) {
            $financial = $decoded['financial'];
        }
        if (empty($balance) && isset($decoded['balance']) && is_array($decoded['balance'])) {
            $balance = $decoded['balance'];
        }
    }

    // Конвертируем в унифицированный формат (старый формат с полем 'metric')
    $financial = convertFinancialRows($financial);
    $balance   = convertBalanceRows($balance);

    return [$financial, $balance];
}

/**
 * Генерирует псевдослучайное число на основе seed и offset
 * Гарантирует детерминированность: одинаковые seed и offset дают одинаковый результат
 * Используется для добавления небольших случайных вариаций в расчеты DCF
 * 
 * @param string $seed Строка-ключ для генерации (обычно название актива + ID формы)
 * @param int $offset Смещение для получения разных значений при одном seed
 * @param float $min Минимальное значение
 * @param float $max Максимальное значение
 * @return float Псевдослучайное число в диапазоне [min, max]
 */
function stableRandFloat(string $seed, int $offset, float $min, float $max): float
{
    $hash = crc32($seed . '|' . $offset);
    $normalized = fmod(abs(sin($hash + $offset * 12.9898)), 1);
    return $min + ($max - $min) * $normalized;
}

/**
 * Ограничивает значение заданными границами
 * 
 * @param float $value Значение для ограничения
 * @param float $min Минимальное значение
 * @param float $max Максимальное значение
 * @return float Значение в диапазоне [min, max]
 */
function clampFloat(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

/**
 * Строит полную DCF-модель на основе последней отправленной анкеты пользователя.
 * Возвращает не только итоговые показатели, но и полный набор параметров/предупреждений
 * для отображения в личном кабинете.
 */
/**
 * Строит полную DCF-модель на основе последней отправленной анкеты пользователя.
 * Возвращает не только итоговые показатели, но и полный набор параметров/предупреждений
 * для отображения в личном кабинете.
 * 
 * Алгоритм:
 * 1. Извлечение и нормализация финансовых и балансовых данных
 * 2. Расчет фактических показателей за 2022-2024 годы
 * 3. Расчет темпов роста на основе исторических данных
 * 4. Построение прогноза на 5 лет (P1-P5) с учетом бюджета 2025
 * 5. Расчет FCFF (Free Cash Flow to Firm) для каждого прогнозного периода
 * 6. Дисконтирование FCFF к текущей стоимости
 * 7. Расчет Terminal Value по модели Гордона
 * 8. Расчет Enterprise Value и Equity Value
 * 
 * @param array $form Данные формы из базы данных
 * @return array Массив с результатами расчета DCF или ошибкой
 */
function calculateUserDCF(array $form): array {
    // Параметры модели по умолчанию
    $defaults = [
        'wacc' => 0.24,              // Средневзвешенная стоимость капитала (24%)
        'tax_rate' => 0.25,          // Ставка налога на прибыль (25%)
        'perpetual_growth' => 0.04,  // Темп бессрочного роста (4%)
    ];

    // Маппинг ключей периодов из базы данных на метки для временных рядов
    $periodMap = [
        'fact_2022'    => '2022',    // Факт 2022 года
        'fact_2023'    => '2023',    // Факт 2023 года
        'fact_2024'    => '2024',    // Факт 2024 года
        'fact_2025_9m' => '9M2025',  // Факт за 9 месяцев 2025 года
        'budget_2025'  => '2025',    // Бюджет 2025 года (используется для P1)
        'budget_2026'  => '2026',    // Бюджет 2026 года
    ];

    // Извлечение финансовых и балансовых данных из формы
    list($financial, $balance) = extractFinancialAndBalance($form);
    if (!$financial || !$balance) {
        return ['error' => 'Недостаточно финансовых данных для построения модели.'];
    }

    // Преобразование в ассоциативные массивы для быстрого доступа по метрикам
    $finRows = dcf_rows_by_metric($financial);
    $balRows = dcf_rows_by_metric($balance);

    // Проверка наличия обязательных метрик
    $requiredMetrics = ['Выручка', 'Себестоимость продаж', 'Коммерческие расходы'];
    foreach ($requiredMetrics as $metric) {
        if (!isset($finRows[$metric])) {
            return ['error' => 'Не заполнены обязательные строки финансовой таблицы (выручка/расходы).'];
        }
    }

    // Построение временных рядов для финансовых показателей
    $revenueSeries   = dcf_build_series($finRows['Выручка'], $periodMap);
    $cogsSeries      = dcf_build_series($finRows['Себестоимость продаж'], $periodMap);
    $commercialSeries= dcf_build_series($finRows['Коммерческие расходы'], $periodMap);
    $adminSeries     = isset($finRows['Управленческие расходы']) ? dcf_build_series($finRows['Управленческие расходы'], $periodMap) : [];
    $deprSeries      = isset($finRows['Амортизация']) ? dcf_build_series($finRows['Амортизация'], $periodMap) : [];

    // Построение временных рядов для балансовых показателей
    $balanceSeries = [];
    foreach ($balRows as $metric => $row) {
        $balanceSeries[$metric] = dcf_build_series($row, $periodMap);
    }

    // Определение структуры таблицы: фактические годы и прогнозные периоды
    $factYears = ['2022', '2023', '2024'];           // Фактические годы для анализа
    $forecastLabels = ['P1', 'P2', 'P3', 'P4', 'P5']; // Прогнозные периоды (5 лет)
    
    // Формирование структуры колонок для отображения
    $columns = [];
    foreach ($factYears as $label) {
        $columns[] = ['key' => $label, 'label' => $label, 'type' => 'fact'];
    }
    foreach ($forecastLabels as $label) {
        $columns[] = ['key' => $label, 'label' => $label, 'type' => 'forecast'];
    }
    $columns[] = ['key' => 'TV', 'label' => 'TV', 'type' => 'tv']; // Terminal Value

    // Проверка наличия выручки за последний фактический год
    $lastFactLabel = '2024';
    if (($revenueSeries[$lastFactLabel] ?? 0) <= 0) {
        return ['error' => 'Укажите выручку минимум за три последних года (включая 2024).'];
    }

    // Инициализация массива для хранения фактических данных
    $factData = [
        'revenue' => [],      // Выручка
        'cogs' => [],         // Себестоимость продаж (Cost of Goods Sold)
        'commercial' => [],   // Коммерческие расходы
        'admin' => [],        // Административные расходы
        'depr' => [],         // Амортизация
        'ebitda' => [],       // EBITDA (прибыль до вычета процентов, налогов и амортизации)
        'ebit' => [],         // EBIT (прибыль до вычета процентов и налогов)
        'margin' => [],       // EBITDA-маржа (%)
    ];

    // Расчет фактических показателей за каждый год
    foreach ($factYears as $year) {
        $factData['revenue'][$year]    = $revenueSeries[$year] ?? 0;
        $factData['cogs'][$year]       = $cogsSeries[$year] ?? 0;
        $factData['commercial'][$year] = $commercialSeries[$year] ?? 0;
        $factData['admin'][$year]      = $adminSeries[$year] ?? 0;
        $factData['depr'][$year]       = $deprSeries[$year] ?? 0;
        
        // EBITDA = Выручка - Себестоимость - Коммерческие расходы - Административные расходы
        $factData['ebitda'][$year]     = $factData['revenue'][$year] - $factData['cogs'][$year] - $factData['commercial'][$year] - $factData['admin'][$year];
        
        // EBIT = EBITDA - Амортизация
        $factData['ebit'][$year]       = $factData['ebitda'][$year] - $factData['depr'][$year];
        
        // EBITDA-маржа = EBITDA / Выручка
        $factData['margin'][$year]     = ($factData['revenue'][$year] > 0) ? $factData['ebitda'][$year] / $factData['revenue'][$year] : null;
    }

    // Расчет фактических темпов роста выручки (год к году)
    $factGrowth = [];
    $prevRevenue = null;
    foreach ($factYears as $year) {
        $current = $factData['revenue'][$year];
        if ($prevRevenue !== null && abs($prevRevenue) > 1e-6) {
            // Темп роста = (Текущая выручка - Предыдущая выручка) / Предыдущая выручка
            $factGrowth[$year] = ($current - $prevRevenue) / $prevRevenue;
        } else {
            $factGrowth[$year] = null; // Для первого года рост не рассчитывается
        }
        $prevRevenue = $current;
    }
    
    // Расчет среднего темпа роста и темпа роста за последний год
    $growthValues = array_values(array_filter($factGrowth, fn($value) => $value !== null));
    $gAvg = !empty($growthValues) ? array_sum($growthValues) / count($growthValues) : 0.05; // Средний темп роста
    $gLastFact = $factGrowth[$lastFactLabel] ?? 0.05; // Темп роста за последний фактический год (2024)

    // Генерация seed для детерминированных случайных значений
    $seedKey = ($form['asset_name'] ?? '') . '|' . ($form['id'] ?? '0');
    
    // Определение "якорных" точек темпа роста для прогнозных периодов
    // Логика основана на среднем историческом темпе роста:
    // - При отрицательном росте: постепенное восстановление к 4%
    // - При низком росте: умеренное ускорение
    // - При высоком росте: постепенное замедление к долгосрочному уровню 4%
    $growthAnchors = [];
    if ($gAvg <= -0.20) {
        // Сильно отрицательный рост: восстановление через P3, P4
        $growthAnchors[2] = 0.022;
        $growthAnchors[3] = 0.0315;
        $growthAnchors[4] = 0.04;
    } elseif ($gAvg <= 0) {
        // Отрицательный или нулевой рост: восстановление через P4
        $growthAnchors[3] = 0.0525;
        $growthAnchors[4] = 0.04;
    } elseif ($gAvg <= 0.1275) {
        // Умеренный рост: поддержание высокого темпа в P1, затем замедление
        $growthAnchors[0] = 0.1275;
        $growthAnchors[3] = 0.066;
        $growthAnchors[4] = 0.04;
    } else {
        // Высокий рост: постепенное замедление
        $growthAnchors[3] = 0.066;
        $growthAnchors[4] = 0.04;
    }
    // Гарантируем, что P5 всегда имеет долгосрочный темп роста 4%
    if (!isset($growthAnchors[4])) {
        $growthAnchors[4] = 0.04;
    }

    // Определение темпа роста для P1
    // P1 должен быть выше темпа роста за последний год, но не более чем на 10 п.п.
    $p1Candidate = $growthAnchors[0] ?? clampFloat($gAvg, -0.20, 0.35);
    $p1Candidate = max($p1Candidate, $gLastFact + 0.0001); // Минимум: последний факт + 0.01%
    $p1Candidate = min($p1Candidate, $gLastFact + 0.10);   // Максимум: последний факт + 10%
    if (abs($p1Candidate - $gLastFact) < 0.0001) {
        // Если кандидат слишком близок к последнему факту, добавляем минимальный шаг
        $p1Candidate = $gLastFact + 0.005;
    }
    $growthAnchors[0] = $p1Candidate;

    // Интерполяция темпов роста для всех прогнозных периодов
    // Используется линейная интерполяция между якорными точками
    $forecastGrowth = array_fill(0, 5, null);
    for ($i = 0; $i < 5; $i++) {
        // Если для периода уже есть якорная точка, используем её
        if (isset($growthAnchors[$i])) {
            $forecastGrowth[$i] = $growthAnchors[$i];
            continue;
        }
        
        // Ищем ближайшую якорную точку слева (предыдущую)
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (isset($growthAnchors[$j])) {
                $prev = [$j, $growthAnchors[$j]];
                break;
            }
        }
        
        // Ищем ближайшую якорную точку справа (следующую)
        $next = null;
        for ($j = $i + 1; $j < 5; $j++) {
            if (isset($growthAnchors[$j])) {
                $next = [$j, $growthAnchors[$j]];
                break;
            }
        }
        
        // Линейная интерполяция между предыдущей и следующей точками
        if ($prev && $next && $next[0] !== $prev[0]) {
            $ratio = ($i - $prev[0]) / ($next[0] - $prev[0]);
            $forecastGrowth[$i] = $prev[1] + ($next[1] - $prev[1]) * $ratio;
        } elseif ($prev) {
            // Если есть только предыдущая точка, используем её значение
            $forecastGrowth[$i] = $prev[1];
        } elseif ($next) {
            // Если есть только следующая точка, используем её значение
            $forecastGrowth[$i] = $next[1];
        } else {
            // Если нет якорных точек, используем кандидата для P1
            $forecastGrowth[$i] = $p1Candidate;
        }
    }

    // Добавление небольших случайных вариаций для реалистичности модели
    foreach ($forecastGrowth as $idx => $value) {
        $forecastGrowth[$idx] = clampFloat(
            $value + stableRandFloat($seedKey, $idx, -0.002, 0.002), // ±0.2% вариация
            -0.30,  // Минимальный темп роста: -30%
            0.40    // Максимальный темп роста: +40%
        );
    }
    
    // Гарантируем монотонное убывание темпов роста (реалистичный сценарий)
    for ($i = 1; $i < count($forecastGrowth); $i++) {
        if ($forecastGrowth[$i] > $forecastGrowth[$i - 1]) {
            // Если текущий период имеет больший рост, чем предыдущий, снижаем его
            $forecastGrowth[$i] = $forecastGrowth[$i - 1] - 0.003;
        }
    }
    
    // Финальная проверка ограничений для P1
    $forecastGrowth[0] = max($forecastGrowth[0], $gLastFact + 0.0001);
    $forecastGrowth[0] = min($forecastGrowth[0], $gLastFact + 0.10);
    
    // Принудительно устанавливаем темп роста P5 = 4% (последний год перед TV)
    // Это гарантирует плавный переход к бессрочному росту в Terminal Value
    $forecastGrowth[4] = 0.04;

    // Обработка бюджета 2025 года: если он указан, используем его для P1
    // Это позволяет учитывать планы компании на ближайший год
    $budgetRevenue = $revenueSeries['2025'] ?? null;
    $lastFactRevenue = $factData['revenue'][$lastFactLabel] ?? 0;
    $hasBudgetOverride = $budgetRevenue !== null && $budgetRevenue > 0;
    
    // Если есть бюджет 2025, пересчитываем темп роста P1 на основе бюджета
    if ($hasBudgetOverride && $lastFactRevenue > 0) {
        $forecastGrowth[0] = ($budgetRevenue - $lastFactRevenue) / $lastFactRevenue;
    }

    // Расчет прогнозной выручки для каждого периода
    $forecastRevenue = [];
    $prevRevenue = $lastFactRevenue;
    foreach ($forecastLabels as $index => $label) {
        if ($index === 0 && $hasBudgetOverride) {
            // Для P1: если есть бюджет 2025, используем его напрямую
            $prevRevenue = $budgetRevenue;
        } else {
            // Для остальных периодов: применяем темп роста к предыдущему периоду
            $prevRevenue = $prevRevenue * (1 + ($forecastGrowth[$index] ?? 0));
        }
        $forecastRevenue[$label] = max(0, $prevRevenue); // Выручка не может быть отрицательной
    }

    /**
     * Вычисляет долю показателя относительно базы (например, себестоимость к выручке)
     * Использует среднее значение за фактические годы, если значения стабильны
     * Если есть выбросы (отклонение >10%), использует последнее значение
     * 
     * @param array $values Значения показателя по годам
     * @param array $bases Базовые значения (например, выручка) по годам
     * @param array $years Список годов для анализа
     * @param float $fallback Значение по умолчанию, если данных нет
     * @return float Доля показателя (0.0 - 1.0)
     */
    $computeShare = function (array $values, array $bases, array $years, float $fallback) {
        $ratios = [];
        $lastRatio = null;
        foreach ($years as $year) {
            $base = $bases[$year] ?? 0;
            if ($base > 0) {
                $ratio = ($values[$year] ?? 0) / $base;
                $ratios[] = $ratio;
                $lastRatio = $ratio;
            }
        }
        if (empty($ratios)) {
            return $fallback;
        }
        $avg = array_sum($ratios) / count($ratios);
        // Проверка на выбросы: если есть отклонение >10% от среднего, используем последнее значение
        foreach ($ratios as $ratio) {
            if (abs($ratio - $avg) >= 0.10) {
                return $lastRatio ?? $avg;
            }
        }
        return $avg;
    };

    // Расчет долей себестоимости и коммерческих расходов от выручки
    $cogsShare = $computeShare($factData['cogs'], $factData['revenue'], $factYears, 0.6);
    $commercialShare = $computeShare($factData['commercial'], $factData['revenue'], $factYears, 0.12);

    // Расчет прогнозной себестоимости и коммерческих расходов
    // Используем исторические доли от выручки
    $forecastCogs = [];
    $forecastCommercial = [];
    foreach ($forecastLabels as $label) {
        $forecastCogs[$label] = $forecastRevenue[$label] * $cogsShare;
        $forecastCommercial[$label] = $forecastRevenue[$label] * $commercialShare;
    }

    // Проверка наличия административных расходов в исторических данных
    $adminExists = ($factData['admin']['2022'] ?? 0) > 0 || ($factData['admin']['2023'] ?? 0) > 0 || ($factData['admin']['2024'] ?? 0) > 0;
    $adminForecast = [];
    $ebitdaForecast = [];
    $ebitdaMarginForecast = [];
    
    // Прогноз инфляции для административных расходов (если они есть)
    $inflationPath = [0.091, 0.055, 0.045, 0.04, 0.04]; // Снижающаяся инфляция

    if ($adminExists) {
        // Если административные расходы есть в истории, прогнозируем их с учетом инфляции
        $prevAdmin = $factData['admin'][$lastFactLabel] ?? 0;
        foreach ($forecastLabels as $idx => $label) {
            $prevAdmin *= (1 + $inflationPath[$idx]); // Индексация на инфляцию
            $adminForecast[$label] = $prevAdmin;
            // EBITDA = Выручка - Себестоимость - Коммерческие - Административные
            $ebitdaForecast[$label] = $forecastRevenue[$label] - $forecastCogs[$label] - $forecastCommercial[$label] - $adminForecast[$label];
            $ebitdaMarginForecast[$label] = ($forecastRevenue[$label] > 0)
                ? $ebitdaForecast[$label] / $forecastRevenue[$label]
                : null;
        }
    } else {
        // Если административных расходов нет, используем целевой подход к марже
        // Целевая маржа постепенно улучшается
        $baseMargin = $factData['margin'][$lastFactLabel] ?? 0.2;
        $increment = stableRandFloat($seedKey, 99, 0.005, 0.008); // Небольшое улучшение маржи
        foreach ($forecastLabels as $idx => $label) {
            $targetMargin = clampFloat($baseMargin + $increment * ($idx + 1), 0, 0.6);
            $baseCosts = $forecastRevenue[$label] - ($forecastCogs[$label] + $forecastCommercial[$label]);
            $desiredEbitda = $forecastRevenue[$label] * $targetMargin;
            $delta = $desiredEbitda - $baseCosts;
            
            // Если базовая EBITDA ниже целевой, корректируем себестоимость и коммерческие расходы
            if ($delta > 0) {
                $costSum = max($forecastCogs[$label] + $forecastCommercial[$label], 1e-6);
                $adjustFactor = clampFloat($delta / $costSum, 0, 0.2);
                // Снижаем себестоимость на 70% от корректировки, коммерческие - на 30%
                $forecastCogs[$label] *= (1 - $adjustFactor * 0.7);
                $forecastCommercial[$label] *= (1 - $adjustFactor * 0.3);
            }
            $adminForecast[$label] = 0;
            $ebitdaForecast[$label] = $forecastRevenue[$label] - $forecastCogs[$label] - $forecastCommercial[$label];
            $ebitdaMarginForecast[$label] = ($forecastRevenue[$label] > 0)
                ? $ebitdaForecast[$label] / $forecastRevenue[$label]
                : null;
        }
    }

    // Получение стоимости основных средств на конец последнего фактического года
    $osLastFact = $balanceSeries['Основные средства'][$lastFactLabel] ?? null;
    if ($osLastFact === null || $osLastFact <= 0) {
        return ['error' => 'Не заполнены данные по основным средствам (баланс).'];
    }

    // Прогноз амортизации и поддерживающего CAPEX
    // Логика: амортизация = 10% от стоимости ОС, поддерживающий CAPEX = 50% от амортизации
    // Это обеспечивает поддержание основных средств на текущем уровне
    $deprForecast = [];
    $supportCapex = [];
    $osTrend = [];
    $prevOS = $osLastFact;
    foreach ($forecastLabels as $label) {
        $dep = 0.10 * $prevOS;        // Амортизация: 10% от стоимости ОС
        $capex = 0.5 * $dep;          // Поддерживающий CAPEX: 50% от амортизации
        $currentOS = $prevOS + $capex; // Новая стоимость ОС = старая + CAPEX
        $deprForecast[$label] = $dep;
        $supportCapex[$label] = $capex;
        $osTrend[$label] = $currentOS;
        $prevOS = $currentOS;
    }

    // Расчет EBIT и налога на прибыль
    $ebitForecast = [];
    $taxForecast = [];
    foreach ($forecastLabels as $label) {
        // EBIT = EBITDA - Амортизация
        $ebitForecast[$label] = $ebitdaForecast[$label] - $deprForecast[$label];
        // Налог на прибыль = EBIT * Ставка налога (только если EBIT > 0)
        $taxForecast[$label] = max(0, $ebitForecast[$label]) * $defaults['tax_rate'];
    }

    // Расчет коэффициентов оборотного капитала (NWC - Net Working Capital)
    // Используем последние 2 года для более точной оценки
    $tailYears = array_slice($factYears, -2);
    $factCostBase = [];
    foreach ($factYears as $year) {
        // База для расчета кредиторской задолженности: все операционные расходы
        $factCostBase[$year] = ($factData['cogs'][$year] ?? 0) + ($factData['commercial'][$year] ?? 0) + ($factData['admin'][$year] ?? 0);
    }
    
    // Коэффициенты оборотного капитала (доли от соответствующих баз):
    $avgArRatio = $computeShare($balanceSeries['Дебиторская задолженность'] ?? [], $factData['revenue'], $tailYears, 0.15); // ДЗ к выручке
    $avgInvRatio = $computeShare($balanceSeries['Запасы'] ?? [], $factData['cogs'], $tailYears, 0.12); // Запасы к себестоимости
    $avgApRatio = $computeShare($balanceSeries['Кредиторская задолженность'] ?? [], $factCostBase, $tailYears, 0.09); // КЗ к операционным расходам

    // Расчет фактического оборотного капитала (NWC) за исторические годы
    // NWC = Дебиторская задолженность + Запасы - Кредиторская задолженность
    $factNwc = [];
    foreach ($factYears as $year) {
        $ar = $balanceSeries['Дебиторская задолженность'][$year] ?? 0;
        $inv = $balanceSeries['Запасы'][$year] ?? 0;
        $ap = $balanceSeries['Кредиторская задолженность'][$year] ?? 0;
        $factNwc[$year] = $ar + $inv - $ap;
    }
    $nwcLastFact = $factNwc[$lastFactLabel] ?? 0;

    // Прогноз оборотного капитала и его изменений
    $nwcForecast = [];
    $deltaNwcForecast = [];
    foreach ($forecastLabels as $index => $label) {
        // Прогноз компонентов NWC на основе коэффициентов
        $ar = $forecastRevenue[$label] * $avgArRatio; // ДЗ = Выручка * Коэффициент ДЗ
        $inv = $forecastCogs[$label] * $avgInvRatio;  // Запасы = Себестоимость * Коэффициент запасов
        $apBase = $forecastCogs[$label] + $forecastCommercial[$label] + $adminForecast[$label];
        $ap = $apBase * $avgApRatio; // КЗ = Операционные расходы * Коэффициент КЗ
        
        $nwcForecast[$label] = $ar + $inv - $ap;
        
        // Изменение NWC: для P1 - относительно последнего факта, для остальных - относительно предыдущего периода
        if ($index === 0) {
            $deltaNwcForecast[$label] = $nwcForecast[$label] - $nwcLastFact;
        } else {
            $prevLabel = $forecastLabels[$index - 1];
            $deltaNwcForecast[$label] = $nwcForecast[$label] - $nwcForecast[$prevLabel];
        }
    }

    // Расчет FCFF (Free Cash Flow to Firm) - свободного денежного потока компании
    // FCFF = EBITDA - Налог на прибыль - Поддерживающий CAPEX - Изменение NWC
    $fcffForecast = [];
    foreach ($forecastLabels as $label) {
        $fcffForecast[$label] = $ebitdaForecast[$label]
            - $taxForecast[$label]
            - $supportCapex[$label]
            - $deltaNwcForecast[$label];
    }

    // Расчет временных коэффициентов для дисконтирования
    // Учитываем, что P1 может начаться не с начала года
    $currentDate = new DateTime();
    $currentMonth = (int)$currentDate->format('n');
    $currentDay = (int)$currentDate->format('j');
    $elapsedFraction = clampFloat((($currentMonth - 1) + ($currentDay / 30)) / 12, 0, 0.99); // Доля прошедшего года
    $remainingFraction = 1 - $elapsedFraction; // Доля оставшегося года
    $stubFraction = clampFloat((12 - $currentMonth) / 12, 0, 1); // Доля года до конца (для дисконтирования)

    // Корректировка FCFF для P1: учитываем только оставшуюся часть года
    $fcffDisplay = $fcffForecast;
    $fcffDisplay[$forecastLabels[0]] = $fcffForecast[$forecastLabels[0]] * $remainingFraction;

    $discountFactors = [];
    $discountedCf = [];
    $pvSum = 0;
    foreach ($forecastLabels as $index => $label) {
        // Period t for discounting: P1 is at stubFraction years, P2 at 1+stubFraction, etc.
        // For P1 (index=0): t = stubFraction (remaining part of current year)
        // For P2 (index=1): t = 1 + stubFraction (end of next year)
        // For P3 (index=2): t = 2 + stubFraction (end of year after next), etc.
        $t = $index + $stubFraction;
        $df = 1 / pow(1 + $defaults['wacc'], $t);
        $discountFactors[$label] = $df;
        $discountedCf[$label] = $fcffDisplay[$label] * $df;
        $pvSum += $discountedCf[$label];
    }

    // Terminal Value calculation using Gordon Growth Model
    // TV = FCF(n) * (1 + g) / (WACC - g)
    // where FCF(n) is the FCF of the last forecast year, g is perpetual growth rate
    $terminalFcff = end($fcffForecast);
    
    // Ensure WACC > perpetual_growth for the formula to work
    $wacc = $defaults['wacc'];
    $perpetualGrowth = $defaults['perpetual_growth'];
    if ($wacc <= $perpetualGrowth) {
        // If WACC <= growth, set growth to WACC - 0.01 to avoid division by zero
        $perpetualGrowth = max(0, $wacc - 0.01);
    }
    
    // Calculate Terminal Value at the end of the last forecast year
    $terminalValue = $terminalFcff * (1 + $perpetualGrowth) / ($wacc - $perpetualGrowth);
    
    // Discount TV to present value
    // TV is at the end of the last forecast period (same moment as last P period)
    // If we have 5 periods (P1-P5), P5 is discounted at (4 + stubFraction)
    // TV should be discounted at the same moment: (count - 1 + stubFraction)
    $terminalPeriod = (count($forecastLabels) - 1) + $stubFraction;
    $terminalDf = 1 / pow(1 + $wacc, $terminalPeriod);
    $terminalPv = $terminalValue * $terminalDf;
    $discountFactors['TV'] = $terminalDf;
    $discountedCf['TV'] = $terminalPv;

    // Расчет итоговых показателей стоимости
    $debt = $balanceSeries['Кредиты и займы'][$lastFactLabel] ?? 0; // Долг на конец последнего факта
    $cash = $balanceSeries['Денежные средства'][$lastFactLabel] ?? 0; // Денежные средства на конец последнего факта
    
    // Enterprise Value (EV) = Текущая стоимость FCFF + Текущая стоимость Terminal Value
    $enterpriseValue = $pvSum + $terminalPv;
    
    // Equity Value = Enterprise Value - Долг + Денежные средства
    $equityValue = $enterpriseValue - $debt + $cash;

    $buildValues = function (array $factValues, array $forecastValues, $tvValue = null) use ($factYears, $forecastLabels) {
        $values = [];
        foreach ($factYears as $year) {
            $values[$year] = $factValues[$year] ?? null;
        }
        foreach ($forecastLabels as $label) {
            $values[$label] = $forecastValues[$label] ?? null;
        }
        $values['TV'] = $tvValue;
        return $values;
    };

    $nullFact = array_fill_keys($factYears, null);
    $nullForecast = array_fill_keys($forecastLabels, null);

    $forecastGrowthAssoc = array_combine($forecastLabels, $forecastGrowth);
    $factTax = [];
    foreach ($factYears as $year) {
        $factTax[$year] = max(0, $factData['ebit'][$year] ?? 0) * $defaults['tax_rate'];
    }

    $rows = [
        [
            'label' => 'Выручка',
            'format' => 'money',
            'is_expense' => false,
            'values' => $buildValues($factData['revenue'], $forecastRevenue),
        ],
        [
            'label' => 'Темп роста, %',
            'format' => 'percent',
            'italic' => true,
            'values' => $buildValues(
                $factGrowth + [$factYears[0] => null],
                $forecastGrowthAssoc
            ),
        ],
        [
            'label' => 'Себестоимость',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['cogs'], $forecastCogs),
        ],
        [
            'label' => 'Коммерческие',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['commercial'], $forecastCommercial),
        ],
        [
            'label' => 'Административные',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factData['admin'], $adminForecast),
        ],
        [
            'label' => 'EBITDA',
            'format' => 'money',
            'is_expense' => false,
            'values' => $buildValues($factData['ebitda'], $ebitdaForecast),
        ],
        [
            'label' => 'EBITDA-маржа, %',
            'format' => 'percent',
            'italic' => true,
            'values' => $buildValues($factData['margin'], $ebitdaMarginForecast),
        ],
        [
            'label' => 'Налог (от EBIT)',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($factTax, $taxForecast),
        ],
        [
            'label' => 'Поддерживающий CAPEX',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($nullFact, $supportCapex),
        ],
        [
            'label' => 'ΔNWC',
            'format' => 'money',
            'is_expense' => true,
            'values' => $buildValues($nullFact, $deltaNwcForecast),
        ],
        [
            'label' => 'FCFF',
            'format' => 'money',
            'is_expense' => false,
            'star_columns' => [$forecastLabels[0]],
            'values' => $buildValues($nullFact, $fcffDisplay, $terminalValue), // TV включен в строку FCFF
        ],
        [
            'label' => 'Фактор дисконтирования',
            'format' => 'decimal',
            'values' => $buildValues($nullFact, $discountFactors, $discountFactors['TV']),
        ],
        [
            'label' => 'Discounted FCFF',
            'format' => 'money',
            'is_expense' => false,
            'values' => $buildValues($nullFact, $discountedCf, $terminalPv),
        ],
    ];

    $warnings = [];
    if ($forecastGrowth[0] <= $gLastFact) {
        $warnings[] = 'P1 скорректирован, чтобы быть выше фактического темпа 2024 года.';
    }
    if (abs($forecastGrowth[0] - $gLastFact) > 0.10) {
        $warnings[] = 'Отклонение P1 от g_last_fact ограничено 10 п.п. согласно регламенту.';
    }

    return [
        'columns' => $columns,
        'rows' => $rows,
        'wacc' => $defaults['wacc'],
        'perpetual_growth' => $defaults['perpetual_growth'],
        'footnotes' => ['* FCFF₁ скорректирован на оставшуюся часть года'],
        'warnings' => $warnings,
        'ev_breakdown' => [
            'ev' => $enterpriseValue,
            'debt' => $debt,
            'cash' => $cash,
            'equity' => $equityValue,
            'terminal_value' => $terminalValue,
            'terminal_pv' => $terminalPv,
            'discounted_sum' => $pvSum,
        ],
    ];
}

$latestForm = null;
$dcfData = null;
$dcfSourceStatus = null;

$latestSubmittedStmt = $pdo->prepare("
    SELECT *
    FROM seller_forms
    WHERE user_id = ?
      AND status IN ('submitted','review','approved')
    ORDER BY submitted_at DESC, updated_at DESC
    LIMIT 1
");
$latestSubmittedStmt->execute([$user['id']]);
$latestForm = $latestSubmittedStmt->fetch();

if ($latestForm) {
    $dcfSourceStatus = $latestForm['status'];
    $dcfData = calculateUserDCF($latestForm);
} else {
    $latestAnyStmt = $pdo->prepare("
        SELECT *
        FROM seller_forms
        WHERE user_id = ?
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $latestAnyStmt->execute([$user['id']]);
    $latestForm = $latestAnyStmt->fetch();
    if ($latestForm) {
        $dcfSourceStatus = $latestForm['status'] ?? null;
        if (in_array($dcfSourceStatus, ['submitted','review','approved'], true)) {
            $dcfData = calculateUserDCF($latestForm);
        } else {
            $dcfData = ['error' => 'DCF рассчитывается только по отправленным анкетам. Отправьте анкету, чтобы увидеть модель.'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - SmartBizSell.ru</title>
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .dashboard-header {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            padding: 40px 0;
            color: white;
            margin-bottom: 40px;
            margin-top: 80px;
        }
        .dashboard-header-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        .dashboard-header h1 {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .dashboard-header p {
            opacity: 0.9;
            font-size: 16px;
        }
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px 40px;
        }
        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        .stat-label {
            color: var(--text-secondary);
            font-size: 14px;
        }
        .dashboard-actions {
            display: flex;
            gap: 16px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: white;
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }
        .btn-secondary:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        .forms-table {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-color);
        }
        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 18px;
        }
        .table-row {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
            gap: 16px;
            align-items: center;
            transition: background 0.2s ease;
        }
        .table-row:hover {
            background: var(--bg-secondary);
        }
        .table-row:last-child {
            border-bottom: none;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }
        .empty-state-icon {
            font-size: 64px;
            margin-bottom: 16px;
        }
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 8px;
            color: var(--text-primary);
        }
        .ev-label-short {
            display: none;
        }
        .ev-label-full {
            display: inline;
        }
        @media (max-width: 768px) {
            .dashboard-header {
                padding: 24px 0;
                margin-top: 60px;
                margin-bottom: 24px;
            }
            .dashboard-header h1 {
                font-size: 24px;
            }
            .dashboard-header p {
                font-size: 14px;
            }
            .dashboard-container {
                padding: 0 16px 24px;
            }
            .dashboard-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
                margin-bottom: 24px;
            }
            .stat-card {
                padding: 16px;
            }
            .stat-value {
                font-size: 24px;
            }
            .stat-label {
                font-size: 12px;
            }
            .dashboard-actions {
                flex-direction: column;
                gap: 12px;
                margin-bottom: 24px;
            }
            .dashboard-actions .btn {
                width: 100%;
                text-align: center;
            }
            .table-row {
                grid-template-columns: 1fr;
                gap: 12px;
                padding: 16px;
            }
            .table-header {
                padding: 16px;
                font-size: 16px;
            }
            .forms-table {
                border-radius: 12px;
            }
            .dcf-card {
                padding: 16px;
                margin-top: 24px;
                border-radius: 12px;
            }
            .dcf-card h2 {
                font-size: 18px;
                margin-bottom: 12px;
            }
            /* Кнопка PDF на мобильных - размещаем сверху */
            .dcf-card > div[style*="display:flex"] {
                flex-direction: column !important;
                align-items: stretch !important;
                gap: 12px !important;
            }
            .dcf-card .btn-export-pdf {
                width: 100% !important;
                padding: 14px 20px !important;
                font-size: 15px !important;
                order: -1 !important; /* Кнопка сверху */
                margin-bottom: 8px;
            }
            .dcf-card > div[style*="display:flex"] > div:first-child {
                width: 100%;
            }
            .dcf-print-hint {
                display: block;
                margin-top: 8px;
                font-size: 12px;
                opacity: 0.7;
            }
            .dcf-card__actions {
                flex-direction: column;
                gap: 8px;
            }
            .dcf-card__actions .btn {
                width: 100%;
            }
            .dcf-params-strip {
                flex-direction: column;
                gap: 8px;
                font-size: 12px;
            }
            /* Обертка для горизонтальной прокрутки таблиц DCF */
            .dcf-table-wrapper {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -16px;
                padding: 0 16px;
            }
            .dcf-table {
                min-width: 600px;
                font-size: 12px;
            }
            .dcf-table th,
            .dcf-table td {
                padding: 8px 6px;
                white-space: nowrap;
            }
            .dcf-table--full th:first-child {
                width: 140px;
                min-width: 140px;
                position: sticky;
                left: 0;
                z-index: 10;
                background: rgba(245,247,250,0.98);
                box-shadow: 2px 0 4px rgba(0,0,0,0.05);
            }
            .dcf-table--full td:first-child {
                position: sticky;
                left: 0;
                z-index: 9;
                background: white;
                box-shadow: 2px 0 4px rgba(0,0,0,0.05);
            }
            .dcf-table--full tr:nth-child(even) td:first-child {
                background: rgba(248,250,252,0.98);
            }
            .dcf-table--ev {
                font-size: 10px;
                width: 100%;
                margin-bottom: 0;
                border-collapse: collapse;
                table-layout: fixed; /* Фиксированная ширина колонок */
            }
            .dcf-table--ev td {
                padding: 2px 4px;
                line-height: 1.15;
                word-wrap: break-word;
                overflow-wrap: break-word;
            }
            .dcf-table--ev td:first-child {
                font-size: 10px;
                width: 50%;
                padding-right: 2px;
                max-width: 50%;
            }
            .dcf-table--ev td:last-child {
                font-size: 11px;
                font-weight: 500;
                text-align: right;
                width: 50%;
                padding-left: 2px;
                white-space: nowrap; /* Числа не переносятся */
            }
            .dcf-table--ev tr:last-child td {
                font-weight: 600;
                font-size: 12px;
                padding-top: 4px;
                padding-bottom: 4px;
                border-top: 2px solid var(--border-color);
            }
            .dcf-table--ev tr:last-child td:first-child {
                width: 45%;
            }
            .dcf-table--ev tr:last-child td:last-child {
                width: 55%;
            }
            /* Убираем обертку для таблицы EV на мобильных, чтобы она была видна полностью */
            .dcf-table-wrapper--ev {
                overflow-x: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100%;
                max-width: 100%;
            }
            /* Улучшаем читаемость таблицы EV */
            .dcf-table--ev tr {
                border-bottom: 1px solid rgba(0,0,0,0.05);
            }
            .dcf-table--ev tr:last-child {
                border-bottom: none;
            }
            /* Компактные карточки для EV показателей */
            .dcf-table-wrapper--ev {
                width: 100%;
                max-width: 100%;
            }
            .dcf-table--ev,
            .dcf-table--ev tbody {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                width: 100%;
            }
            .dcf-table--ev {
                border-collapse: collapse;
                font-size: 11px;
            }
            .dcf-table--ev tr {
                display: flex;
                flex-direction: column;
                width: 100%;
                border: 1px solid var(--border-color);
                border-radius: 10px;
                padding: 6px 8px;
                background: white;
                box-shadow: var(--shadow-sm);
                min-width: 0; /* Позволяет сжиматься */
            }
            .dcf-table--ev tr + tr {
                margin-top: 8px;
            }
            .dcf-table--ev td {
                width: 100% !important;
                padding: 0 !important;
                border: none !important;
                text-align: left !important;
                line-height: 1.2;
                min-width: 0; /* Позволяет сжиматься */
                overflow: hidden;
            }
            .dcf-table--ev td:first-child {
                font-size: 10px;
                color: var(--text-secondary);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            .dcf-table--ev td:last-child {
                margin-top: 4px;
                font-size: 14px;
                font-weight: 600;
                color: var(--text-primary);
                white-space: normal;
                text-align: left !important;
            }
            .dcf-table--ev tr:last-child td:last-child {
                font-size: 15px;
            }
            .ev-label-short {
                display: inline;
            }
            .ev-label-full {
                display: none;
            }
            .debt-label-short {
                display: none;
            }
            .debt-label-full {
                display: inline;
            }
            /* На мобильных показываем короткие версии */
            @media (max-width: 768px) {
                .debt-label-short {
                    display: inline;
                }
                .debt-label-full {
                    display: none;
                }
            }
            /* Компактные отступы для DCF карточки */
            .dcf-footnote {
                font-size: 11px;
                margin-top: 8px;
                margin-bottom: 12px;
                line-height: 1.5;
            }
            /* Улучшаем отображение кнопок в таблице форм */
            .table-row > div:last-child {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .table-row .btn {
                width: 100%;
                padding: 10px 16px;
                font-size: 13px;
            }
            /* Дополнительные улучшения для мобильной версии */
            .dashboard-header-content {
                padding: 0 16px;
            }
            .empty-state {
                padding: 40px 16px;
            }
            .empty-state-icon {
                font-size: 48px;
            }
            .empty-state h3 {
                font-size: 18px;
            }
        }
        @media (max-width: 375px) {
            .dcf-table--ev tr {
                padding: 5px 6px !important;
            }
            .dcf-table--ev td:first-child {
                font-size: 9px !important;
            }
            .dcf-table--ev td:last-child {
                font-size: 12px !important;
            }
            .dcf-table--ev tr:last-child td:last-child {
                font-size: 13px !important;
            }
        }
        .dcf-card {
            margin-top: 48px;
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }
        .dcf-card h2 {
            margin-top: 0;
            font-size: 24px;
            margin-bottom: 16px;
        }
        .dcf-card__actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 16px;
        }
        .btn-export-pdf {
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            background: white;
        }
        .btn-export-pdf:hover {
            background: rgba(102, 126, 234, 0.05);
        }
        .dcf-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
        }
        .dcf-table th,
        .dcf-table td {
            border: 1px solid var(--border-color);
            padding: 12px;
            text-align: left;
        }
        .dcf-table th {
            background: rgba(245,247,250,0.8);
            font-weight: 600;
        }
        .dcf-table--full th:first-child {
            width: 220px;
        }
        .dcf-table--full td {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .dcf-table--full td:first-child {
            text-align: left;
            font-weight: 500;
            color: var(--text-primary);
        }
        .dcf-col-fact {
            background: rgba(248,250,252,0.6);
        }
        .dcf-col-forecast {
            background: rgba(255,255,255,0.8);
        }
        .dcf-col-tv {
            background: rgba(20,184,166,0.08);
        }
        .dcf-cell-tv {
            font-weight: 600;
        }
        .dcf-params-strip {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }
        .dcf-footnote {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: -12px;
            margin-bottom: 16px;
        }
        .dcf-table--ev {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-bottom: 0;
        }
        .dcf-table--ev td {
            border: none;
            padding: 6px 12px;
            text-align: right;
        }
        .dcf-table--ev td:first-child {
            text-align: left;
            font-weight: 500;
            color: var(--text-primary);
        }
        @media print {
            body.print-dcf * {
                visibility: hidden !important;
            }
            body.print-dcf #dcf-card,
            body.print-dcf #dcf-card * {
                visibility: visible !important;
            }
            body.print-dcf #dcf-card {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                box-shadow: none;
                border: none;
            }
        }
        .dcf-source-note {
            font-size: 13px;
            color: var(--text-secondary);
            margin: -8px 0 16px;
        }
        .dcf-source-note strong {
            color: var(--text-primary);
        }
        .dcf-source-note--warning {
            color: #ad6800;
        }
        .dcf-print-hint {
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
        }
        .warnings {
            margin-top: 16px;
            padding: 12px 16px;
            border-radius: 12px;
            background: #fff7e6;
            border: 1px solid #ffe2a8;
            color: #ad6800;
        }
        .teaser-section {
            margin-top: 32px;
            padding: 32px;
            border-radius: 24px;
            background: white;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(15, 23, 42, 0.06);
        }
        .teaser-header {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 24px;
        }
        .teaser-header h2 {
            margin: 0;
            font-size: 22px;
        }
        .teaser-header p {
            margin: 0;
            color: var(--text-secondary);
        }
        .teaser-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 12px;
        }
        .teaser-status {
            min-height: 24px;
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 16px;
        }
        .teaser-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
        }
        .teaser-card {
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 18px;
            padding: 20px;
            background: #fdfdfd;
            box-shadow: var(--shadow-sm);
        }
        .teaser-card h3 {
            margin: 0 0 10px;
            font-size: 16px;
            color: var(--text-primary);
        }
        .teaser-card__subtitle {
            margin: 0 0 10px;
            font-size: 14px;
            color: var(--text-secondary);
        }
        .teaser-card ul {
            padding-left: 18px;
            margin: 0;
            color: var(--text-primary);
        }
        .teaser-card ul li + li {
            margin-top: 6px;
        }
        .teaser-card__footer {
            margin-top: 12px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        .teaser-spinner {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
        }
        .teaser-spinner:before {
            content: "";
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-top-color: rgba(102, 126, 234, 0.9);
            animation: teaser-spin 0.8s linear infinite;
            display: inline-block;
        }
        @keyframes teaser-spin {
            to { transform: rotate(360deg); }
        }
        @media (max-width: 768px) {
            .teaser-section {
                padding: 20px;
            }
            .teaser-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="index.php" class="logo">
                    <span class="logo-icon">🚀</span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="index.php">Главная</a></li>
                    <li><a href="index.php#buy-business">Купить бизнес</a></li>
                    <li><a href="seller_form.php">Продать бизнес</a></li>
                    <li><a href="dashboard.php">Личный кабинет</a></li>
                    <li><a href="logout.php">Выйти</a></li>
                </ul>
                <button class="nav-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <div class="dashboard-header">
        <div class="dashboard-header-content">
            <h1>Личный кабинет</h1>
            <p>Добро пожаловать, <?php echo htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8'); ?>!</p>
        </div>
    </div>

    <div class="dashboard-container">
        <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
            <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 16px; border-radius: 12px; margin-bottom: 24px;">
                <strong>✓ Анкета успешно отправлена!</strong> Команда SmartBizSell изучит информацию и свяжется с вами.
            </div>
        <?php endif; ?>
        
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($forms); ?></div>
                <div class="stat-label">Всего анкет</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'submitted' || $f['status'] === 'review')); ?></div>
                <div class="stat-label">На проверке</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'approved')); ?></div>
                <div class="stat-label">Одобрено</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($forms, fn($f) => $f['status'] === 'draft')); ?></div>
                <div class="stat-label">Черновиков</div>
            </div>
        </div>

        <div class="dashboard-actions">
            <a href="seller_form.php" class="btn btn-primary">+ Создать новую анкету</a>
            <a href="profile.php" class="btn btn-secondary">Настройки профиля</a>
        </div>

        <div class="forms-table">
            <div class="table-header">Мои анкеты</div>
            
            <?php if (empty($activeForms)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📋</div>
                    <h3>У вас пока нет отправленных анкет</h3>
                    <p>Заполните и отправьте анкету, чтобы получить материалы по сделке</p>
                    <a href="seller_form.php" class="btn btn-primary" style="margin-top: 20px;">Создать анкету</a>
                </div>
            <?php else: ?>
                <div style="padding: 0;">
                    <?php foreach ($activeForms as $form): ?>
                        <div class="table-row">
                            <div>
                                <strong><?php echo htmlspecialchars($form['asset_name'] ?: 'Без названия', ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                                    Создана: <?php echo date('d.m.Y H:i', strtotime($form['created_at'])); ?>
                                </div>
                            </div>
                            <div>
                                <span class="status-badge" style="background: <?php echo $statusColors[$form['status']]; ?>; color: white;">
                                    <?php echo $statusLabels[$form['status']]; ?>
                                </span>
                            </div>
                            <div>
                                <div style="font-size: 12px; color: var(--text-secondary);">Обновлена:</div>
                                <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['updated_at'])); ?></div>
                            </div>
                            <div>
                                <?php if ($form['submitted_at']): ?>
                                    <div style="font-size: 12px; color: var(--text-secondary);">Отправлена:</div>
                                    <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['submitted_at'])); ?></div>
                                <?php else: ?>
                                    <div style="font-size: 12px; color: var(--text-secondary);">Не отправлена</div>
                                <?php endif; ?>
                            </div>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <a href="view_form.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">Просмотр</a>
                                <a href="seller_form.php?form_id=<?php echo $form['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 12px;">Редактировать</a>
                                <a href="export_form_json.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">📥 JSON</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($draftForms)): ?>
        <div class="forms-table" style="margin-top: 32px;">
            <div class="table-header">Черновики</div>
            <div style="padding: 0;">
                <?php foreach ($draftForms as $form): ?>
                    <div class="table-row">
                    <div>
                        <strong><?php echo htmlspecialchars($form['asset_name'] ?: 'Черновик без названия', ENT_QUOTES, 'UTF-8'); ?></strong>
                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 4px;">
                            Обновлён: <?php echo date('d.m.Y H:i', strtotime($form['updated_at'])); ?>
                        </div>
                    </div>
                    <div>
                        <span class="status-badge" style="background: <?php echo $statusColors['draft']; ?>; color: white;">
                            <?php echo $statusLabels['draft']; ?>
                        </span>
                    </div>
                    <div>
                        <div style="font-size: 12px; color: var(--text-secondary);">Создан:</div>
                        <div style="font-size: 14px;"><?php echo date('d.m.Y', strtotime($form['created_at'])); ?></div>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="seller_form.php?form_id=<?php echo $form['id']; ?>" class="btn btn-primary" style="padding: 8px 16px; font-size: 12px;">
                            Продолжить заполнение
                        </a>
                        <a href="export_form_json.php?id=<?php echo $form['id']; ?>" class="btn btn-secondary" style="padding: 8px 16px; font-size: 12px;">📥 JSON</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($dcfData): ?>
            <div class="dcf-card" id="dcf-card">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
                    <div>
                        <h2 style="margin-bottom:4px;">DCF Model</h2>
                    </div>
                    <button
                        type="button"
                        class="btn btn-export-pdf"
                        id="export-dcf-pdf"
                        data-asset-name="<?php echo htmlspecialchars($latestForm['asset_name'] ?? 'DCF', ENT_QUOTES, 'UTF-8'); ?>"
                        data-date-label="<?php echo isset($latestForm['submitted_at']) ? date('d.m.Y', strtotime($latestForm['submitted_at'])) : date('d.m.Y'); ?>"
                    >
                        Сохранить DCF в PDF
                    </button>
                </div>
                <?php if ($latestForm): ?>
                    <?php
                        $dcfAssetName = $latestForm['asset_name'] ?: 'Без названия';
                        $dcfDate = null;
                        if (!empty($latestForm['submitted_at'])) {
                            $dcfDate = date('d.m.Y', strtotime($latestForm['submitted_at']));
                        } elseif (!empty($latestForm['updated_at'])) {
                            $dcfDate = date('d.m.Y', strtotime($latestForm['updated_at']));
                        }
                        $dcfStatusLabel = $statusLabels[$dcfSourceStatus] ?? $dcfSourceStatus ?? 'Черновик';
                        $noteClasses = 'dcf-source-note';
                        if (!in_array($dcfSourceStatus, ['submitted','review','approved'], true)) {
                            $noteClasses .= ' dcf-source-note--warning';
                        }
                    ?>
                    <p class="<?php echo $noteClasses; ?>">
                        <?php if (in_array($dcfSourceStatus, ['submitted','review','approved'], true)): ?>
                            Расчёт построен по анкете «<?php echo htmlspecialchars($dcfAssetName, ENT_QUOTES, 'UTF-8'); ?>»
                            <?php if ($dcfDate): ?>от <?php echo $dcfDate; ?><?php endif; ?>
                            (статус: <?php echo htmlspecialchars($dcfStatusLabel, ENT_QUOTES, 'UTF-8'); ?>).
                        <?php else: ?>
                            Последняя анкета «<?php echo htmlspecialchars($dcfAssetName, ENT_QUOTES, 'UTF-8'); ?>»
                            имеет статус «<?php echo htmlspecialchars($dcfStatusLabel, ENT_QUOTES, 'UTF-8'); ?>». Отправьте анкету, чтобы рассчитать модель.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if (isset($dcfData['error'])): ?>
                    <div class="warnings"><?php echo htmlspecialchars($dcfData['error'], ENT_QUOTES, 'UTF-8'); ?></div>
                <?php else: ?>
                    <?php
                        $columnsMeta = $dcfData['columns'] ?? [];
                        $rows = $dcfData['rows'] ?? [];
                        $evData = $dcfData['ev_breakdown'] ?? null;
                        $formatMoney = static function ($value, bool $isExpense = false): string {
                            if ($value === null) {
                                return '—';
                            }
                            $rounded = round($value);
                            $formatted = number_format(abs($rounded), 0, '.', ' ');
                            if ($isExpense && $rounded > 0) {
                                return '(' . $formatted . ')';
                            }
                            if ($isExpense && $rounded < 0) {
                                return '−(' . $formatted . ')';
                            }
                            return ($rounded < 0 ? '−' : '') . $formatted;
                        };
                        $formatPercent = static function ($value, bool $italic = false): string {
                            if ($value === null) {
                                return '—';
                            }
                            $formatted = number_format($value * 100, 2, '.', ' ') . '%';
                            return $italic ? '<em>' . $formatted . '</em>' : $formatted;
                        };
                        $formatDecimal = static function ($value): string {
                            if ($value === null) {
                                return '—';
                            }
                            return number_format($value, 4, '.', ' ');
                        };
                        $formatEvRow = static function ($value) use ($formatMoney): string {
                            if ($value === null) {
                                return '—';
                            }
                            return $formatMoney($value) . ' млн ₽';
                        };
                    ?>
                    <div class="dcf-params-strip">
                        <span>WACC: <?php echo number_format(($dcfData['wacc'] ?? 0) * 100, 2, '.', ' '); ?>%</span>
                        <span>g: <?php echo number_format(($dcfData['perpetual_growth'] ?? 0) * 100, 2, '.', ' '); ?>%</span>
                    </div>
                    <div class="dcf-table-wrapper">
                    <table class="dcf-table dcf-table--full">
                        <thead>
                            <tr>
                                <th>Показатель</th>
                                <?php foreach ($columnsMeta as $column): ?>
                                    <th class="dcf-col-<?php echo htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars($column['label'], ENT_QUOTES, 'UTF-8'); ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php foreach ($columnsMeta as $column): ?>
                                        <?php
                                            $value = $row['values'][$column['key']] ?? null;
                                            $formattedValue = '—';
                                            if ($row['format'] === 'money') {
                                                $formattedValue = $formatMoney($value, $row['is_expense'] ?? false);
                                            } elseif ($row['format'] === 'percent') {
                                                $formattedValue = $formatPercent($value, $row['italic'] ?? false);
                                            } elseif ($row['format'] === 'decimal') {
                                                $formattedValue = $formatDecimal($value);
                                            } else {
                                                $formattedValue = htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
                                            }
                                            if (!empty($row['star_columns']) && in_array($column['key'], $row['star_columns'], true) && $formattedValue !== '—') {
                                                $formattedValue .= '*';
                                            }
                                        ?>
                                        <td class="dcf-cell-<?php echo htmlspecialchars($column['type'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo $formattedValue; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <?php if (!empty($dcfData['footnotes'])): ?>
                        <p class="dcf-footnote">
                            <?php foreach ($dcfData['footnotes'] as $note): ?>
                                <?php echo htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?><br>
                            <?php endforeach; ?>
                        </p>
                    <?php endif; ?>
                    <?php if ($evData): ?>
                        <div class="dcf-table-wrapper dcf-table-wrapper--ev">
                        <table class="dcf-table dcf-table--ev">
                            <tbody>
                                <tr>
                                    <td><span class="ev-label-full">Enterprise Value (EV)</span><span class="ev-label-short">EV</span></td>
                                    <td><?php echo $formatEvRow($evData['ev'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td><span class="debt-label-full">− Debt</span><span class="debt-label-short">Debt</span></td>
                                    <td>(<?php echo $formatMoney($evData['debt'] ?? 0); ?> млн ₽)</td>
                                </tr>
                                <tr>
                                    <td>+ Cash</td>
                                    <td><?php echo $formatEvRow($evData['cash'] ?? null); ?></td>
                                </tr>
                                <tr>
                                    <td><strong><span class="ev-label-full">Equity Value</span><span class="ev-label-short">Equity</span></strong></td>
                                    <td><strong><?php echo $formatEvRow($evData['equity'] ?? null); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($dcfData['warnings'])): ?>
                        <div class="warnings">
                            <strong>Контрольные замечания:</strong>
                            <ul>
                                <?php foreach ($dcfData['warnings'] as $warning): ?>
                                    <li><?php echo htmlspecialchars($warning, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($latestForm): ?>
            <div class="teaser-section" id="teaser-section">
                <div class="teaser-header">
                    <h2>AI-тизер компании</h2>
                    <p>Краткая презентация актива на основе данных анкеты и открытых источников.</p>
                    <div class="teaser-actions">
                        <button type="button" class="btn btn-primary" id="generate-teaser-btn">Создать тизер</button>
                    </div>
                </div>
                <div class="teaser-status" id="teaser-status"></div>
                <div class="teaser-result" id="teaser-result">
                    <p style="color: var(--text-secondary); margin: 0;">Нажмите «Создать тизер», чтобы получить структурированный документ для инвесторов.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="teaser-section">
                <div class="teaser-header">
                    <h2>AI-тизер компании</h2>
                    <p>Отправьте анкету, чтобы автоматически сформировать тизер.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const card = document.getElementById('dcf-card');
            const exportBtn = document.getElementById('export-dcf-pdf');
            if (card && exportBtn) {
                const originalText = exportBtn.textContent;

                const restoreState = () => {
                    document.body.classList.remove('print-dcf');
                    exportBtn.disabled = false;
                    exportBtn.textContent = originalText;
                };

                const handleAfterPrint = () => {
                    restoreState();
                    window.removeEventListener('afterprint', handleAfterPrint);
                };

                exportBtn.addEventListener('click', () => {
                    document.body.classList.add('print-dcf');
                    exportBtn.disabled = true;
                    exportBtn.textContent = 'Открывается диалог...';

                    window.addEventListener('afterprint', handleAfterPrint);

                    setTimeout(() => {
                        window.print();
                        // На некоторых iOS/Safari события afterprint нет — возвращаем состояние сами
                        setTimeout(restoreState, 1000);
                    }, 50);
                });
            }

            const teaserBtn = document.getElementById('generate-teaser-btn');
            const teaserStatus = document.getElementById('teaser-status');
            const teaserResult = document.getElementById('teaser-result');

            if (teaserBtn && teaserStatus && teaserResult) {
                teaserBtn.addEventListener('click', async () => {
                    teaserBtn.disabled = true;
                    teaserStatus.innerHTML = '<span class="teaser-spinner">Генерируем тизер...</span>';
                    teaserResult.style.opacity = '0.6';

                    try {
                        const response = await fetch('generate_teaser.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({}),
                        });

                        const payload = await response.json();
                        if (!response.ok || !payload.success) {
                            throw new Error(payload.message || 'Не удалось создать тизер.');
                        }

                        teaserResult.innerHTML = payload.html;
                        teaserStatus.textContent = 'Готово! Тизер сформирован.';
                    } catch (error) {
                        teaserStatus.textContent = error.message || 'Ошибка генерации тизера.';
                    } finally {
                        teaserBtn.disabled = false;
                        teaserResult.style.opacity = '1';
                    }
                });
            }
        });
    </script>
    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

