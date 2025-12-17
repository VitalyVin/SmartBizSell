<?php
/**
 * about.php
 * 
 * Страница "О нас"
 * Информация о компании, команде, опыте и миссии
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

$pageTitle = "О нас - Команда SmartBizSell, опыт, миссия | SmartBizSell";
$pageDescription = "SmartBizSell - это команда M&A-профессионалов с опытом десятков закрытых сделок. Мы объединили наш опыт с искусственным интеллектом, чтобы сделать продажу и покупку бизнеса доступнее и эффективнее.";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="о SmartBizSell, команда M&A, опыт сделок, слияния и поглощения, M&A консультанты">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/about">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/about">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        .about-page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 100px 20px 60px;
        }
        .about-hero {
            text-align: center;
            margin-bottom: 60px;
        }
        .about-hero h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .about-hero p {
            font-size: 20px;
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto;
        }
        .about-section {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            margin-bottom: 40px;
        }
        .about-section h2 {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 24px;
            color: var(--text-primary);
        }
        .about-section h3 {
            font-size: 24px;
            font-weight: 600;
            margin: 30px 0 16px;
            color: var(--text-primary);
        }
        .about-section p {
            font-size: 18px;
            line-height: 1.8;
            margin-bottom: 20px;
            color: var(--text-secondary);
        }
        .about-section ul {
            margin: 20px 0;
            padding-left: 30px;
        }
        .about-section li {
            font-size: 18px;
            line-height: 1.8;
            margin-bottom: 12px;
            color: var(--text-secondary);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 30px;
            margin: 40px 0;
        }
        .stat-card {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 16px;
        }
        .stat-value {
            font-size: 48px;
            font-weight: 800;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }
        .stat-label {
            font-size: 16px;
            color: var(--text-secondary);
            font-weight: 600;
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
                        <li><a href="dashboard.php">Продать бизнес</a></li>
                        <li><a href="dashboard.php">Личный кабинет</a></li>
                        <li><a href="logout.php">Выйти</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Продать бизнес</a></li>
                        <li><a href="login.php">Войти</a></li>
                        <li><a href="register.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <div class="about-page">
        <div class="about-hero">
            <h1>О SmartBizSell</h1>
            <p>Команда M&A-профессионалов, которая объединила опыт десятков закрытых сделок с современными технологиями искусственного интеллекта</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">50+</div>
                <div class="stat-label">Закрытых сделок</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">10+</div>
                <div class="stat-label">Лет опыта</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">15+</div>
                <div class="stat-label">Отраслей</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">100%</div>
                <div class="stat-label">Прозрачность</div>
            </div>
        </div>

        <div class="about-section">
            <h2>Наша миссия</h2>
            <p>
                Сделать продажу и покупку бизнеса доступной, прозрачной и эффективной для компаний любого размера. 
                Мы верим, что каждый бизнес заслуживает профессиональной подготовки к сделке, но не каждый может позволить 
                себе услуги крупных инвестиционных банков.
            </p>
            <p>
                SmartBizSell объединяет опыт команды M&A-профессионалов с искусственным интеллектом, чтобы автоматизировать 
                рутинные задачи и ускорить процесс подготовки материалов в 10+ раз, сохраняя при этом профессиональный 
                уровень и соответствие стандартам инвестбанкинга.
            </p>
        </div>

        <div class="about-section">
            <h2>Наш опыт</h2>
            <p>
                Команда SmartBizSell имеет опыт работы над десятками M&A сделок в различных отраслях:
            </p>
            <ul>
                <li>IT и технологии (SaaS, разработка, цифровые сервисы)</li>
                <li>Ритейл и торговля</li>
                <li>Производство</li>
                <li>Услуги</li>
                <li>Рестораны и кафе</li>
                <li>E-commerce</li>
                <li>Недвижимость</li>
                <li>Логистика и транспорт</li>
                <li>Сельское хозяйство</li>
                <li>Здравоохранение</li>
                <li>Нефтегаз</li>
                <li>Финансовый сектор</li>
            </ul>
            <p>
                Мы работали с бизнесами различных масштабов: от небольших компаний с выручкой в несколько миллионов рублей 
                до крупных предприятий с выручкой в сотни миллионов.
            </p>
        </div>

        <div class="about-section">
            <h2>Наши технологии</h2>
            <h3>Искусственный интеллект</h3>
            <p>
                Мы используем современные модели искусственного интеллекта (Qwen от Together.ai) для:
            </p>
            <ul>
                <li>Автоматической генерации текстов тизеров на основе данных анкеты</li>
                <li>Анализа финансовых показателей и выявления трендов</li>
                <li>Определения сектора бизнеса для применения правильных мультипликаторов</li>
                <li>Поиска подходящих инвесторов с использованием RAG-технологий</li>
                <li>Создания term sheet с ключевыми условиями сделки</li>
            </ul>

            <h3>Финансовое моделирование</h3>
            <p>
                Платформа автоматически рассчитывает:
            </p>
            <ul>
                <li>DCF модели с прогнозом на 5 лет</li>
                <li>Оценку методом мультипликаторов</li>
                <li>Темпы роста, маржинальность, CAPEX</li>
                <li>Изменение оборотного капитала</li>
                <li>WACC и другие финансовые параметры</li>
            </ul>
        </div>

        <div class="about-section">
            <h2>Наши ценности</h2>
            <ul>
                <li><strong>Прозрачность:</strong> все расчеты и модели доступны для просмотра и проверки</li>
                <li><strong>Профессионализм:</strong> материалы соответствуют стандартам инвестбанкинга</li>
                <li><strong>Эффективность:</strong> автоматизация ускоряет процесс в 10+ раз</li>
                <li><strong>Доступность:</strong> профессиональные услуги для бизнесов любого размера</li>
                <li><strong>Опыт:</strong> команда с реальным опытом закрытых сделок</li>
            </ul>
        </div>

        <div class="about-section">
            <h2>Как мы работаем</h2>
            <p>
                Мы объединяем лучшее из двух миров: опыт и экспертизу команды M&A-профессионалов с мощью искусственного интеллекта.
            </p>
            <ol>
                <li><strong>Автоматизация:</strong> ИИ обрабатывает данные и создает базовые материалы за минуты</li>
                <li><strong>Проверка:</strong> команда проверяет и модерирует материалы перед публикацией</li>
                <li><strong>Доработка:</strong> при необходимости материалы корректируются и улучшаются</li>
                <li><strong>Публикация:</strong> готовые материалы публикуются и используются для поиска контрагентов</li>
                <li><strong>Сопровождение:</strong> помощь на всех этапах сделки до закрытия</li>
            </ol>
        </div>

        <div class="about-section">
            <h2>Контакты</h2>
            <p><strong>Email:</strong> <?php echo ADMIN_EMAIL; ?></p>
            <p><strong>Сайт:</strong> <a href="<?php echo BASE_URL; ?>"><?php echo BASE_URL; ?></a></p>
            <p><strong>Локация:</strong> Москва, Россия</p>
        </div>
    </div>

    <!-- Структурированные данные -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "AboutPage",
        "mainEntity": {
            "@type": "Organization",
            "name": "SmartBizSell",
            "url": "<?php echo BASE_URL; ?>",
            "logo": "<?php echo BASE_URL; ?>/logo.png",
            "description": "<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>",
            "address": {
                "@type": "PostalAddress",
                "addressLocality": "Москва",
                "addressCountry": "RU"
            },
            "contactPoint": {
                "@type": "ContactPoint",
                "contactType": "Customer Service",
                "email": "<?php echo ADMIN_EMAIL; ?>"
            },
            "foundingDate": "2024",
            "numberOfEmployees": {
                "@type": "QuantitativeValue",
                "value": "10-50"
            }
        }
    }
    </script>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

