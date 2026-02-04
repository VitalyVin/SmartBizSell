<?php
/**
 * services/sell-business.php
 * 
 * Страница услуги "Продажа бизнеса"
 * SEO-оптимизированная страница с информацией об услуге
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once '../config.php';

$pageTitle = "Продажа бизнеса - Подготовка к продаже, создание тизера, поиск покупателей | SmartBizSell";
$pageDescription = "Помогаем продать бизнес быстро и выгодно. Подготовка бизнеса к продаже, создание профессиональных тизеров с помощью ИИ, оценка стоимости, поиск покупателей и инвесторов. Опыт десятков закрытых сделок.";
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="продажа бизнеса, как продать бизнес, подготовка бизнеса к продаже, тизер бизнеса, поиск покупателей бизнеса, оценка бизнеса перед продажей">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/services/sell-business">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/services/sell-business">
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
            <h1>Продажа бизнеса</h1>
            <p>Помогаем продать бизнес быстро, выгодно и с минимальными рисками. Профессиональная подготовка, оценка и поиск покупателей.</p>
        </div>

        <div class="service-content">
            <div class="service-main">
                <h2>Как мы помогаем продать бизнес</h2>
                <p>
                    Продажа бизнеса — это сложный процесс, требующий профессиональной подготовки, правильной оценки и эффективного поиска покупателей. 
                    SmartBizSell объединяет опыт команды M&A-профессионалов с современными технологиями искусственного интеллекта, чтобы сделать этот процесс максимально быстрым и эффективным.
                </p>

                <h3>1. Подготовка бизнеса к продаже</h3>
                <p>
                    Первый шаг к успешной продаже — правильная подготовка. Мы помогаем:
                </p>
                <ul>
                    <li>Собрать и структурировать всю необходимую информацию о бизнесе</li>
                    <li>Подготовить финансовую отчетность и документы</li>
                    <li>Выявить и устранить потенциальные проблемы до начала продажи</li>
                    <li>Определить конкурентные преимущества и уникальные особенности бизнеса</li>
                </ul>

                <h3>2. Оценка стоимости бизнеса</h3>
                <p>
                    Правильная оценка — ключ к успешной продаже. Мы используем профессиональные методы оценки:
                </p>
                <ul>
                    <li><strong>DCF (дисконтированные денежные потоки):</strong> оценка на основе прогноза будущих денежных потоков</li>
                    <li><strong>Метод мультипликаторов:</strong> сравнение с аналогичными компаниями в отрасли</li>
                    <li><strong>Анализ рынка:</strong> изучение недавних сделок в вашей отрасли</li>
                </ul>
                <p>
                    Наша платформа автоматически рассчитывает стоимость бизнеса с помощью финансового моделирования, 
                    что позволяет получить объективную оценку за считанные минуты.
                </p>

                <h3>3. Создание профессионального тизера</h3>
                <p>
                    Тизер (инвестиционная презентация) — это первое впечатление о вашем бизнесе для потенциальных покупателей. 
                    Мы создаем профессиональные тизеры с помощью искусственного интеллекта:
                </p>
                <ul>
                    <li>Структурированное описание бизнеса и его преимуществ</li>
                    <li>Финансовые графики и показатели</li>
                    <li>Анализ рынка и конкурентной среды</li>
                    <li>Описание рисков и возможностей роста</li>
                    <li>Профессиональное оформление в стиле инвестбанкинга</li>
                </ul>
                <p>
                    ИИ анализирует данные вашей анкеты и создает тизер, который соответствует стандартам крупных инвестиционных банков, 
                    но при этом готов за несколько минут вместо недель работы консультантов.
                </p>

                <h3>4. Поиск покупателей и инвесторов</h3>
                <p>
                    После подготовки материалов мы помогаем найти подходящих покупателей:
                </p>
                <ul>
                    <li>Публикация тизера в каталоге бизнесов SmartBizSell</li>
                    <li>Автоматический подбор инвесторов на основе профиля бизнеса с использованием RAG-технологий</li>
                    <li>Прямые контакты с заинтересованными покупателями</li>
                    <li>Помощь в организации встреч и переговоров</li>
                </ul>

                <h3>5. Подготовка Term Sheet и сопровождение сделки</h3>
                <p>
                    Когда найден заинтересованный покупатель, мы помогаем:
                </p>
                <ul>
                    <li>Создать Term Sheet с ключевыми условиями сделки</li>
                    <li>Провести переговоры и согласовать условия</li>
                    <li>Организовать due diligence (проверку бизнеса)</li>
                    <li>Подготовить необходимые документы</li>
                </ul>

                <h2>Преимущества работы с SmartBizSell</h2>
                <ul>
                    <li><strong>Скорость:</strong> подготовка тизера и оценка занимают часы вместо недель</li>
                    <li><strong>Профессионализм:</strong> материалы соответствуют стандартам инвестбанкинга</li>
                    <li><strong>Опыт:</strong> команда с десятками закрытых M&A сделок</li>
                    <li><strong>Прозрачность:</strong> все расчеты и модели доступны для просмотра</li>
                    <li><strong>Эффективность:</strong> автоматизация рутинных задач экономит время и деньги</li>
                </ul>

                <h2>Процесс работы</h2>
                <ol>
                    <li><strong>Регистрация и заполнение анкеты:</strong> предоставьте информацию о вашем бизнесе через удобную форму</li>
                    <li><strong>Создание материалов:</strong> вы запускаете генерацию тизера в личном кабинете, ИИ создает тизер, DCF модель и оценку за несколько минут</li>
                    <li><strong>Модерация и доработка:</strong> вы отправляете тизер на модерацию, команда проверяет материалы за несколько часов и при необходимости корректирует</li>
                    <li><strong>Публикация и поиск покупателей:</strong> тизер публикуется, начинается поиск инвесторов</li>
                    <li><strong>Переговоры и сделка:</strong> сопровождение до закрытия сделки</li>
                </ol>
            </div>

            <div class="service-sidebar">
                <div class="service-card">
                    <h3>Что входит в услугу</h3>
                    <ul style="list-style: none; padding-left: 0;">
                        <li>✓ Оценка бизнеса (DCF + мультипликаторы)</li>
                        <li>✓ Создание профессионального тизера</li>
                        <li>✓ Публикация в каталоге</li>
                        <li>✓ Поиск инвесторов</li>
                        <li>✓ Создание Term Sheet</li>
                        <li>✓ Консультации по сделке</li>
                    </ul>
                </div>

                <div class="service-card">
                    <h3>Сроки</h3>
                    <p>Модерация тизера: <strong>несколько часов</strong></p>
                    <p>Поиск покупателей: <strong>от 2 недель</strong></p>
                    <p>Закрытие сделки: <strong>2-6 месяцев</strong></p>
                </div>

                <div class="service-cta">
                    <h3>Готовы продать бизнес?</h3>
                    <p>Начните с заполнения анкеты. Это займет 15-20 минут.</p>
                    <a href="<?php echo isLoggedIn() ? '../dashboard.php' : '../login.php'; ?>" class="btn-service">
                        Начать продажу бизнеса
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
        "name": "Продажа бизнеса",
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
        },
        "offers": {
            "@type": "Offer",
            "description": "Подготовка бизнеса к продаже, создание тизера, оценка стоимости, поиск покупателей"
        }
    }
    </script>

    <script src="../script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

