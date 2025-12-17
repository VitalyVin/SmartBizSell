<?php
/**
 * services/ma-advisory.php
 * 
 * Страница услуги "M&A консалтинг"
 * SEO-оптимизированная страница с информацией об услуге
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once '../config.php';

$pageTitle = "M&A консалтинг - Консультации по сделкам слияний и поглощений | SmartBizSell";
$pageDescription = "Профессиональные консультации по M&A сделкам. Помощь в структурировании сделок, переговорах, due diligence, подготовке документов. Опыт десятков закрытых сделок слияний и поглощений.";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="M&A консалтинг, консультации M&A, сделки слияний и поглощений, консультации по сделкам, due diligence, структурирование сделок">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/services/ma-advisory">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/services/ma-advisory">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="stylesheet" href="../styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
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
            <h1>M&A консалтинг</h1>
            <p>Профессиональные консультации по сделкам слияний и поглощений. Опыт десятков закрытых сделок и глубокое понимание рынка.</p>
        </div>

        <div class="service-content">
            <div class="service-main">
                <h2>Что такое M&A консалтинг</h2>
                <p>
                    M&A (Mergers and Acquisitions) — это сделки по слиянию и поглощению компаний. 
                    M&A консалтинг включает профессиональную помощь на всех этапах сделки: от подготовки к продаже 
                    до закрытия сделки и интеграции бизнесов.
                </p>

                <h3>Наши услуги M&A консалтинга</h3>
                <ul>
                    <li><strong>Структурирование сделок:</strong> помощь в определении оптимальной структуры сделки (cash-out, cash-in, обмен акциями и др.)</li>
                    <li><strong>Оценка и финансовая модель:</strong> профессиональная оценка бизнеса методом DCF и мультипликаторов</li>
                    <li><strong>Подготовка материалов:</strong> создание тизеров, инвестиционных меморандумов, term sheet</li>
                    <li><strong>Поиск контрагентов:</strong> помощь в поиске покупателей, продавцов или партнеров для сделки</li>
                    <li><strong>Переговоры:</strong> консультации по стратегии переговоров, согласованию условий</li>
                    <li><strong>Due Diligence:</strong> организация и проведение комплексной проверки бизнеса</li>
                    <li><strong>Юридическое сопровождение:</strong> координация с юристами, подготовка документов</li>
                    <li><strong>Закрытие сделки:</strong> сопровождение до финального закрытия сделки</li>
                </ul>

                <h3>Этапы M&A сделки</h3>
                <ol>
                    <li><strong>Подготовка:</strong> оценка бизнеса, подготовка материалов, определение стратегии</li>
                    <li><strong>Поиск контрагента:</strong> поиск покупателя/продавца, первичные контакты</li>
                    <li><strong>Переговоры:</strong> обсуждение условий, создание term sheet</li>
                    <li><strong>Due Diligence:</strong> комплексная проверка бизнеса (финансовая, юридическая, операционная)</li>
                    <li><strong>Согласование условий:</strong> финализация условий сделки, подготовка документов</li>
                    <li><strong>Закрытие:</strong> подписание документов, передача активов, расчеты</li>
                    <li><strong>Интеграция:</strong> объединение бизнесов (для сделок слияния)</li>
                </ol>

                <h3>Преимущества работы с нами</h3>
                <ul>
                    <li><strong>Опыт:</strong> команда с десятками закрытых M&A сделок в различных отраслях</li>
                    <li><strong>Технологии:</strong> использование ИИ для ускорения подготовки материалов</li>
                    <li><strong>Прозрачность:</strong> все расчеты и модели доступны для просмотра</li>
                    <li><strong>Эффективность:</strong> автоматизация рутинных задач экономит время и деньги</li>
                    <li><strong>Профессионализм:</strong> материалы соответствуют стандартам инвестбанкинга</li>
                </ul>

                <h2>Типы M&A сделок</h2>
                <ul>
                    <li><strong>Продажа бизнеса:</strong> полная или частичная продажа компании</li>
                    <li><strong>Покупка бизнеса:</strong> приобретение готового бизнеса</li>
                    <li><strong>Слияние:</strong> объединение двух компаний в одну</li>
                    <li><strong>Поглощение:</strong> покупка контрольного пакета акций компании</li>
                    <li><strong>Привлечение инвестиций:</strong> продажа доли инвестору (PE, VC фонды)</li>
                    <li><strong>Выход инвестора:</strong> продажа доли существующего инвестора</li>
                </ul>

                <h2>Структура сделки</h2>
                <p>
                    Правильная структура сделки — ключ к успеху. Мы помогаем определить оптимальную структуру:
                </p>
                <ul>
                    <li><strong>Cash-out:</strong> продавец получает денежные средства и выходит из бизнеса</li>
                    <li><strong>Cash-in:</strong> привлечение новых инвестиций в бизнес</li>
                    <li><strong>Комбинированная:</strong> сочетание cash-out и cash-in</li>
                    <li><strong>Обмен акциями:</strong> сделка с использованием акций вместо денег</li>
                    <li><strong>Рефинансирование долга:</strong> реструктуризация долговых обязательств</li>
                </ul>
            </div>

            <div class="service-sidebar">
                <div class="service-card">
                    <h3>Услуги</h3>
                    <ul style="list-style: none; padding-left: 0;">
                        <li>✓ Структурирование сделок</li>
                        <li>✓ Оценка бизнеса</li>
                        <li>✓ Подготовка материалов</li>
                        <li>✓ Поиск контрагентов</li>
                        <li>✓ Переговоры</li>
                        <li>✓ Due Diligence</li>
                        <li>✓ Закрытие сделки</li>
                    </ul>
                </div>

                <div class="service-card">
                    <h3>Опыт</h3>
                    <p>Десятки закрытых M&A сделок в различных отраслях</p>
                    <p style="margin-top: 16px;">Команда M&A-профессионалов с опытом работы в крупных консалтинговых компаниях</p>
                </div>

                <div class="service-cta">
                    <h3>Нужна консультация?</h3>
                    <p>Свяжитесь с нами для обсуждения вашей сделки</p>
                    <a href="/#contact" class="btn-service">
                        Связаться с нами
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
        "name": "M&A консалтинг",
        "description": "<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>",
        "provider": {
            "@type": "Organization",
            "name": "SmartBizSell",
            "url": "<?php echo BASE_URL; ?>"
        },
        "serviceType": "M&A консалтинг",
        "areaServed": {
            "@type": "Country",
            "name": "Россия"
        }
    }
    </script>

    <script src="../script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

