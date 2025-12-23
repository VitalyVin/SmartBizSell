<?php
/**
 * services/valuation.php
 * 
 * Страница услуги "Оценка бизнеса"
 * SEO-оптимизированная страница с информацией об услуге
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once '../config.php';

$pageTitle = "Оценка бизнеса - DCF модель, мультипликаторы, профессиональная оценка стоимости | SmartBizSell";
$pageDescription = "Профессиональная оценка стоимости бизнеса методом DCF (дисконтированных денежных потоков) и мультипликаторов. Автоматический расчет за минуты. Используется в M&A сделках и для принятия инвестиционных решений.";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="оценка бизнеса, оценка стоимости бизнеса, DCF модель, мультипликаторы оценки, дисконтированные денежные потоки, оценка компании">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/services/valuation">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/services/valuation">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/../yandex_metrika.php'; ?>
    
    <style>
        .service-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 100px 20px 60px;
        }
        .service-hero {
            text-align: center;
            margin-bottom: 60px;
        }
        .service-hero h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .service-hero p {
            font-size: 20px;
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto;
        }
        .service-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 40px;
            margin-bottom: 60px;
        }
        .service-main {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }
        .service-main h2 {
            font-size: 32px;
            font-weight: 700;
            margin: 30px 0 20px;
            color: var(--text-primary);
        }
        .service-main h2:first-child {
            margin-top: 0;
        }
        .service-main h3 {
            font-size: 24px;
            font-weight: 600;
            margin: 24px 0 16px;
            color: var(--text-primary);
        }
        .service-main p {
            font-size: 18px;
            line-height: 1.8;
            margin-bottom: 20px;
            color: var(--text-secondary);
        }
        .service-main ul {
            margin: 20px 0;
            padding-left: 30px;
        }
        .service-main li {
            font-size: 18px;
            line-height: 1.8;
            margin-bottom: 12px;
            color: var(--text-secondary);
        }
        .service-sidebar {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .service-card {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
        }
        .service-card h3 {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-primary);
        }
        .service-cta {
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            color: white;
            padding: 30px;
            border-radius: 16px;
            text-align: center;
        }
        .service-cta h3 {
            color: white;
            margin-bottom: 16px;
        }
        .service-cta p {
            color: rgba(255, 255, 255, 0.9);
            margin-bottom: 24px;
        }
        .btn-service {
            display: inline-block;
            padding: 14px 32px;
            background: white;
            color: #667EEA;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            transition: transform 0.3s ease;
        }
        .btn-service:hover {
            transform: translateY(-2px);
        }
        @media (max-width: 768px) {
            .service-content {
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
                <a href="/" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="/#features">Возможности</a></li>
                    <li><a href="/#how-it-works">Как это работает</a></li>
                    <li><a href="/#buy-business">Купить бизнес</a></li>
                    <li><a href="/blog">Блог</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="../dashboard.php">Продать бизнес</a></li>
                        <li><a href="../dashboard.php">Личный кабинет</a></li>
                        <li><a href="../logout.php">Выйти</a></li>
                    <?php else: ?>
                        <li><a href="../login.php">Продать бизнес</a></li>
                        <li><a href="../login.php">Войти</a></li>
                        <li><a href="../register.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="service-page">
        <div class="service-hero">
            <h1>Оценка бизнеса</h1>
            <p>Профессиональная оценка стоимости бизнеса методом DCF и мультипликаторов. Автоматический расчет за минуты вместо недель работы консультантов.</p>
        </div>

        <div class="service-content">
            <div class="service-main">
                <h2>Методы оценки бизнеса</h2>
                <p>
                    Правильная оценка стоимости бизнеса — основа успешной сделки. Мы используем два основных метода оценки, 
                    которые применяются в профессиональной M&A практике и инвестбанкинге.
                </p>

                <h3>1. Метод DCF (Discounted Cash Flow)</h3>
                <p>
                    Метод дисконтированных денежных потоков — это наиболее точный способ оценки бизнеса, основанный на прогнозе 
                    будущих денежных потоков компании.
                </p>
                <p><strong>Как это работает:</strong></p>
                <ul>
                    <li>Анализ исторических финансовых данных за последние 3-5 лет</li>
                    <li>Прогнозирование денежных потоков на 5 лет вперед с учетом темпов роста</li>
                    <li>Расчет терминальной стоимости (стоимости бизнеса после прогнозного периода)</li>
                    <li>Дисконтирование всех будущих денежных потоков к текущей стоимости</li>
                    <li>Учет WACC (средневзвешенной стоимости капитала) и долгосрочного темпа роста</li>
                </ul>
                <p>
                    Платформа автоматически рассчитывает DCF модель на основе данных вашей анкеты, учитывая:
                </p>
                <ul>
                    <li>Выручку, себестоимость, коммерческие и управленческие расходы</li>
                    <li>Амортизацию и CAPEX (инвестиции в основные средства)</li>
                    <li>Изменение оборотного капитала</li>
                    <li>Налоги и чистую прибыль</li>
                    <li>Свободный денежный поток (FCFF)</li>
                </ul>

                <h3>2. Метод мультипликаторов</h3>
                <p>
                    Оценка на основе сравнения с аналогичными компаниями в отрасли. Используются отраслевые мультипликаторы:
                </p>
                <ul>
                    <li><strong>EV/Revenue (Enterprise Value / Выручка):</strong> отношение стоимости компании к выручке</li>
                    <li><strong>EV/EBITDA:</strong> отношение стоимости компании к прибыли до вычета процентов, налогов и амортизации</li>
                    <li><strong>P/E (Price/Earnings):</strong> отношение цены акции к прибыли на акцию (для публичных компаний)</li>
                </ul>
                <p>
                    Платформа автоматически определяет сектор вашего бизнеса с помощью ИИ и применяет соответствующие мультипликаторы 
                    для расчета справедливой стоимости.
                </p>

                <h3>Преимущества автоматической оценки</h3>
                <ul>
                    <li><strong>Скорость:</strong> расчет занимает минуты вместо недель</li>
                    <li><strong>Точность:</strong> используются стандартные методы оценки, применяемые в инвестбанкинге</li>
                    <li><strong>Прозрачность:</strong> все расчеты доступны для просмотра и проверки</li>
                    <li><strong>Объективность:</strong> оценка основана на данных, а не на субъективных мнениях</li>
                    <li><strong>Два метода:</strong> DCF и мультипликаторы дают диапазон справедливой стоимости</li>
                </ul>

                <h2>Когда нужна оценка бизнеса</h2>
                <ul>
                    <li><strong>Продажа бизнеса:</strong> для определения справедливой цены</li>
                    <li><strong>Покупка бизнеса:</strong> для проверки заявленной стоимости</li>
                    <li><strong>Привлечение инвестиций:</strong> для определения доли инвестора</li>
                    <li><strong>Реструктуризация:</strong> для оценки активов при реорганизации</li>
                    <li><strong>Налоговые цели:</strong> для определения стоимости при передаче бизнеса</li>
                    <li><strong>Стратегическое планирование:</strong> для понимания текущей стоимости компании</li>
                </ul>

                <h2>Что входит в оценку</h2>
                <ul>
                    <li>DCF модель с прогнозом на 5 лет</li>
                    <li>Расчет стоимости методом мультипликаторов</li>
                    <li>Сравнительный анализ двух методов</li>
                    <li>Детальная таблица с финансовыми показателями</li>
                    <li>Графики динамики выручки и денежных потоков</li>
                    <li>Расчет WACC и других параметров</li>
                </ul>
            </div>

            <div class="service-sidebar">
                <div class="service-card">
                    <h3>Методы оценки</h3>
                    <ul style="list-style: none; padding-left: 0;">
                        <li>✓ DCF (дисконтированные денежные потоки)</li>
                        <li>✓ Мультипликаторы (EV/Revenue, EV/EBITDA)</li>
                        <li>✓ Сравнительный анализ</li>
                    </ul>
                </div>

                <div class="service-card">
                    <h3>Сроки</h3>
                    <p>Расчет оценки: <strong>5-10 минут</strong></p>
                    <p>После заполнения анкеты оценка рассчитывается автоматически</p>
                </div>

                <div class="service-cta">
                    <h3>Получить оценку</h3>
                    <p>Заполните анкету и получите профессиональную оценку бизнеса</p>
                    <a href="<?php echo isLoggedIn() ? '../dashboard.php' : '../login.php'; ?>" class="btn-service">
                        Начать оценку
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Структурированные данные -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Service",
        "name": "Оценка бизнеса",
        "description": "<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>",
        "provider": {
            "@type": "Organization",
            "name": "SmartBizSell",
            "url": "<?php echo BASE_URL; ?>"
        },
        "serviceType": "Оценка бизнеса",
        "areaServed": {
            "@type": "Country",
            "name": "Россия"
        }
    }
    </script>

    <script src="../script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

