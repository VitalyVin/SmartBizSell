<?php
/**
 * services/buy-business.php
 * 
 * Страница услуги "Покупка бизнеса"
 * SEO-оптимизированная страница с информацией об услуге
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once '../config.php';

$pageTitle = "Покупка бизнеса - Каталог готовых бизнесов, поиск и оценка | SmartBizSell";
$pageDescription = "Найдите готовый бизнес для покупки в нашем каталоге. Детальные тизеры с финансовыми моделями, фильтрация по отраслям и цене. Помощь в выборе и оценке бизнеса для покупки.";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="купить бизнес, покупка бизнеса, каталог бизнесов, готовый бизнес, инвестиции в бизнес, выбор бизнеса для покупки">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/services/buy-business">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/services/buy-business">
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
            <h1>Покупка бизнеса</h1>
            <p>Найдите готовый бизнес для покупки в нашем каталоге. Детальная информация, финансовые модели и профессиональные тизеры помогут сделать правильный выбор.</p>
        </div>

        <div class="service-content">
            <div class="service-main">
                <h2>Каталог готовых бизнесов</h2>
                <p>
                    На платформе SmartBizSell представлены готовые бизнесы различных отраслей и масштабов. 
                    Каждый бизнес проходит профессиональную подготовку: создается тизер с финансовыми моделями, 
                    оценкой стоимости и детальным описанием, что позволяет вам принимать обоснованные решения.
                </p>

                <h3>Преимущества покупки бизнеса через SmartBizSell</h3>
                <ul>
                    <li><strong>Профессиональная подготовка:</strong> все бизнесы представлены с детальными тизерами, созданными по стандартам инвестбанкинга</li>
                    <li><strong>Финансовое моделирование:</strong> для каждого бизнеса доступны DCF модели и оценка методом мультипликаторов</li>
                    <li><strong>Прозрачность:</strong> полная информация о финансовых показателях, рынке, команде и рисках</li>
                    <li><strong>Фильтрация:</strong> удобный поиск по отраслям, цене, локации, финансовым показателям</li>
                    <li><strong>Проверка:</strong> все тизеры проходят модерацию командой M&A-консультантов</li>
                </ul>

                <h3>Как выбрать бизнес для покупки</h3>
                <p>
                    При выборе бизнеса важно учитывать несколько факторов:
                </p>
                <ul>
                    <li><strong>Отрасль:</strong> выберите отрасль, в которой у вас есть опыт или интерес</li>
                    <li><strong>Размер:</strong> определите подходящий масштаб бизнеса по выручке и количеству сотрудников</li>
                    <li><strong>Локация:</strong> учтите географическое расположение бизнеса</li>
                    <li><strong>Финансовые показатели:</strong> изучите выручку, прибыль, маржинальность и темпы роста</li>
                    <li><strong>Риски и возможности:</strong> проанализируйте описанные риски и потенциал роста</li>
                </ul>

                <h3>Процесс покупки бизнеса</h3>
                <ol>
                    <li><strong>Просмотр каталога:</strong> изучите доступные бизнесы, используйте фильтры для поиска</li>
                    <li><strong>Изучение тизеров:</strong> откройте детальные тизеры с финансовыми моделями и графиками</li>
                    <li><strong>Связь с продавцом:</strong> используйте кнопку "Связаться с продавцом" для получения дополнительной информации</li>
                    <li><strong>Due Diligence:</strong> проведите комплексную проверку бизнеса (при необходимости)</li>
                    <li><strong>Переговоры:</strong> согласуйте условия сделки, используйте Term Sheet для фиксации ключевых условий</li>
                    <li><strong>Закрытие сделки:</strong> завершите сделку с помощью юристов и консультантов</li>
                </ol>

                <h3>Что вы найдете в тизере</h3>
                <p>
                    Каждый бизнес представлен профессиональным тизером, который включает:
                </p>
                <ul>
                    <li>Обзор возможности и ключевые метрики</li>
                    <li>Профиль компании (отрасль, история, команда)</li>
                    <li>Описание продуктов и услуг, ключевых клиентов</li>
                    <li>Анализ рынка и конкурентной среды</li>
                    <li>Финансовый профиль с графиками динамики</li>
                    <li>Инвестиционные преимущества и риски</li>
                    <li>Параметры сделки (цена, доля, структура)</li>
                    <li>Документы актива (если предоставлены продавцом)</li>
                </ul>

                <h2>Фильтры поиска</h2>
                <p>
                    На платформе доступны удобные фильтры для поиска подходящего бизнеса:
                </p>
                <ul>
                    <li><strong>По отраслям:</strong> IT, ритейл, производство, услуги, рестораны, e-commerce, недвижимость и др.</li>
                    <li><strong>По цене:</strong> от 5 млн до 100+ млн рублей</li>
                    <li><strong>По локации:</strong> Москва, Санкт-Петербург, Екатеринбург, другие города</li>
                    <li><strong>По финансовым показателям:</strong> выручка, прибыль, маржинальность</li>
                </ul>
            </div>

            <div class="service-sidebar">
                <div class="service-card">
                    <h3>Каталог бизнесов</h3>
                    <p>Более 50 готовых бизнесов различных отраслей и масштабов</p>
                    <p style="margin-top: 16px;">
                        <a href="/#buy-business" style="color: #667EEA; font-weight: 600; text-decoration: none;">
                            Посмотреть каталог →
                        </a>
                    </p>
                </div>

                <div class="service-card">
                    <h3>Отрасли</h3>
                    <ul style="list-style: none; padding-left: 0;">
                        <li>• IT и технологии</li>
                        <li>• Ритейл</li>
                        <li>• Производство</li>
                        <li>• Услуги</li>
                        <li>• Рестораны</li>
                        <li>• E-commerce</li>
                        <li>• Недвижимость</li>
                        <li>• И другие</li>
                    </ul>
                </div>

                <div class="service-cta">
                    <h3>Хотите продать бизнес?</h3>
                    <p>Разместите свой бизнес в нашем каталоге</p>
                    <a href="<?php echo isLoggedIn() ? '../dashboard.php' : '../login.php'; ?>" class="btn-service">
                        Продать бизнес
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
        "name": "Покупка бизнеса",
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

