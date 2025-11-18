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
                    <a href="#seller-form" class="btn btn-primary">
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
                    <div class="stat-item">
                        <div class="stat-number">500+</div>
                        <div class="stat-label">Проверенных инвесторов</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number">150+</div>
                        <div class="stat-label">Закрытых M&amp;A-сделок</div>
                    </div>
                    <div class="stat-item">
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
                    <div class="feature-icon">🤖</div>
                    <h3 class="feature-title">ИИ-Генерация тизеров</h3>
                    <p class="feature-description">
                        Используем проверенные нами подходы к тизерам и подключаем ИИ для точной аналитики, чтобы каждый инвестор сразу видел ценность бизнеса.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">📊</div>
                    <h3 class="feature-title">Автоматические финансовые модели</h3>
                    <p class="feature-description">
                        Формируем финансовые модели по стандартам сделок M&amp;A и ускоряем расчёты с помощью нейросетей — быстро, прозрачно и с учётом ключевых метрик.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">⚡</div>
                    <h3 class="feature-title">Ускорение процессов</h3>
                    <p class="feature-description">
                        Цифровые пайплайны заменяют ручные задачи: готовим материалы, структурируем данные и запускаем показы в разы быстрее традиционных процессов.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🎯</div>
                    <h3 class="feature-title">Умный подбор покупателей</h3>
                    <p class="feature-description">
                        Соединяем данные о прошлых сделках, нашу экспертную оценку и алгоритмы рекомендаций, чтобы вывести к вам релевантных инвесторов без лишнего шума.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">📱</div>
                    <h3 class="feature-title">Современный интерфейс</h3>
                    <p class="feature-description">
                        Управляйте ходом сделки в едином цифровом кабинете: согласовывайте материалы, отслеживайте статус и общайтесь с командой в режиме реального времени.
                    </p>
                </div>
                <div class="feature-card">
                    <div class="feature-icon">🔒</div>
                    <h3 class="feature-title">Безопасность данных</h3>
                    <p class="feature-description">
                        Следуем лучшим практикам комплаенса и используем корпоративный уровень защиты, чтобы вся информация о сделке оставалась конфиденциальной.
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
                            Создаём тизер и финансовую модель по стандартам инвестбанкинга: ИИ ускоряет расчёты, а мы обеспечиваем точность, аргументацию и прозрачность цифр.
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
    <section id="seller-form" class="seller-form-section">
        <div class="container">
            <div class="section-header">
                <h2 class="section-title">Анкета для продавца</h2>
                <p class="section-subtitle">Расскажите о компании — и команда SmartBizSell подготовит материалы сделки и стратегию выхода на рынок</p>
            </div>
            <div class="form-wrapper">
                <?php
                /**
                 * Обработка формы анкеты продавца
                 * 
                 * Форма содержит три основных раздела:
                 * I. Детали предполагаемой сделки
                 * II. Описание бизнеса компании
                 * III. Основные операционные и финансовые показатели
                 * 
                 * После успешной валидации данные сохраняются в БД (если пользователь авторизован)
                 */
                
                // Инициализация переменных
                $errors = [];
                $success = false;
                $yesNo = ['yes' => 'да', 'no' => 'нет'];

                $productionColumns = [
                    'product' => $_POST['production_product'] ?? [''],
                    'unit' => $_POST['production_unit'] ?? [''],
                    'fact_2022' => $_POST['production_fact_2022'] ?? [''],
                    'fact_2023' => $_POST['production_fact_2023'] ?? [''],
                    'fact_2024' => $_POST['production_fact_2024'] ?? [''],
                    'fact_2025_9m' => $_POST['production_fact_2025_9m'] ?? [''],
                    'budget_2025' => $_POST['production_budget_2025'] ?? [''],
                    'budget_2026' => $_POST['production_budget_2026'] ?? [''],
                ];

                $productionRowCount = max(array_map('count', $productionColumns));
                if ($productionRowCount < 1) {
                    $productionRowCount = 1;
                }
                foreach ($productionColumns as $key => $values) {
                    if (count($values) < $productionRowCount) {
                        $productionColumns[$key] = array_pad($values, $productionRowCount, '');
                    }
                }

                // Инициализация переменных для финансовых результатов
                $defaultFinancialResultsRows = [
                    ['Выручка', '___'],
                    ['Себестоимость продаж', '___'],
                    ['Коммерческие расходы', '___'],
                    ['Управленческие расходы', '___'],
                    ['Прибыль от продаж', '___'],
                    ['Амортизация', '___'],
                    ['Приобретение основных средств', '___'],
                ];

                $financialResultsMetrics = $_POST['financial_results_metric'] ?? [];
                if (count($financialResultsMetrics) === 0) {
                    $financialResultsMetrics = array_column($defaultFinancialResultsRows, 0);
                    $financialResultsUnits = array_column($defaultFinancialResultsRows, 1);
                    $financialResultsFact2022 = array_fill(0, count($financialResultsMetrics), '');
                    $financialResultsFact2023 = array_fill(0, count($financialResultsMetrics), '');
                    $financialResultsFact2024 = array_fill(0, count($financialResultsMetrics), '');
                    $financialResultsFact2025_9m = array_fill(0, count($financialResultsMetrics), '');
                    $financialResultsBudget2025 = array_fill(0, count($financialResultsMetrics), '');
                    $financialResultsBudget2026 = array_fill(0, count($financialResultsMetrics), '');
                } else {
                    $financialResultsUnits = $_POST['financial_results_unit'] ?? [];
                    $financialResultsFact2022 = $_POST['financial_results_fact_2022'] ?? [];
                    $financialResultsFact2023 = $_POST['financial_results_fact_2023'] ?? [];
                    $financialResultsFact2024 = $_POST['financial_results_fact_2024'] ?? [];
                    $financialResultsFact2025_9m = $_POST['financial_results_fact_2025_9m'] ?? [];
                    $financialResultsBudget2025 = $_POST['financial_results_budget_2025'] ?? [];
                    $financialResultsBudget2026 = $_POST['financial_results_budget_2026'] ?? [];
                }

                $financialResultsRowCount = count($financialResultsMetrics);
                $financialResultsUnits = array_pad($financialResultsUnits, $financialResultsRowCount, '');
                $financialResultsFact2022 = array_pad($financialResultsFact2022, $financialResultsRowCount, '');
                $financialResultsFact2023 = array_pad($financialResultsFact2023, $financialResultsRowCount, '');
                $financialResultsFact2024 = array_pad($financialResultsFact2024, $financialResultsRowCount, '');
                $financialResultsFact2025_9m = array_pad($financialResultsFact2025_9m, $financialResultsRowCount, '');
                $financialResultsBudget2025 = array_pad($financialResultsBudget2025, $financialResultsRowCount, '');
                $financialResultsBudget2026 = array_pad($financialResultsBudget2026, $financialResultsRowCount, '');

                // Инициализация переменных для балансовых показателей
                $defaultBalanceRows = [
                    ['Основные средства', '___'],
                    ['Запасы', '___'],
                    ['Дебиторская задолженность', '___'],
                    ['Кредиторская задолженность', '___'],
                    ['Кредиты и займы', '___'],
                    ['Денежные средства', '___'],
                    ['Чистые активы', '___'],
                ];

                $balanceMetrics = $_POST['balance_metric'] ?? [];
                if (count($balanceMetrics) === 0) {
                    $balanceMetrics = array_column($defaultBalanceRows, 0);
                    $balanceUnits = array_column($defaultBalanceRows, 1);
                    $balanceFact2022 = array_fill(0, count($balanceMetrics), '');
                    $balanceFact2023 = array_fill(0, count($balanceMetrics), '');
                    $balanceFact2024 = array_fill(0, count($balanceMetrics), '');
                    $balanceFact2025 = array_fill(0, count($balanceMetrics), '');
                } else {
                    $balanceUnits = $_POST['balance_unit'] ?? [];
                    $balanceFact2022 = $_POST['balance_fact_2022'] ?? [];
                    $balanceFact2023 = $_POST['balance_fact_2023'] ?? [];
                    $balanceFact2024 = $_POST['balance_fact_2024'] ?? [];
                    $balanceFact2025 = $_POST['balance_fact_2025'] ?? [];
                }

                $balanceRowCount = count($balanceMetrics);
                $balanceUnits = array_pad($balanceUnits, $balanceRowCount, '');
                $balanceFact2022 = array_pad($balanceFact2022, $balanceRowCount, '');
                $balanceFact2023 = array_pad($balanceFact2023, $balanceRowCount, '');
                $balanceFact2024 = array_pad($balanceFact2024, $balanceRowCount, '');
                $balanceFact2025 = array_pad($balanceFact2025, $balanceRowCount, '');

                /**
                 * Получение и санитизация данных формы
                 * Все данные проходят через функцию sanitizeInput для безопасности
                 */
                $asset_name = sanitizeInput($_POST['asset_name'] ?? '');
                $deal_share_range = sanitizeInput($_POST['deal_share_range'] ?? '');
                $deal_goal = $_POST['deal_goal'] ?? '';
                $asset_disclosure = $_POST['asset_disclosure'] ?? '';
                $company_description = sanitizeInput($_POST['company_description'] ?? '');
                $presence_regions = sanitizeInput($_POST['presence_regions'] ?? '');
                $products_services = sanitizeInput($_POST['products_services'] ?? '');
                $company_brands = sanitizeInput($_POST['company_brands'] ?? '');
                $own_production = $_POST['own_production'] ?? '';
                $production_sites_count = $_POST['production_sites_count'] ?? '';
                $production_sites_region = sanitizeInput($_POST['production_sites_region'] ?? '');
                $production_area = sanitizeInput($_POST['production_area'] ?? '');
                $production_capacity = sanitizeInput($_POST['production_capacity'] ?? '');
                $production_load = sanitizeInput($_POST['production_load'] ?? '');
                $production_building_ownership = $_POST['production_building_ownership'] ?? '';
                $production_land_ownership = $_POST['production_land_ownership'] ?? '';
                $contract_production_usage = $_POST['contract_production_usage'] ?? '';
                $contract_production_region = sanitizeInput($_POST['contract_production_region'] ?? '');
                $contract_production_logistics = sanitizeInput($_POST['contract_production_logistics'] ?? '');
                $offline_sales_presence = $_POST['offline_sales_presence'] ?? '';
                $offline_sales_points = $_POST['offline_sales_points'] ?? '';
                $offline_sales_regions = sanitizeInput($_POST['offline_sales_regions'] ?? '');
                $offline_sales_area = sanitizeInput($_POST['offline_sales_area'] ?? '');
                $offline_sales_third_party = $_POST['offline_sales_third_party'] ?? '';
                $offline_sales_distributors = $_POST['offline_sales_distributors'] ?? '';
                $online_sales_presence = $_POST['online_sales_presence'] ?? '';
                $online_sales_share = sanitizeInput($_POST['online_sales_share'] ?? '');
                $online_sales_channels = sanitizeInput($_POST['online_sales_channels'] ?? '');
                $main_clients = sanitizeInput($_POST['main_clients'] ?? '');
                $sales_share = sanitizeInput($_POST['sales_share'] ?? '');
                $personnel_count = $_POST['personnel_count'] ?? '';
                $company_website = sanitizeInput($_POST['company_website'] ?? '');
                $additional_info = sanitizeInput($_POST['additional_info'] ?? '');
                $financial_results_vat = $_POST['financial_results_vat'] ?? '';
                $financial_source = $_POST['financial_source'] ?? '';
                $agree = isset($_POST['agree']);

            /**
             * Валидация данных формы
             * Проверяются все обязательные поля и корректность введенных данных
             */
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                // Валидация обязательных полей
                if ($asset_name === '') $errors['asset_name'] = 'Укажите название актива';
                if ($deal_share_range === '') $errors['deal_share_range'] = 'Укажите предмет сделки';
                if (!in_array($deal_goal, ['cash_out', 'cash_in'], true)) $errors['deal_goal'] = 'Выберите цель сделки';
                if (!in_array($asset_disclosure, ['yes', 'no'], true)) $errors['asset_disclosure'] = 'Укажите раскрытие названия актива';
                if ($company_description === '' || mb_strlen($company_description) < 20) $errors['company_description'] = 'Опишите деятельность компании (не менее 20 символов)';
                if ($presence_regions === '') $errors['presence_regions'] = 'Укажите регионы присутствия';
                if ($products_services === '') $errors['products_services'] = 'Опишите продукцию или услуги компании';
                if (!in_array($own_production, array_keys($yesNo), true)) $errors['own_production'] = 'Укажите наличие собственного производства';
                if ($personnel_count === '' || !is_numeric($personnel_count) || (float)$personnel_count < 0) $errors['personnel_count'] = 'Введите численность персонала';
                if ($company_website !== '' && !filter_var($company_website, FILTER_VALIDATE_URL)) $errors['company_website'] = 'Введите корректный адрес сайта (https://...)';
                if ($production_sites_count !== '' && (!is_numeric($production_sites_count) || (float)$production_sites_count < 0)) $errors['production_sites_count'] = 'Введите корректное количество производственных площадок';
                if ($offline_sales_points !== '' && (!is_numeric($offline_sales_points) || (float)$offline_sales_points < 0)) $errors['offline_sales_points'] = 'Введите корректное количество розничных точек';
                if (!in_array($financial_results_vat, ['with_vat', 'without_vat'], true)) $errors['financial_results_vat'] = 'Выберите вариант НДС для финансовых результатов';
                if ($financial_source === '' || !in_array($financial_source, ['rsbu', 'ifrs', 'management'], true)) {
                    $errors['financial_source'] = 'Выберите источник финансовых показателей';
                }
                if (!$agree) $errors['agree'] = 'Необходимо согласие на обработку данных';

                /**
                 * Сохранение данных в базу данных
                 * Выполняется только если пользователь авторизован
                 * Данные сохраняются в таблицу seller_forms
                 */
                if (empty($errors)) {
                    // Сохранение в базу данных (если пользователь авторизован)
                    if (isLoggedIn()) {
                        try {
                            $pdo = getDBConnection();
                            
                            /**
                             * Подготовка данных для JSON полей
                             * Объемы производства и финансовые показатели сохраняются в формате JSON
                             */
                            $productionVolumes = [];
                            for ($i = 0; $i < $productionRowCount; $i++) {
                                if (!empty($productionColumns['product'][$i])) {
                                    $productionVolumes[] = [
                                        'product' => $productionColumns['product'][$i],
                                        'unit' => $productionColumns['unit'][$i] ?? '',
                                        'fact_2022' => $productionColumns['fact_2022'][$i] ?? '',
                                        'fact_2023' => $productionColumns['fact_2023'][$i] ?? '',
                                        'fact_2024' => $productionColumns['fact_2024'][$i] ?? '',
                                        'fact_2025_9m' => $productionColumns['fact_2025_9m'][$i] ?? '',
                                        'budget_2025' => $productionColumns['budget_2025'][$i] ?? '',
                                        'budget_2026' => $productionColumns['budget_2026'][$i] ?? '',
                                    ];
                                }
                            }
                            
                            // Финансовые результаты
                            $financialResults = [];
                            for ($i = 0; $i < $financialResultsRowCount; $i++) {
                                if (!empty($financialResultsMetrics[$i])) {
                                    $financialResults[] = [
                                        'metric' => $financialResultsMetrics[$i],
                                        'unit' => $financialResultsUnits[$i] ?? '',
                                        'fact_2022' => $financialResultsFact2022[$i] ?? '',
                                        'fact_2023' => $financialResultsFact2023[$i] ?? '',
                                        'fact_2024' => $financialResultsFact2024[$i] ?? '',
                                        'fact_2025_9m' => $financialResultsFact2025_9m[$i] ?? '',
                                        'budget_2025' => $financialResultsBudget2025[$i] ?? '',
                                        'budget_2026' => $financialResultsBudget2026[$i] ?? '',
                                    ];
                                }
                            }
                            
                            // Балансовые показатели
                            $balanceIndicators = [];
                            for ($i = 0; $i < $balanceRowCount; $i++) {
                                if (!empty($balanceMetrics[$i])) {
                                    $balanceIndicators[] = [
                                        'metric' => $balanceMetrics[$i],
                                        'unit' => $balanceUnits[$i] ?? '',
                                        'fact_2022' => $balanceFact2022[$i] ?? '',
                                        'fact_2023' => $balanceFact2023[$i] ?? '',
                                        'fact_2024' => $balanceFact2024[$i] ?? '',
                                        'fact_2025' => $balanceFact2025[$i] ?? '',
                                    ];
                                }
                            }
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO seller_forms (
                                    user_id, asset_name, deal_subject, deal_purpose, asset_disclosure,
                                    company_description, presence_regions, products_services, company_brands,
                                    own_production, production_sites_count, production_sites_region,
                                    production_area, production_capacity, production_load,
                                    production_building_ownership, production_land_ownership,
                                    contract_production_usage, contract_production_region, contract_production_logistics,
                                    offline_sales_presence, offline_sales_points, offline_sales_regions, offline_sales_area,
                                    offline_sales_third_party, offline_sales_distributors,
                                    online_sales_presence, online_sales_share, online_sales_channels,
                                    main_clients, sales_share, personnel_count, company_website, additional_info,
                                    production_volumes, financial_results_vat, financial_results, balance_indicators,
                                    financial_source, status, submitted_at
                                ) VALUES (
                                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
                                )
                            ");
                            
                            $stmt->execute([
                                $_SESSION['user_id'],
                                $asset_name,
                                $deal_share_range,
                                $deal_goal === 'cash_out' ? 'cash-out' : ($deal_goal === 'cash_in' ? 'cash-in' : null),
                                $asset_disclosure ?: null,
                                $company_description,
                                $presence_regions,
                                $products_services,
                                $company_brands,
                                $own_production ?: null,
                                $production_sites_count ?: null,
                                $production_sites_region,
                                $production_area,
                                $production_capacity,
                                $production_load,
                                $production_building_ownership ?: null,
                                $production_land_ownership ?: null,
                                $contract_production_usage ?: null,
                                $contract_production_region,
                                $contract_production_logistics,
                                $offline_sales_presence ?: null,
                                $offline_sales_points ?: null,
                                $offline_sales_regions,
                                $offline_sales_area,
                                $offline_sales_third_party ?: null,
                                $offline_sales_distributors ?: null,
                                $online_sales_presence ?: null,
                                $online_sales_share,
                                $online_sales_channels,
                                $main_clients,
                                $sales_share,
                                $personnel_count ?: null,
                                $company_website,
                                $additional_info,
                                json_encode($productionVolumes, JSON_UNESCAPED_UNICODE),
                                $financial_results_vat ?: null,
                                json_encode($financialResults, JSON_UNESCAPED_UNICODE),
                                json_encode($balanceIndicators, JSON_UNESCAPED_UNICODE),
                                $financial_source ?: null,
                                'submitted'
                            ]);
                            
                            $success = true;
                            // Редирект в личный кабинет после успешной отправки
                            header('Location: dashboard.php?success=1');
                            exit;
                        } catch (PDOException $e) {
                            error_log("Error saving form: " . $e->getMessage());
                            $errors['general'] = 'Ошибка сохранения анкеты. Попробуйте позже.';
                        }
                    } else {
                        // Если не авторизован, просто показываем успех (можно добавить редирект на регистрацию)
                        $success = true;
                    }
                }
            }
            ?>
            <?php if ($success && !isLoggedIn()): ?>
                <div class="success-message">
                    <div class="success-icon">✓</div>
                    <h3>Спасибо за заявку!</h3>
                    <p>Мы получили вашу анкету. Для сохранения и отслеживания статуса анкеты рекомендуем <a href="register.php" style="color: var(--primary-color); font-weight: 600;">зарегистрироваться</a> или <a href="login.php" style="color: var(--primary-color); font-weight: 600;">войти</a> в личный кабинет.</p>
                </div>
            <?php elseif (!$success): ?>
                <form class="seller-form" method="POST" action="#seller-form">
                    <div class="form-section">
                        <h3 class="form-section-title">I. Детали предполагаемой сделки</h3>

                        <div class="form-group">
                            <label for="asset_name">Название актива (название ЮЛ, группы компаний или бренда), ИНН *</label>
                            <input type="text" id="asset_name" name="asset_name" required
                                   value="<?php echo htmlspecialchars($_POST['asset_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if (isset($errors['asset_name'])): ?>
                                <span class="error-message"><?php echo $errors['asset_name']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="deal_share_range">Предмет сделки: продажа доли от ___до ____ *</label>
                            <input type="text" id="deal_share_range" name="deal_share_range" required
                                   placeholder="от ___ до ____"
                                   value="<?php echo htmlspecialchars($_POST['deal_share_range'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if (isset($errors['deal_share_range'])): ?>
                                <span class="error-message"><?php echo $errors['deal_share_range']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <span class="form-group-label">Цель сделки *</span>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="deal_goal" value="cash_out" <?php echo (($_POST['deal_goal'] ?? '') === 'cash_out') ? 'checked' : ''; ?> required>
                                    <span>a. Продажа бизнеса (cash-out)</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="deal_goal" value="cash_in" <?php echo (($_POST['deal_goal'] ?? '') === 'cash_in') ? 'checked' : ''; ?>>
                                    <span>b. Привлечение инвестиций (cash-in)</span>
                                </label>
                            </div>
                            <?php if (isset($errors['deal_goal'])): ?>
                                <span class="error-message"><?php echo $errors['deal_goal']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <span class="form-group-label">Раскрытие названия актива в анкете: да/нет *</span>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="asset_disclosure" value="yes" <?php echo (($_POST['asset_disclosure'] ?? '') === 'yes') ? 'checked' : ''; ?> required>
                                    <span>да</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="asset_disclosure" value="no" <?php echo (($_POST['asset_disclosure'] ?? '') === 'no') ? 'checked' : ''; ?>>
                                    <span>нет</span>
                                </label>
                            </div>
                            <?php if (isset($errors['asset_disclosure'])): ?>
                                <span class="error-message"><?php echo $errors['asset_disclosure']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">II. Описание бизнеса компании</h3>

                        <div class="form-group">
                            <label for="company_description">Краткое описание деятельности компании *</label>
                            <textarea id="company_description" name="company_description" required rows="4"><?php echo htmlspecialchars($_POST['company_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <?php if (isset($errors['company_description'])): ?>
                                <span class="error-message"><?php echo $errors['company_description']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="presence_regions">Регионы присутствия *</label>
                            <input type="text" id="presence_regions" name="presence_regions" required
                                   value="<?php echo htmlspecialchars($_POST['presence_regions'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            <?php if (isset($errors['presence_regions'])): ?>
                                <span class="error-message"><?php echo $errors['presence_regions']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="products_services">Продукция/услуги компании *</label>
                            <textarea id="products_services" name="products_services" required rows="3"><?php echo htmlspecialchars($_POST['products_services'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <?php if (isset($errors['products_services'])): ?>
                                <span class="error-message"><?php echo $errors['products_services']; ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label for="company_brands">Бренды компании</label>
                            <input type="text" id="company_brands" name="company_brands"
                                   value="<?php echo htmlspecialchars($_POST['company_brands'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-subsection">
                            <h4 class="form-subsection-title">Собственные производственные мощности</h4>
                            <div class="form-group">
                                <span class="form-group-label">a. Наличие собственного производства *</span>
                                <div class="radio-group">
                                    <?php foreach ($yesNo as $value => $label): ?>
                                        <label class="radio-option">
                                            <input type="radio" name="own_production" value="<?php echo $value; ?>"
                                                <?php echo (($_POST['own_production'] ?? 'yes') === $value) ? 'checked' : ''; ?> required>
                                            <span><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (isset($errors['own_production'])): ?>
                                    <span class="error-message"><?php echo $errors['own_production']; ?></span>
                                <?php endif; ?>
                            </div>

                            <div class="production-details toggle-section" data-production-details data-toggle-source="own_production">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="production_sites_count">b. Количество производственных площадок</label>
                                        <input type="number" id="production_sites_count" name="production_sites_count" min="0"
                                               value="<?php echo htmlspecialchars($_POST['production_sites_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php if (isset($errors['production_sites_count'])): ?>
                                            <span class="error-message"><?php echo $errors['production_sites_count']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group">
                                        <label for="production_sites_region">c. Регион расположения производственных площадок</label>
                                        <input type="text" id="production_sites_region" name="production_sites_region"
                                               value="<?php echo htmlspecialchars($_POST['production_sites_region'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="production_area">d. Площадь производственной площадки</label>
                                        <input type="text" id="production_area" name="production_area"
                                               value="<?php echo htmlspecialchars($_POST['production_area'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="production_capacity">e. Производственная мощность</label>
                                        <input type="text" id="production_capacity" name="production_capacity" placeholder="мощность; единицы"
                                               value="<?php echo htmlspecialchars($_POST['production_capacity'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="production_load">f. Текущая загрузка мощностей</label>
                                        <input type="text" id="production_load" name="production_load"
                                               value="<?php echo htmlspecialchars($_POST['production_load'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <span class="form-group-label">g. Право собственности на здание</span>
                                        <div class="radio-group">
                                            <?php foreach ($yesNo as $value => $label): ?>
                                                <label class="radio-option">
                                                    <input type="radio" name="production_building_ownership" value="<?php echo $value; ?>"
                                                        <?php echo (($_POST['production_building_ownership'] ?? '') === $value) ? 'checked' : ''; ?>>
                                                    <span><?php echo $label; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <span class="form-group-label">h. Право собственности на земельный участок</span>
                                        <div class="radio-group">
                                            <?php foreach ($yesNo as $value => $label): ?>
                                                <label class="radio-option">
                                                    <input type="radio" name="production_land_ownership" value="<?php echo $value; ?>"
                                                        <?php echo (($_POST['production_land_ownership'] ?? '') === $value) ? 'checked' : ''; ?>>
                                                    <span><?php echo $label; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-subsection">
                            <h4 class="form-subsection-title">Контрактное производство</h4>
                            <div class="form-group">
                                <span class="form-group-label">a. Пользуется ли компания услугами контрактного производства</span>
                                <div class="radio-group">
                                    <?php foreach ($yesNo as $value => $label): ?>
                                        <label class="radio-option">
                                            <input type="radio" name="contract_production_usage" value="<?php echo $value; ?>"
                                                <?php echo (($_POST['contract_production_usage'] ?? '') === $value) ? 'checked' : ''; ?>>
                                            <span><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="contract-details toggle-section" data-toggle-source="contract_production_usage">
                                <div class="form-group">
                                    <label for="contract_production_region">b. Регион расположения контрактных производителей</label>
                                    <input type="text" id="contract_production_region" name="contract_production_region"
                                           value="<?php echo htmlspecialchars($_POST['contract_production_region'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="form-group">
                                    <label for="contract_production_logistics">c. Как осуществляется логистика от производства до клиентов</label>
                                    <textarea id="contract_production_logistics" name="contract_production_logistics" rows="3"><?php echo htmlspecialchars($_POST['contract_production_logistics'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div class="form-subsection">
                            <h4 class="form-subsection-title">Офлайн-продажи</h4>
                            <div class="form-group">
                                <span class="form-group-label">a. Наличие собственных магазинов</span>
                                <div class="radio-group">
                                    <?php foreach ($yesNo as $value => $label): ?>
                                        <label class="radio-option">
                                            <input type="radio" name="offline_sales_presence" value="<?php echo $value; ?>"
                                                <?php echo (($_POST['offline_sales_presence'] ?? '') === $value) ? 'checked' : ''; ?>>
                                            <span><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="retail-details toggle-section" data-toggle-source="offline_sales_presence">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="offline_sales_points">b. Количество розничных точек</label>
                                        <input type="number" id="offline_sales_points" name="offline_sales_points" min="0"
                                               value="<?php echo htmlspecialchars($_POST['offline_sales_points'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php if (isset($errors['offline_sales_points'])): ?>
                                            <span class="error-message"><?php echo $errors['offline_sales_points']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-group">
                                        <label for="offline_sales_regions">c. Регионы расположения розничных точек</label>
                                        <input type="text" id="offline_sales_regions" name="offline_sales_regions"
                                               value="<?php echo htmlspecialchars($_POST['offline_sales_regions'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="offline_sales_area">d. Общая площадь розничных точек</label>
                                        <input type="text" id="offline_sales_area" name="offline_sales_area"
                                               value="<?php echo htmlspecialchars($_POST['offline_sales_area'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <span class="form-group-label">e. Реализация через сторонние розничные магазины: да/нет</span>
                                        <div class="radio-group">
                                            <?php foreach ($yesNo as $value => $label): ?>
                                                <label class="radio-option">
                                                    <input type="radio" name="offline_sales_third_party" value="<?php echo $value; ?>"
                                                        <?php echo (($_POST['offline_sales_third_party'] ?? '') === $value) ? 'checked' : ''; ?>>
                                                    <span><?php echo $label; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <span class="form-group-label">f. Реализация через дистрибьюторов: да/нет</span>
                                        <div class="radio-group">
                                            <?php foreach ($yesNo as $value => $label): ?>
                                                <label class="radio-option">
                                                    <input type="radio" name="offline_sales_distributors" value="<?php echo $value; ?>"
                                                        <?php echo (($_POST['offline_sales_distributors'] ?? '') === $value) ? 'checked' : ''; ?>>
                                                    <span><?php echo $label; ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-subsection">
                            <h4 class="form-subsection-title">Онлайн-продажи</h4>
                            <div class="form-group">
                                <span class="form-group-label">a. Наличие онлайн-продаж</span>
                                <div class="radio-group">
                                    <?php foreach ($yesNo as $value => $label): ?>
                                        <label class="radio-option">
                                            <input type="radio" name="online_sales_presence" value="<?php echo $value; ?>"
                                                <?php echo (($_POST['online_sales_presence'] ?? '') === $value) ? 'checked' : ''; ?>>
                                            <span><?php echo $label; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="online-details toggle-section" data-toggle-source="online_sales_presence">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="online_sales_share">b. Доля онлайн-продаж</label>
                                        <input type="text" id="online_sales_share" name="online_sales_share"
                                               value="<?php echo htmlspecialchars($_POST['online_sales_share'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label for="online_sales_channels">c. В каких онлайн-магазинах и маркетплейсах присутствует продукция</label>
                                        <textarea id="online_sales_channels" name="online_sales_channels" rows="3"><?php echo htmlspecialchars($_POST['online_sales_channels'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="main_clients">Основные клиенты</label>
                            <textarea id="main_clients" name="main_clients" rows="3"><?php echo htmlspecialchars($_POST['main_clients'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="sales_share">Доля продаж в РФ/экспорта: __/__0%</label>
                            <input type="text" id="sales_share" name="sales_share"
                                   placeholder="__/__0%"
                                   value="<?php echo htmlspecialchars($_POST['sales_share'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="personnel_count">Численность персонала *</label>
                                <input type="number" id="personnel_count" name="personnel_count" min="0" required
                                       value="<?php echo htmlspecialchars($_POST['personnel_count'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if (isset($errors['personnel_count'])): ?>
                                    <span class="error-message"><?php echo $errors['personnel_count']; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="form-group">
                                <label for="company_website">Сайт компании</label>
                                <input type="text" id="company_website" name="company_website"
                                       placeholder="https://example.com"
                                       value="<?php echo htmlspecialchars($_POST['company_website'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                <?php if (isset($errors['company_website'])): ?>
                                    <span class="error-message"><?php echo $errors['company_website']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="additional_info">Дополнительная информация</label>
                            <textarea id="additional_info" name="additional_info" rows="4"><?php echo htmlspecialchars($_POST['additional_info'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">III. Основные операционные и финансовые показатели</h3>

                        <div class="form-subsection">
                            <h4 class="form-subsection-title">Объемы производства</h4>
                            <div class="table-wrapper">
                                <table class="form-table" id="production-table">
                                    <thead>
                                        <tr>
                                            <th>Вид продукции</th>
                                            <th>Ед. изм.</th>
                                            <th>2022 факт</th>
                                            <th>2023 факт</th>
                                            <th>2024 факт</th>
                                            <th>9М 2025 факт</th>
                                            <th>2025 бюджет</th>
                                            <th>2026 бюджет</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($row = 0; $row < $productionRowCount; $row++): ?>
                                            <tr>
                                                <td><input type="text" name="production_product[]" value="<?php echo htmlspecialchars($productionColumns['product'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="production_unit[]" value="<?php echo htmlspecialchars($productionColumns['unit'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="production_fact_2022[]" value="<?php echo htmlspecialchars($productionColumns['fact_2022'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="production_fact_2023[]" value="<?php echo htmlspecialchars($productionColumns['fact_2023'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="production_fact_2024[]" value="<?php echo htmlspecialchars($productionColumns['fact_2024'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="production_fact_2025_9m[]" value="<?php echo htmlspecialchars($productionColumns['fact_2025_9m'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="production_budget_2025[]" value="<?php echo htmlspecialchars($productionColumns['budget_2025'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="production_budget_2026[]" value="<?php echo htmlspecialchars($productionColumns['budget_2026'][$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-secondary add-row-button" data-add-row="#production-table">Добавить строку</button>
                        </div>

                        <div class="form-subsection">
                            <h4 class="form-subsection-title">Финансовые результаты</h4>
                            <div class="form-group">
                                <span class="form-group-label">Выберите: с НДС / без НДС *</span>
                                <div class="radio-group">
                                    <label class="radio-option">
                                        <input type="radio" name="financial_results_vat" value="with_vat" <?php echo (($_POST['financial_results_vat'] ?? '') === 'with_vat') ? 'checked' : ''; ?> required>
                                        <span>с НДС</span>
                                    </label>
                                    <label class="radio-option">
                                        <input type="radio" name="financial_results_vat" value="without_vat" <?php echo (($_POST['financial_results_vat'] ?? '') === 'without_vat') ? 'checked' : ''; ?>>
                                        <span>без НДС</span>
                                    </label>
                                </div>
                                <?php if (isset($errors['financial_results_vat'])): ?>
                                    <span class="error-message"><?php echo $errors['financial_results_vat']; ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="table-wrapper">
                                <table class="form-table" id="financial-results-table">
                                    <thead>
                                        <tr>
                                            <th>Показатель</th>
                                            <th>Ед. изм.</th>
                                            <th>2022 факт</th>
                                            <th>2023 факт</th>
                                            <th>2024 факт</th>
                                            <th>9М 2025 факт</th>
                                            <th>2025 бюджет</th>
                                            <th>2026 бюджет</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($row = 0; $row < $financialResultsRowCount; $row++): ?>
                                            <tr>
                                                <td><input type="text" name="financial_results_metric[]" value="<?php echo htmlspecialchars($financialResultsMetrics[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="financial_results_unit[]" value="<?php echo htmlspecialchars($financialResultsUnits[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="financial_results_fact_2022[]" value="<?php echo htmlspecialchars($financialResultsFact2022[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="financial_results_fact_2023[]" value="<?php echo htmlspecialchars($financialResultsFact2023[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="financial_results_fact_2024[]" value="<?php echo htmlspecialchars($financialResultsFact2024[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="financial_results_fact_2025_9m[]" value="<?php echo htmlspecialchars($financialResultsFact2025_9m[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="financial_results_budget_2025[]" value="<?php echo htmlspecialchars($financialResultsBudget2025[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="financial_results_budget_2026[]" value="<?php echo htmlspecialchars($financialResultsBudget2026[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="form-subsection">
                            <h4 class="form-subsection-title">Балансовые показатели</h4>
                            <div class="table-wrapper">
                                <table class="form-table" id="balance-table">
                                    <thead>
                                        <tr>
                                            <th>Показатель</th>
                                            <th>Ед. изм.</th>
                                            <th>31.12.2022 факт</th>
                                            <th>31.12.2023 факт</th>
                                            <th>31.12.2024 факт</th>
                                            <th>30.09.2025 факт</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php for ($row = 0; $row < $balanceRowCount; $row++): ?>
                                            <tr>
                                                <td><input type="text" name="balance_metric[]" value="<?php echo htmlspecialchars($balanceMetrics[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="balance_unit[]" value="<?php echo htmlspecialchars($balanceUnits[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="balance_fact_2022[]" value="<?php echo htmlspecialchars($balanceFact2022[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="balance_fact_2023[]" value="<?php echo htmlspecialchars($balanceFact2023[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="balance_fact_2024[]" value="<?php echo htmlspecialchars($balanceFact2024[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                                <td><input type="text" name="balance_fact_2025[]" value="<?php echo htmlspecialchars($balanceFact2025[$row] ?? '', ENT_QUOTES, 'UTF-8'); ?>"></td>
                                            </tr>
                                        <?php endfor; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="form-group">
                            <span class="form-group-label">Источник финансовых показателей *</span>
                            <div class="radio-group">
                                <label class="radio-option">
                                    <input type="radio" name="financial_source" value="rsbu" <?php echo (($_POST['financial_source'] ?? '') === 'rsbu') ? 'checked' : ''; ?> required>
                                    <span>a. РСБУ</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="financial_source" value="ifrs" <?php echo (($_POST['financial_source'] ?? '') === 'ifrs') ? 'checked' : ''; ?>>
                                    <span>b. МСФО</span>
                                </label>
                                <label class="radio-option">
                                    <input type="radio" name="financial_source" value="management" <?php echo (($_POST['financial_source'] ?? '') === 'management') ? 'checked' : ''; ?>>
                                    <span>c. Управленческая отчетность</span>
                                </label>
                            </div>
                            <?php if (isset($errors['financial_source'])): ?>
                                <span class="error-message"><?php echo $errors['financial_source']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-group checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="agree" required <?php echo isset($_POST['agree']) ? 'checked' : ''; ?>>
                            <span>Я соглашаюсь на обработку персональных данных и использование ИИ для подготовки материалов *</span>
                        </label>
                        <?php if (isset($errors['agree'])): ?>
                            <span class="error-message"><?php echo $errors['agree']; ?></span>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-large">
                        <span>Отправить анкету</span>
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                            <path d="M7.5 15L12.5 10L7.5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </form>
            <?php endif; ?>
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
                    <div class="contact-icon">📧</div>
                    <h3>Email</h3>
                    <p>info@smartbizsell.ru</p>
                </div>
                <div class="contact-card">
                    <div class="contact-icon">📱</div>
                    <h3>Телефон</h3>
                    <p>+7 (495) 123-45-67</p>
                </div>
                <div class="contact-card">
                    <div class="contact-icon">📍</div>
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

