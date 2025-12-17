<?php
/**
 * faq.php
 * 
 * Страница "Часто задаваемые вопросы"
 * FAQ с Schema.org разметкой для rich snippets в поисковой выдаче
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

$pageTitle = "Часто задаваемые вопросы (FAQ) - SmartBizSell";
$pageDescription = "Ответы на часто задаваемые вопросы о платформе SmartBizSell, продаже и покупке бизнеса, оценке, M&A сделках, тизерах и term sheet.";

// Вопросы и ответы для FAQ
$faqs = [
    [
        'question' => 'Что такое SmartBizSell?',
        'answer' => 'SmartBizSell — это экспертная M&A платформа, которая объединяет опыт команды M&A-профессионалов с искусственным интеллектом для продажи и покупки бизнеса. Платформа помогает подготовить бизнес к продаже, создать профессиональные тизеры, оценить стоимость и найти инвесторов.'
    ],
    [
        'question' => 'Как работает платформа SmartBizSell?',
        'answer' => 'Продавец заполняет детальную анкету о бизнесе. Искусственный интеллект анализирует данные и создает профессиональный тизер с финансовыми моделями. Команда проверяет материалы, после чего тизер публикуется в каталоге. Покупатели могут просматривать бизнесы, изучать тизеры и связываться с продавцами.'
    ],
    [
        'question' => 'Сколько стоит использование платформы?',
        'answer' => 'Стоимость зависит от выбранного пакета услуг. Базовые функции доступны бесплатно. За профессиональную подготовку тизера, оценку бизнеса и поиск инвесторов может взиматься плата. Свяжитесь с нами для уточнения стоимости.'
    ],
    [
        'question' => 'Как долго занимает создание тизера?',
        'answer' => 'После заполнения анкеты ИИ создает базовый тизер за несколько минут. Затем команда проверяет и модерирует материалы, что занимает 1-2 рабочих дня. В итоге профессиональный тизер готов за 1-2 дня вместо недель работы консультантов.'
    ],
    [
        'question' => 'Что такое DCF модель?',
        'answer' => 'DCF (Discounted Cash Flow) — это метод оценки бизнеса на основе прогноза будущих денежных потоков. Платформа автоматически рассчитывает DCF модель, прогнозируя денежные потоки на 5 лет вперед и дисконтируя их к текущей стоимости с учетом WACC (средневзвешенной стоимости капитала).'
    ],
    [
        'question' => 'Что такое Term Sheet?',
        'answer' => 'Term Sheet — это документ с ключевыми условиями сделки между продавцом и инвестором. Он включает структуру сделки, цену, долю, условия оплаты, права инвестора и другие важные параметры. Term Sheet помогает ускорить переговоры и зафиксировать основные условия до детальной проработки документов.'
    ],
    [
        'question' => 'Как найти подходящих инвесторов?',
        'answer' => 'Платформа использует RAG (Retrieval-Augmented Generation) технологию для автоматического подбора инвесторов на основе профиля вашего бизнеса. Система анализирует предпочтения инвесторов из базы данных и рекомендует наиболее подходящих кандидатов.'
    ],
    [
        'question' => 'Какие документы нужны для продажи бизнеса?',
        'answer' => 'Для начала работы достаточно заполнить анкету на платформе. Для создания тизера понадобятся: финансовая отчетность за последние 3-5 лет, описание бизнеса, информация о продуктах и услугах, данные о рынке и конкурентах, информация о команде. Дополнительные документы (договоры, лицензии и т.д.) можно загрузить позже.'
    ],
    [
        'question' => 'Можно ли продать бизнес анонимно?',
        'answer' => 'Да, при заполнении анкеты вы можете указать, что название компании не должно раскрываться. В этом случае в тизере будет использоваться общее описание без указания конкретного названия компании.'
    ],
    [
        'question' => 'Как происходит модерация тизеров?',
        'answer' => 'После создания тизера ИИ материалы проверяются командой M&A-консультантов. Они проверяют корректность расчетов, полноту информации, соответствие стандартам. При необходимости материалы корректируются перед публикацией.'
    ],
    [
        'question' => 'Какие отрасли поддерживает платформа?',
        'answer' => 'Платформа работает с бизнесами различных отраслей: IT и технологии, ритейл, производство, услуги, рестораны, e-commerce, недвижимость, логистика, сельское хозяйство, здравоохранение, нефтегаз, финансовый сектор и другие.'
    ],
    [
        'question' => 'Как рассчитывается стоимость бизнеса?',
        'answer' => 'Платформа использует два метода оценки: DCF (дисконтированные денежные потоки) и метод мультипликаторов. DCF основан на прогнозе будущих денежных потоков, а мультипликаторы — на сравнении с аналогичными компаниями в отрасли. Оба метода дают диапазон справедливой стоимости.'
    ],
    [
        'question' => 'Что делать после публикации тизера?',
        'answer' => 'После публикации тизер становится доступен в каталоге для просмотра покупателями и инвесторами. Вы получите уведомления о заинтересованных покупателях. Платформа также автоматически подберет подходящих инвесторов. При необходимости можно создать Term Sheet для фиксации условий сделки.'
    ],
    [
        'question' => 'Нужна ли регистрация для просмотра бизнесов?',
        'answer' => 'Нет, просмотр каталога бизнесов доступен без регистрации. Однако для связи с продавцом и получения дополнительной информации может потребоваться регистрация.'
    ],
    [
        'question' => 'Как обеспечена конфиденциальность данных?',
        'answer' => 'Мы серьезно относимся к защите конфиденциальности. Финансовые данные и документы доступны только авторизованным пользователям. При необходимости можно скрыть название компании в публичном тизере. Все данные хранятся на защищенных серверах.'
    ]
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="keywords" content="FAQ SmartBizSell, вопросы о продаже бизнеса, вопросы о покупке бизнеса, как работает SmartBizSell">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo BASE_URL; ?>/faq">
    
    <!-- Open Graph -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/faq">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="stylesheet" href="styles.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        .faq-page {
            max-width: 900px;
            margin: 0 auto;
            padding: 100px 20px 60px;
        }
        .faq-header {
            text-align: center;
            margin-bottom: 60px;
        }
        .faq-header h1 {
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .faq-header p {
            font-size: 20px;
            color: var(--text-secondary);
        }
        .faq-item {
            background: white;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            margin-bottom: 20px;
            border: 1px solid rgba(0, 0, 0, 0.06);
        }
        .faq-question {
            font-size: 22px;
            font-weight: 700;
            margin-bottom: 16px;
            color: var(--text-primary);
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .faq-question:hover {
            color: #667EEA;
        }
        .faq-question::after {
            content: '+';
            font-size: 28px;
            color: #667EEA;
            font-weight: 300;
            transition: transform 0.3s ease;
        }
        .faq-item.active .faq-question::after {
            transform: rotate(45deg);
        }
        .faq-answer {
            font-size: 18px;
            line-height: 1.8;
            color: var(--text-secondary);
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
            padding: 0;
        }
        .faq-item.active .faq-answer {
            max-height: 1000px;
            padding-top: 16px;
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

    <div class="faq-page">
        <div class="faq-header">
            <h1>Часто задаваемые вопросы</h1>
            <p>Ответы на популярные вопросы о платформе SmartBizSell</p>
        </div>

        <div class="faq-list">
            <?php foreach ($faqs as $index => $faq): ?>
                <div class="faq-item" data-faq-index="<?php echo $index; ?>">
                    <div class="faq-question">
                        <?php echo htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                    <div class="faq-answer">
                        <?php echo nl2br(htmlspecialchars($faq['answer'], ENT_QUOTES, 'UTF-8')); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Структурированные данные FAQPage -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "FAQPage",
        "mainEntity": [
            <?php 
            $faqItems = [];
            foreach ($faqs as $faq) {
                $faqItems[] = json_encode([
                    "@type" => "Question",
                    "name" => $faq['question'],
                    "acceptedAnswer" => [
                        "@type" => "Answer",
                        "text" => $faq['answer']
                    ]
                ], JSON_UNESCAPED_UNICODE);
            }
            echo implode(",\n            ", $faqItems);
            ?>
        ]
    }
    </script>

    <script>
        // Интерактивность для FAQ
        document.addEventListener('DOMContentLoaded', function() {
            const faqItems = document.querySelectorAll('.faq-item');
            faqItems.forEach(item => {
                const question = item.querySelector('.faq-question');
                question.addEventListener('click', function() {
                    const isActive = item.classList.contains('active');
                    // Закрываем все остальные
                    faqItems.forEach(otherItem => {
                        if (otherItem !== item) {
                            otherItem.classList.remove('active');
                        }
                    });
                    // Переключаем текущий
                    item.classList.toggle('active', !isActive);
                });
            });
        });
    </script>

    <script src="script.js?v=<?php echo time(); ?>"></script>
</body>
</html>

