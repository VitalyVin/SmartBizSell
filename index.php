<?php
/**
 * Главная страница SmartBizSell.ru
 * 
 * Содержит:
 * - Навигацию с условным отображением для авторизованных/неавторизованных пользователей
 * - Hero секцию с описанием платформы
 * - Секцию возможностей
 * - Секцию "Как это работает"
 * - Каталог бизнесов для покупки
 * - Форму анкеты для продавцов (с сохранением в БД)
 * - Секцию контактов
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartBizSell.ru - Экспертная M&A платформа с ИИ</title>
    <meta name="description" content="Команда M&A-практиков SmartBizSell объединяет опыт десятков сделок и искусственный интеллект, чтобы сделать продажу и покупку бизнеса прозрачной, быстрой и эффективной.">
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- GSAP для плавных анимаций в стиле Apple.com -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="#" class="logo">
                    <span class="logo-icon">🚀</span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="#features">Возможности</a></li>
                    <li><a href="#how-it-works">Как это работает</a></li>
                    <li><a href="#buy-business">Купить бизнес</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="dashboard.php">Продать бизнес</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Продать бизнес</a></li>
                    <?php endif; ?>
                    <li><a href="#contact">Контакты</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="dashboard.php">Личный кабинет</a></li>
                        <li><a href="logout.php">Выйти</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Войти</a></li>
                        <li><a href="register.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
                <button class="nav-toggle" aria-label="Toggle menu">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-background">
            <div class="gradient-orb orb-1"></div>
            <div class="gradient-orb orb-2"></div>
            <div class="gradient-orb orb-3"></div>
        </div>
        <div class="container">
            <div class="hero-content">
                <h1 class="hero-title">
                    <span class="gradient-text">Экспертная M&amp;A платформа</span>
                    <br>для продажи и покупки бизнеса с поддержкой <span class="gradient-text">ИИ</span>
                </h1>
                <p class="hero-subtitle">
                    Мы — команда M&amp;A-профессионалов с десятками закрытых сделок. Платформа SmartBizSell объединяет наш опыт, современные технологии и искусственный интеллект, чтобы проводить сделки быстрее, прозрачнее и экономичнее.
                </p>
                <div class="hero-buttons">
                    <a href="<?php echo isLoggedIn() ? 'dashboard.php' : 'login.php'; ?>" class="btn btn-primary">
                        <span>Продать бизнес</span>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </a>
                    <a href="#features" class="btn btn-secondary">
                        <span>Узнать больше</span>
                    </a>
                </div>
                <div class="hero-stats">
                    <div class="stat-item" data-stat="500">
                        <div class="stat-number">500+</div>
                        <div class="stat-label">Проверенных инвесторов</div>
                    </div>
                    <div class="stat-item" data-stat="150">
                        <div class="stat-number">150+</div>
                        <div class="stat-label">Закрытых M&amp;A-сделок</div>
                    </div>
                    <div class="stat-item" data-stat="48">
                        <div class="stat-number">48ч</div>
                        <div class="stat-label">На подготовку материалов</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="features">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Преимущества SmartBizSell</h2>
                <p class="section-subtitle">Экспертиза команды M&amp;A, усиленная искусственным интеллектом и современными технологиями</p>
            </div>
            <div class="features-grid">
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="5" y="16" width="4" height="11" rx="2" fill="#6366F1"/>
                            <rect x="14" y="9" width="4" height="18" rx="2" fill="#8B5CF6"/>
                            <rect x="23" y="4" width="4" height="23" rx="2" fill="#A5B4FC"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">ИИ-Генерация тизеров</h3>
                    <p class="feature-description">
                        Используем проверенные нами подходы к тизерам и подключаем ИИ для точной аналитики, чтобы каждый инвестор сразу видел ценность бизнеса.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6 22L13 15L18 21L26 10" stroke="#22D3EE" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                            <circle cx="13" cy="15" r="2" fill="#22D3EE"/>
                            <circle cx="18" cy="21" r="2" fill="#22D3EE"/>
                            <circle cx="26" cy="10" r="2" fill="#22D3EE"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Автоматические финансовые модели</h3>
                    <p class="feature-description">
                        Формируем финансовые модели по стандартам сделок M&amp;A и ускоряем расчёты с помощью нейросетей — быстро, прозрачно и с учётом ключевых метрик.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M16 6L18.4721 12.5279L25 15L18.4721 17.4721L16 24L13.5279 17.4721L7 15L13.5279 12.5279L16 6Z" fill="url(#gradStar1)"/>
                            <defs>
                                <linearGradient id="gradStar1" x1="7" y1="6" x2="25" y2="24" gradientUnits="userSpaceOnUse">
                                    <stop stop-color="#FDE047"/>
                                    <stop offset="1" stop-color="#F97316"/>
                                </linearGradient>
                            </defs>
                        </svg>
                    </div>
                    <h3 class="feature-title">Ускорение процессов</h3>
                    <p class="feature-description">
                        Цифровые пайплайны заменяют ручные задачи: готовим материалы, структурируем данные и запускаем показы в разы быстрее традиционных процессов.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="10" cy="10" r="4" stroke="#F97316" stroke-width="2"/>
                            <circle cx="22" cy="10" r="4" stroke="#FACC15" stroke-width="2"/>
                            <circle cx="16" cy="22" r="4" stroke="#FB923C" stroke-width="2"/>
                            <path d="M12 12L15 19M20 12L17 19" stroke="#F97316" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Умный подбор покупателей</h3>
                    <p class="feature-description">
                        Соединяем данные о прошлых сделках, нашу экспертную оценку и алгоритмы рекомендаций, чтобы вывести к вам релевантных инвесторов без лишнего шума.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="6" y="10" width="20" height="16" rx="3" fill="#0EA5E9" opacity="0.2"/>
                            <rect x="9" y="6" width="14" height="20" rx="3" stroke="#0EA5E9" stroke-width="2" fill="none"/>
                            <rect x="13" y="14" width="6" height="8" rx="1" fill="#0EA5E9"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Современный интерфейс</h3>
                    <p class="feature-description">
                        Управляйте ходом сделки в едином цифровом кабинете: согласовывайте материалы, отслеживайте статус и общайтесь с командой в режиме реального времени.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="16" cy="16" r="11" stroke="#10B981" stroke-width="2" opacity="0.6"/>
                            <path d="M16 7V16L23 19" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M10 21C12 23 14 24 16 24C20 24 23 21 23 17" stroke="#34D399" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Безопасность данных</h3>
                    <p class="feature-description">
                        Следуем лучшим практикам комплаенса и используем корпоративный уровень защиты, чтобы вся информация о сделке оставалась конфиденциальной.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M14 4H8C7.46957 4 6.96086 4.21071 6.58579 4.58579C6.21071 4.96086 6 5.46957 6 6V26C6 26.5304 6.21071 27.0391 6.58579 27.4142C6.96086 27.7893 7.46957 28 8 28H24C24.5304 28 25.0391 27.7893 25.4142 27.4142C25.7893 27.0391 26 26.5304 26 26V12L18 4H14Z" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M18 4V12H26" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20 18H12" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M20 22H12" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M14 10H12" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h3 class="feature-title">Создание Term Sheet</h3>
                    <p class="feature-description">
                        Автоматически формируем инвестиционный меморандум с ключевыми условиями сделки. Term Sheet помогает закрепить параметры сделки и ускорить переговоры с инвесторами.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section id="how-it-works" class="how-it-works">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Как проходит сделка с нами</h2>
                <p class="section-subtitle">Совмещаем экспертное сопровождение и автоматизацию, чтобы вы видели каждый шаг и результат в цифрах</p>
            </div>
            <div class="steps">
                <div class="step-item">
                    <div class="step-number">01</div>
                    <div class="step-content">
                        <h3 class="step-title">Заполните анкету</h3>
                        <p class="step-description">
                            Делитесь ключевыми данными о компании. Мы убрали лишние вопросы и сразу подсказываем, какие цифры важны для успешной сделки.
                        </p>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-number">02</div>
                    <div class="step-content">
                        <h3 class="step-title">ИИ и аналитики готовят выводы</h3>
                        <p class="step-description">
                            Наш ИИ классифицирует показатели и выявляет драйверы роста, а команда M&amp;A-консультантов проверяет выводы и формирует стратегию сделки.
                        </p>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-number">03</div>
                    <div class="step-content">
                        <h3 class="step-title">Готовим материалы</h3>
                        <p class="step-description">
                            Создаём тизер, финансовую модель и Term Sheet по стандартам инвестбанкинга: ИИ ускоряет расчёты, а мы обеспечиваем точность, аргументацию и прозрачность цифр. Term Sheet помогает закрепить ключевые условия сделки и ускорить согласование с инвесторами.
                        </p>
                    </div>
                </div>
                <div class="step-item">
                    <div class="step-number">04</div>
                    <div class="step-content">
                        <h3 class="step-title">Выход на рынок</h3>
                        <p class="step-description">
                            Размещаем предложение на платформе, подключаем нашу сеть покупателей и управляем коммуникациями. Вы видите статус каждого лида и экономику сделки.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Buy Business Section -->
    <section id="buy-business" class="buy-business-section">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Купить бизнес</h2>
                <p class="section-subtitle">Изучайте сделки, подготовленные нашей M&amp;A-командой и подтверждённые аналитикой платформы</p>
            </div>
            
            <div class="filter-bar">
                <div class="filter-group">
                    <label for="filter-industry">Отрасль:</label>
                    <select id="filter-industry" class="filter-select">
                        <option value="">Все отрасли</option>
                        <option value="retail">Розничная торговля</option>
                        <option value="services">Услуги</option>
                        <option value="manufacturing">Производство</option>
                        <option value="it">IT и технологии</option>
                        <option value="restaurant">Рестораны и кафе</option>
                        <option value="ecommerce">E-commerce</option>
                        <option value="real_estate">Недвижимость</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter-price">Цена до:</label>
                    <select id="filter-price" class="filter-select">
                        <option value="">Любая цена</option>
                        <option value="5000000">до 5 млн ₽</option>
                        <option value="10000000">до 10 млн ₽</option>
                        <option value="50000000">до 50 млн ₽</option>
                        <option value="100000000">до 100 млн ₽</option>
                        <option value="999999999">свыше 100 млн ₽</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filter-location">Город:</label>
                    <select id="filter-location" class="filter-select">
                        <option value="">Все города</option>
                        <option value="moscow">Москва</option>
                        <option value="spb">Санкт-Петербург</option>
                        <option value="ekb">Екатеринбург</option>
                        <option value="other">Другие города</option>
                    </select>
                </div>
            </div>

            <div class="businesses-grid" id="businesses-grid">
                <!-- Business Card 1 -->
                <div class="business-card card-it"
                     data-industry="it"
                     data-price="15000000"
                     data-location="moscow"
                     data-id="1"
                     data-title="IT-Стартап по разработке SaaS"
                     data-revenue="12000000"
                     data-employees="8"
                     data-years="3"
                     data-profit="4800000"
                     data-growth="25"
                     data-description="Развивающийся SaaS-проект с активной клиентской базой. Продукт для автоматизации бизнес-процессов. Готовая команда разработки. Стабильный рост выручки."
                     data-full-description="Компания специализируется на разработке и поддержке SaaS-решений для автоматизации бизнес-процессов малого и среднего бизнеса. Продукт включает модули для управления проектами, CRM, аналитики и отчетности. Активная клиентская база насчитывает более 200 компаний. Команда из 8 опытных разработчиков и менеджеров. Бизнес работает по модели подписки (SaaS), что обеспечивает стабильный ежемесячный доход. Высокий потенциал для масштабирования."
                     data-advantages="Готовая команда разработки|Активная клиентская база 200+ компаний|Стабильный рекуррентный доход|Высокий потенциал роста|Современные технологии|Автоматизированные процессы"
                     data-risks="Зависимость от ключевых сотрудников|Конкуренция на рынке SaaS"
                     data-contact="+7 (495) 123-45-67">
                    <div class="card-header">
                        <div class="card-icon-bg">
                            <div class="card-icon">💻</div>
                        </div>
                        <div class="card-badge">Новое</div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">IT-Стартап по разработке SaaS</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">12 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">8</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">3 года</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Развивающийся SaaS-проект с активной клиентской базой. Продукт для автоматизации бизнес-процессов. Готовая команда разработки. Стабильный рост выручки.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">15 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 2 -->
                <div class="business-card card-restaurant"
                     data-industry="restaurant"
                     data-price="8000000"
                     data-location="moscow"
                     data-id="2"
                     data-title="Сеть кофеен в центре Москвы"
                     data-revenue="25000000"
                     data-employees="15"
                     data-years="5"
                     data-profit="7500000"
                     data-growth="15"
                     data-description="Две кофейни в проходимых местах центра Москвы. Налаженные поставки, обученный персонал, постоянная клиентская база."
                     data-full-description="Успешная сеть из двух кофеен, расположенных в центре Москвы в местах с высокой проходимостью. Обе точки оснащены современным оборудованием, налажены прямые поставки от обжарщиков. Обученная команда из 15 сотрудников работает по стандартизированным процессам. Постоянная клиентская база и лояльная аудитория. Высокий средний чек и стабильная прибыльность."
                     data-advantages="Две точки в центре Москвы|Налаженные поставки|Обученный персонал|Высокая проходимость|Лояльная клиентская база|Готовая инфраструктура"
                     data-risks="Конкуренция в сегменте|Зависимость от локации"
                     data-contact="+7 (495) 234-56-78">
                    <div class="card-header">
                        <div class="card-icon-bg">
                            <div class="card-icon">🍽️</div>
                        </div>
                        <div class="card-badge badge-popular">Популярное</div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Сеть кофеен в центре Москвы</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">25 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">15</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">5 лет</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Две кофейни в проходимых местах центра Москвы. Налаженные поставки, обученный персонал, постоянная клиентская база. Готовая к работе инфраструктура.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">8 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 3 -->
                <div class="business-card card-ecommerce"
                     data-industry="ecommerce"
                     data-price="12000000"
                     data-location="spb"
                     data-id="3"
                     data-title="Интернет-магазин детских товаров"
                     data-revenue="18000000"
                     data-employees="5"
                     data-years="4"
                     data-profit="5400000"
                     data-growth="20"
                     data-description="Успешный онлайн-магазин с собственным складом и логистикой. Широкий ассортимент товаров для детей."
                     data-full-description="Успешный интернет-магазин детских товаров с собственной складской логистикой. Широкий ассортимент от 0 до 12 лет. Собственный склад площадью 500 кв.м, отлаженная система доставки по всей России. Активная маркетинговая стратегия в социальных сетях и контекстной рекламе. Высокий уровень клиентского сервиса и положительные отзывы. Стабильный рост продаж."
                     data-advantages="Собственный склад|Отлаженная логистика|Широкий ассортимент|Активный маркетинг|Высокий сервис|Рост продаж 20%"
                     data-risks="Сезонность спроса|Зависимость от поставщиков"
                     data-contact="+7 (812) 345-67-89">
                    <div class="card-header">
                        <div class="card-icon-bg">
                            <div class="card-icon">🛒</div>
                        </div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Интернет-магазин детских товаров</h3>
                        <p class="card-location">📍 Санкт-Петербург</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">18 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">5</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">4 года</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Успешный онлайн-магазин с собственным складом и логистикой. Широкий ассортимент товаров для детей. Активная маркетинговая стратегия и высокий уровень клиентского сервиса.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">12 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 4 -->
                <div class="business-card card-services"
                     data-industry="services"
                     data-price="3000000"
                     data-location="moscow"
                     data-id="4"
                     data-title="Агентство недвижимости"
                     data-revenue="8000000"
                     data-employees="12"
                     data-years="7"
                     data-profit="2400000"
                     data-growth="10"
                     data-description="Стабильное агентство недвижимости с сильной репутацией. Офис в центре Москвы, команда опытных риелторов."
                     data-full-description="Стабильное агентство недвижимости с сильной репутацией на рынке. Офис в центре Москвы площадью 120 кв.м. Команда из 12 опытных риелторов с сертификатами. Обширная база объектов недвижимости и клиентов. Лицензия на осуществление риелторской деятельности. Все необходимые документы в порядке. Стабильный поток клиентов и сделок."
                     data-advantages="Центральный офис|Опытная команда|Обширная база|Лицензия|Сильная репутация|Стабильный поток клиентов"
                     data-risks="Зависимость от рынка недвижимости|Конкуренция"
                     data-contact="+7 (495) 456-78-90">
                    <div class="card-header">
                        <div class="card-icon-bg">
                            <div class="card-icon">💼</div>
                        </div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Агентство недвижимости</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">8 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">12</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">7 лет</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Стабильное агентство недвижимости с сильной репутацией. Офис в центре Москвы, команда опытных риелторов, база объектов и клиентов. Лицензия и все необходимые документы.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">3 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 5 -->
                <div class="business-card card-retail"
                     data-industry="retail"
                     data-price="6000000"
                     data-location="ekb"
                     data-id="5"
                     data-title="Сеть магазинов одежды"
                     data-revenue="20000000"
                     data-employees="10"
                     data-years="6"
                     data-profit="6000000"
                     data-growth="12"
                     data-description="Три магазина одежды в торговых центрах Екатеринбурга. Налаженные поставки от производителей, узнаваемый бренд."
                     data-full-description="Сеть из трех магазинов одежды, расположенных в крупных торговых центрах Екатеринбурга. Налаженные прямые поставки от производителей без посредников. Узнаваемый бренд и лояльная клиентская база. Стильный мерчендайзинг и современный дизайн магазинов. Стабильный доход и потенциал для расширения сети в другие города."
                     data-advantages="Три точки продаж|Прямые поставки|Узнаваемый бренд|Торговые центры|Лояльная база|Потенциал расширения"
                     data-risks="Конкуренция в ритейле|Зависимость от арендодателей"
                     data-contact="+7 (343) 567-89-01">
                    <div class="card-header">
                        <div class="card-icon-bg">
                            <div class="card-icon">🏪</div>
                        </div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Сеть магазинов одежды</h3>
                        <p class="card-location">📍 Екатеринбург</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">20 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">10</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">6 лет</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Три магазина одежды в торговых центрах Екатеринбурга. Налаженные поставки от производителей, узнаваемый бренд, лояльная клиентская база. Стабильный доход и потенциал для расширения.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">6 000 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>

                <!-- Business Card 6 -->
                <div class="business-card card-beauty"
                     data-industry="services"
                     data-price="4500000"
                     data-location="moscow"
                     data-id="6"
                     data-title="Салон красоты премиум-класса"
                     data-revenue="15000000"
                     data-employees="8"
                     data-years="4"
                     data-profit="5250000"
                     data-growth="18"
                     data-description="Премиальный салон красоты в центре Москвы. Современное оборудование, профессиональная команда стилистов."
                     data-full-description="Премиальный салон красоты в центре Москвы площадью 200 кв.м. Современное профессиональное оборудование ведущих мировых брендов. Команда из 8 профессиональных стилистов, визажистов и мастеров маникюра. Постоянная клиентская база из 500+ постоянных клиентов. Высокий средний чек и отличная репутация. Система предварительной записи и лояльности."
                     data-advantages="Центральная локация|Премиум оборудование|Профессиональная команда|Постоянная база 500+|Высокий средний чек|Отличная репутация"
                     data-risks="Зависимость от мастеров|Конкуренция в сегменте"
                     data-contact="+7 (495) 678-90-12">
                    <div class="card-header">
                        <div class="card-icon-bg">
                            <div class="card-icon">✂️</div>
                        </div>
                        <div class="card-badge badge-recommended">Рекомендуем</div>
                    </div>

                    <div class="card-content">
                        <h3 class="card-title">Салон красоты премиум-класса</h3>
                        <p class="card-location">📍 Москва</p>

                        <div class="card-metrics">
                            <div class="metric">
                                <div class="metric-value">15 млн ₽</div>
                                <div class="metric-label">Выручка</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">8</div>
                                <div class="metric-label">Сотрудников</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">4 года</div>
                                <div class="metric-label">На рынке</div>
                            </div>
                        </div>

                        <p class="card-description">
                            Премиальный салон красоты в центре Москвы. Современное оборудование, профессиональная команда стилистов, постоянная клиентская база. Высокий средний чек и отличная репутация.
                        </p>
                    </div>

                    <div class="card-footer">
                        <div class="card-price">
                            <span class="price-amount">4 500 000 ₽</span>
                            <span class="price-label">Цена продажи</span>
                        </div>
                        <button class="card-button">Подробнее</button>
                    </div>

                    <div class="card-glow"></div>
                </div>
            </div>

            <div class="no-results" id="no-results" style="display: none;">
                <p>По вашему запросу ничего не найдено. Попробуйте изменить фильтры.</p>
            </div>
        </div>
    </section>

    <!-- Business Detail Modal -->
    <div class="modal-overlay" id="business-modal">
        <div class="modal-container">
            <button class="modal-close" aria-label="Close modal">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <div class="modal-content">
                <div class="modal-header">
                    <div class="modal-icon-wrapper">
                        <div class="modal-icon" id="modal-icon">💼</div>
                    </div>
                    <div class="modal-title-section">
                        <h2 class="modal-title" id="modal-title">Название бизнеса</h2>
                        <p class="modal-location" id="modal-location">📍 Город</p>
                    </div>
                    <div class="modal-badge" id="modal-badge"></div>
                </div>

                <div class="modal-body">
                    <div class="modal-section">
                        <h3 class="section-title-modal">📊 Финансовые показатели</h3>
                        <div class="financial-grid">
                            <div class="financial-item">
                                <span class="financial-label">Годовая выручка</span>
                                <span class="financial-value" id="modal-revenue">0 ₽</span>
                            </div>
                            <div class="financial-item">
                                <span class="financial-label">Прибыль в год</span>
                                <span class="financial-value" id="modal-profit">0 ₽</span>
                            </div>
                            <div class="financial-item">
                                <span class="financial-label">Рост выручки</span>
                                <span class="financial-value growth" id="modal-growth">0%</span>
                            </div>
                            <div class="financial-item">
                                <span class="financial-label">Цена продажи</span>
                                <span class="financial-value price" id="modal-price">0 ₽</span>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="section-title-modal">📋 Общая информация</h3>
                        <div class="info-grid">
                            <div class="info-item">
                                <span class="info-icon">👥</span>
                                <div class="info-content">
                                    <span class="info-label">Количество сотрудников</span>
                                    <span class="info-value" id="modal-employees">0</span>
                                </div>
                            </div>
                            <div class="info-item">
                                <span class="info-icon">📅</span>
                                <div class="info-content">
                                    <span class="info-label">На рынке</span>
                                    <span class="info-value" id="modal-years">0 лет</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="section-title-modal">📖 Описание</h3>
                        <p class="modal-description" id="modal-description"></p>
                    </div>

                    <div class="modal-section">
                        <h3 class="section-title-modal">✅ Преимущества</h3>
                        <ul class="advantages-list" id="modal-advantages"></ul>
                    </div>

                    <div class="modal-section">
                        <h3 class="section-title-modal">⚠️ Риски</h3>
                        <ul class="risks-list" id="modal-risks"></ul>
                    </div>

                    <div class="modal-section">
                        <h3 class="section-title-modal">📞 Контакты</h3>
                        <div class="contact-info">
                            <a href="tel:" class="contact-link" id="modal-contact">
                                <span class="contact-icon">📱</span>
                                <span id="modal-contact-text">+7 (495) 123-45-67</span>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button class="btn btn-secondary" id="modal-close-btn">Закрыть</button>
                    <button class="btn btn-primary" id="modal-contact-btn">
                        <span>Связаться с продавцом</span>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>


    <!-- Seller Form Section -->
    <section class="seller-form-cta">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Продажа бизнеса через SmartBizSell</h2>
                <p class="section-subtitle">Анкета доступна только в личном кабинете. После заполнения вы получите автоматический DCF-анализ и сможете вернуться к данным в любой момент.</p>
            </div>
            <div style="text-align:center; margin-top: 32px;">
                <a class="btn btn-primary" href="<?php echo isLoggedIn() ? 'dashboard.php' : 'login.php'; ?>">Перейти в личный кабинет</a>
            </div>
        </div>
    </section>
    <!-- Contact Section -->
    <section id="contact" class="contact">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Свяжитесь с нами</h2>
                <p class="section-subtitle">Обсудим цели сделки, расскажем о подходе команды и покажем платформу в работе</p>
            </div>
            <div class="contact-grid">
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="4" y="6" width="24" height="20" rx="3" stroke="#6366F1" stroke-width="2" fill="none"/>
                            <path d="M4 10L16 18L28 10" stroke="#6366F1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h3>Email</h3>
                    <p>info@smartbizsell.ru</p>
                </div>
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect x="6" y="4" width="20" height="24" rx="4" stroke="#0EA5E9" stroke-width="2" fill="none"/>
                            <path d="M12 8H20M12 12H20M12 16H18" stroke="#0EA5E9" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
                    <h3>Телефон</h3>
                    <p>+7 (495) 123-45-67</p>
                </div>
                <div class="contact-card">
                    <div class="contact-icon">
                        <svg width="48" height="48" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="16" cy="12" r="6" stroke="#10B981" stroke-width="2" fill="none"/>
                            <path d="M16 18C10 18 4 20 4 24V28H28V24C28 20 22 18 16 18Z" stroke="#10B981" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h3>Адрес</h3>
                    <p>Москва, Россия</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <a href="#" class="footer-logo">
                    <span class="logo-icon">🚀</span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <p class="footer-text">
                    Экспертная M&amp;A команда, которая внедрила свой опыт в цифровую платформу и ИИ, чтобы проводить сделки быстрее, прозрачнее и выгоднее.
                </p>
                <div class="footer-links">
                    <a href="#features">Возможности</a>
                    <a href="#how-it-works">Как это работает</a>
                    <a href="#buy-business">Купить бизнес</a>
                    <a href="#seller-form">Продать бизнес</a>
                    <a href="#contact">Контакты</a>
                </div>
                <div class="footer-copyright">
                    <p>&copy; 2025 SmartBizSell.ru. Все права защищены.</p>
                </div>
            </div>
        </div>
    </footer>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

