<?php
/**
 * teaser_pdf.php
 * 
 * Отдельная страница для формирования PDF тизера компании.
 * Оптимизирована для печати на одной странице формата A4.
 * 
 * Функциональность:
 * - Загружает сохраненный HTML тизера из базы данных
 * - Отображает тизер с оптимизированными стилями для A4 (210mm x 297mm)
 * - Автоматически инициализирует ApexCharts для отображения финансовых графиков
 * - Автоматически запускает печать через 1 секунду после загрузки
 * - Использует градиентные фоны и визуальные эффекты для всех элементов
 * - Гарантирует размещение всего контента на одной странице A4
 * 
 * Создано: 2025-01-XX
 * 
 * @package SmartBizSell
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
 * Получение данных тизера из базы данных
 * Загружается последняя отправленная анкета пользователя со статусом
 * 'submitted', 'review' или 'approved', из которой извлекается
 * сохраненный HTML тизера (teaser_snapshot).
 */
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT *
        FROM seller_forms
        WHERE user_id = ?
          AND status IN ('submitted','review','approved')
        ORDER BY submitted_at DESC, updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $form = $stmt->fetch();

    if (!$form) {
        die('Нет отправленных анкет для формирования тизера.');
    }

    // Извлечение данных тизера из JSON поля data_json
    $formData = json_decode($form['data_json'] ?? '{}', true);
    if (!is_array($formData)) {
        $formData = [];
    }

    // Получение сохраненного HTML тизера
    $teaserSnapshot = $formData['teaser_snapshot'] ?? null;
    if (!$teaserSnapshot || empty($teaserSnapshot['html'])) {
        die('Тизер еще не создан. Пожалуйста, создайте тизер в личном кабинете.');
    }

    $teaserHtml = $teaserSnapshot['html'];
    $generatedAt = $teaserSnapshot['generated_at'] ?? null;
    $assetName = $form['asset_name'] ?? 'Актив';

} catch (PDOException $e) {
    error_log("Error fetching teaser: " . $e->getMessage());
    die('Ошибка загрузки данных тизера.');
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тизер компании - <?php echo htmlspecialchars($assetName, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        /**
         * Настройки страницы A4
         * Размер: 210mm x 297mm (стандартный формат A4)
         * Отступы: 8mm со всех сторон
         * Рабочая область: 194mm x 281mm
         */
        @page {
            size: A4;
            margin: 8mm;
            overflow: hidden;
        }
        
        /**
         * HTML элемент настроен на точную высоту A4
         * overflow: hidden предотвращает появление второй страницы
         */
        html {
            height: 297mm;
            max-height: 297mm;
            overflow: hidden;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /**
         * Основной контейнер body
         * Фиксированная высота 297mm (A4) с overflow: hidden для предотвращения второй страницы
         * Flexbox layout для вертикального распределения контента
         */
        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            font-size: 10px;
            line-height: 1.35;
            color: #1f2937;
            background: #fff;
            width: 210mm;
            height: 297mm;
            max-height: 297mm;
            padding: 0;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /**
         * Основная секция тизера
         * Высота: 281mm (297mm - 8mm*2 отступы)
         * Padding: 5mm для внутренних отступов
         * Градиентный фон для визуальной привлекательности
         * Flexbox для распределения hero-блока и grid с карточками
         */
        .teaser-section {
            width: 100%;
            height: 281mm;
            max-height: 281mm;
            padding: 5mm;
            background: linear-gradient(180deg, #ffffff 0%, #f7f8fb 100%);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
        }
        
        .teaser-section::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at top right, rgba(99,102,241,0.08), transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .teaser-section > * {
            position: relative;
            z-index: 1;
        }

        /**
         * Hero блок - верхний блок с названием компании и ключевыми метриками
         * flex-shrink: 0 предотвращает сжатие блока при нехватке места
         * Градиентный фон и радиальный градиент через ::before для визуального эффекта
         */
        .teaser-hero {
            margin-bottom: 3mm;
            padding: 3mm;
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border-radius: 4px;
            border: 0.5px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .teaser-hero::before {
            content: "";
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(99,102,241,0.15), transparent 70%);
            pointer-events: none;
        }
        
        .teaser-hero > * {
            position: relative;
            z-index: 1;
        }

        .teaser-hero__title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 2mm;
            line-height: 1.2;
        }

        .teaser-hero__tags {
            display: flex;
            flex-wrap: wrap;
            gap: 2mm;
            margin-bottom: 2mm;
        }

        .teaser-chip {
            padding: 1.5mm 2.5mm;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            border-radius: 3px;
            font-size: 8px;
            font-weight: 500;
            box-shadow: 0 2px 6px rgba(99, 102, 241, 0.3);
        }

        .teaser-hero__stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2mm;
            margin-top: 2mm;
        }

        .teaser-stat {
            padding: 2mm;
            background: rgba(255, 255, 255, 0.9);
            border-radius: 3px;
            text-align: center;
            box-shadow: 0 2px 6px rgba(15, 23, 42, 0.06);
            border: 0.5px solid rgba(255, 255, 255, 0.8);
        }

        .teaser-stat strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.5mm;
            line-height: 1.2;
        }

        .teaser-stat span {
            font-size: 8px;
            color: #64748b;
        }

        /**
         * Сетка карточек тизера
         * 3 колонки для размещения карточек в ряд
         * flex: 1 занимает оставшееся пространство после hero-блока
         * min-height: 0 позволяет flex-элементу сжиматься при необходимости
         */
        .teaser-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2.5mm;
            margin-top: 2.5mm;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }

        .teaser-card {
            padding: 3mm;
            background: #fff;
            border: 0.5px solid rgba(15, 23, 42, 0.08);
            border-radius: 4px;
            page-break-inside: avoid;
            break-inside: avoid;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            min-height: 0;
        }
        
        .teaser-card::before {
            content: "";
            position: absolute;
            inset: 0;
            opacity: 0.15;
            background: linear-gradient(135deg, #a855f7, #6366f1);
            pointer-events: none;
            z-index: 0;
        }
        
        .teaser-card[data-variant="overview"]::before { 
            background: linear-gradient(135deg, #a855f7, #6366f1); 
        }
        .teaser-card[data-variant="profile"]::before { 
            background: linear-gradient(135deg, #14b8a6, #22d3ee); 
        }
        .teaser-card[data-variant="products"]::before { 
            background: linear-gradient(135deg, #f97316, #facc15); 
        }
        .teaser-card[data-variant="market"]::before { 
            background: linear-gradient(135deg, #0ea5e9, #38bdf8); 
        }
        .teaser-card[data-variant="financial"]::before { 
            background: linear-gradient(135deg, #6366f1, #8b5cf6); 
        }
        .teaser-card[data-variant="highlights"]::before { 
            background: linear-gradient(135deg, #f43f5e, #fb7185); 
        }
        .teaser-card[data-variant="deal"]::before { 
            background: linear-gradient(135deg, #10b981, #22c55e); 
        }
        .teaser-card[data-variant="next"]::before { 
            background: linear-gradient(135deg, #0ea5e9, #14b8a6); 
        }
        .teaser-card[data-variant="chart"]::before { 
            background: linear-gradient(135deg, #818cf8, #6366f1); 
        }
        
        .teaser-card > * {
            position: relative;
            z-index: 1;
        }

        /**
         * Блок "Обзор возможности" занимает всю ширину сетки (все 3 колонки)
         */
        .teaser-card[data-variant="overview"] {
            grid-column: 1 / -1;
        }

        /**
         * Блок финансового графика занимает две колонки из трех
         * Это обеспечивает больший размер графика для лучшей читаемости
         */
        .teaser-card[data-variant="chart"] {
            grid-column: span 2;
        }

        .teaser-card__icon {
            font-size: 14px;
            margin-bottom: 1.5mm;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.6);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-shadow: inset 0 0 8px rgba(99, 102, 241, 0.1);
            flex-shrink: 0;
        }

        .teaser-card h3 {
            font-size: 11px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 1.5mm;
            line-height: 1.3;
        }

        .teaser-card__subtitle {
            font-size: 9px;
            color: #64748b;
            margin-bottom: 1.5mm;
            font-weight: 500;
            line-height: 1.3;
        }

        .teaser-card p {
            font-size: 9px;
            line-height: 1.4;
            color: #475569;
            margin-bottom: 1.5mm;
        }

        .teaser-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
            flex: 1;
        }

        .teaser-card ul li {
            font-size: 9px;
            line-height: 1.4;
            color: #475569;
            padding-left: 4mm;
            margin-bottom: 0.8mm;
            position: relative;
        }

        .teaser-card ul li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 6px;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #6366f1;
            opacity: 0.8;
        }

        /**
         * Контейнер для финансового графика
         * Высота: 200px (оптимизирована для размещения на одной странице A4)
         * Градиентный фон для визуального выделения
         * ApexCharts будет отрендерен внутри этого контейнера
         */
        .teaser-chart {
            min-height: 180px;
            max-height: 200px;
            height: 200px;
            padding: 1.5mm;
            margin: 0;
            flex: 1;
            background: linear-gradient(135deg, rgba(99, 102, 241, 0.05) 0%, rgba(255, 255, 255, 0.98) 100%);
            border: 0.5px solid rgba(99, 102, 241, 0.15);
            border-radius: 4px;
            box-shadow: inset 0 0 10px rgba(99, 102, 241, 0.05);
            overflow: hidden;
        }

        .teaser-chart__note {
            font-size: 8px;
            color: #94a3b8;
            text-align: center;
            margin-top: 1mm;
            display: none;
        }

        /**
         * Стили для печати в PDF
         * print-color-adjust: exact гарантирует сохранение всех цветов и градиентов при печати
         * Фиксированные размеры предотвращают перенос на вторую страницу
         */
        @media print {
            @page {
                size: A4;
                margin: 8mm;
            }
            
            body {
                width: 210mm;
                height: 297mm;
                max-height: 297mm;
                margin: 0;
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                color-adjust: exact;
                overflow: hidden;
            }
            
            html {
                height: 297mm;
                max-height: 297mm;
            }

            .teaser-section {
                padding: 5mm;
                height: 281mm;
                max-height: 281mm;
                overflow: hidden;
            }
            
            body {
                max-height: 297mm;
                overflow: hidden;
            }

            /* Ensure gradients and backgrounds are printed */
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }

            /* Ensure ApexCharts renders correctly */
            .apexcharts-canvas,
            .apexcharts-canvas * {
                visibility: visible !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .apexcharts-svg {
                height: 200px !important;
                max-height: 200px !important;
            }

            /* Keep gradients and shadows in print */
            .teaser-hero,
            .teaser-card,
            .teaser-stat,
            .teaser-chip,
            .teaser-chart {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }

        /* Hide elements that shouldn't be in PDF */
        .teaser-actions,
        .teaser-progress,
        .teaser-status {
            display: none !important;
        }
    </style>
    <!-- ApexCharts for chart rendering -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3.45.1"></script>
</head>
<body>
    <div class="teaser-section">
        <?php echo $teaserHtml; ?>
    </div>

    <script>
        /**
         * Инициализация ApexCharts для отображения финансовых графиков в PDF
         * 
         * Процесс:
         * 1. Ожидание загрузки DOM
         * 2. Проверка наличия библиотеки ApexCharts
         * 3. Поиск всех контейнеров с атрибутом data-chart
         * 4. Парсинг JSON данных графика из атрибута data-chart
         * 5. Создание и рендеринг графика с оптимизированными настройками для PDF
         * 6. Автоматический запуск печати через 1 секунду после рендеринга
         * 
         * Создано: 2025-01-XX
         */
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof ApexCharts === 'undefined') {
                console.warn('ApexCharts is not available.');
                return;
            }

            // Поиск всех контейнеров для графиков
            const containers = document.querySelectorAll('.teaser-chart[data-chart]');
            containers.forEach((container, index) => {
                container.innerHTML = '';
                
                if (!container.id) {
                    container.id = 'teaser-chart-pdf-' + Date.now() + '-' + index;
                }
                const chartId = container.id;
                
                let payload;
                try {
                    payload = JSON.parse(container.getAttribute('data-chart') || '{}');
                } catch (error) {
                    console.error('Chart payload parse error', error);
                    return;
                }

                if (!payload || !Array.isArray(payload.series) || payload.series.length === 0) {
                    return;
                }

                const options = {
                    chart: {
                        id: chartId,
                        type: 'line',
                        height: 200,
                        parentHeightOffset: 0,
                        toolbar: { show: false },
                        fontFamily: 'Arial, sans-serif',
                    },
                    colors: payload.colors || ['#6366F1', '#0EA5E9', '#F97316', '#10B981'],
                    series: payload.series,
                    stroke: {
                        width: 3,
                        curve: 'smooth',
                    },
                    markers: {
                        size: 4,
                        strokeWidth: 2,
                    },
                    dataLabels: { enabled: false },
                    grid: {
                        strokeDashArray: 4,
                        borderColor: 'rgba(15,23,42,0.08)',
                        show: true,
                    },
                    xaxis: {
                        categories: payload.categories || [],
                        labels: {
                            style: {
                                colors: 'rgba(71,85,105,0.9)',
                                fontSize: '9px',
                            },
                        },
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                    },
                    yaxis: {
                        labels: {
                            style: {
                                colors: 'rgba(71,85,105,0.9)',
                                fontSize: '9px',
                            },
                            formatter: (value) => {
                                if (value === null || value === undefined) {
                                    return '';
                                }
                                const unit = payload.unit || '';
                                return `${Math.round(value).toLocaleString('ru-RU')} ${unit}`.trim();
                            },
                        },
                    },
                    legend: {
                        position: 'top',
                        horizontalAlign: 'left',
                        fontSize: '9px',
                        offsetY: -5,
                        offsetX: 0,
                        markers: { width: 8, height: 8, radius: 4 },
                        itemMargin: {
                            horizontal: 8,
                            vertical: 0,
                        },
                    },
                    tooltip: { enabled: false },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 0.2,
                            opacityFrom: 0.6,
                            opacityTo: 0.1,
                            stops: [0, 90, 100],
                        },
                    },
                };

                container.style.minHeight = '200px';
                container.style.height = '200px';
                container.style.maxHeight = '200px';
                
                const chart = new ApexCharts(container, options);
                chart.render().then(() => {
                    container.dataset.chartReady = '1';
                }).catch((error) => {
                    console.error('Chart render error:', error);
                });
            });

            /**
             * Автоматический запуск печати после рендеринга графиков
             * Задержка 1 секунда необходима для полного рендеринга ApexCharts
             * Это гарантирует, что графики будут видны в PDF
             */
            setTimeout(() => {
                window.print();
            }, 1000);
        });
    </script>
</body>
</html>

