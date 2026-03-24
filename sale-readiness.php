<?php
/**
 * Интерактивный сервис: оценка готовности бизнеса к продаже.
 * Полностью автономная страница: HTML + CSS + JS без серверных API.
 */
require_once 'config.php';

$pageTitle = 'Оцените готовность бизнеса к продаже за 2 минуты | SmartBizSell';
$pageDescription = 'Пройдите экспресс-анкету и получите персональные рекомендации по подготовке бизнеса к продаже.';
$canonicalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . strtok($_SERVER['REQUEST_URI'] ?? '/sale-readiness.php', '?');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/styles.css?v=<?php echo time(); ?>">
    <?php include __DIR__ . '/yandex_metrika.php'; ?>

    <style>
        /* ===== Локальные переменные и базовая сетка страницы ===== */
        :root {
            --readiness-surface: #ffffff;
            --readiness-bg: #f5f7ff;
            --readiness-border: #e5e7eb;
            --readiness-text: #0f172a;
            --readiness-muted: #64748b;
            --readiness-primary: #4f46e5;
            --readiness-primary-soft: #eef2ff;
            --readiness-success: #16a34a;
            --readiness-warning: #d97706;
            --readiness-danger: #dc2626;
            --readiness-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        }

        body {
            background: linear-gradient(180deg, #f8faff 0%, #eef2ff 100%);
        }

        .readiness-page {
            max-width: 1040px;
            margin: 0 auto;
            padding: 24px 16px 72px;
            color: var(--readiness-text);
        }

        .readiness-back {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--readiness-primary);
            font-weight: 600;
            text-decoration: none;
        }

        .readiness-back:hover {
            text-decoration: underline;
        }

        /* ===== Hero-блок сервиса ===== */
        .readiness-hero {
            background: var(--readiness-surface);
            border: 1px solid rgba(79, 70, 229, 0.16);
            box-shadow: var(--readiness-shadow);
            border-radius: 22px;
            padding: 28px 24px;
            margin-bottom: 18px;
        }

        .readiness-kicker {
            margin: 0 0 10px;
            color: var(--readiness-primary);
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            font-size: 12px;
        }

        .readiness-title {
            margin: 0 0 12px;
            font-size: clamp(28px, 4.2vw, 42px);
            line-height: 1.12;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .readiness-subtitle {
            margin: 0;
            color: var(--readiness-muted);
            font-size: 17px;
            line-height: 1.6;
        }

        /* ===== Прогресс заполнения анкеты ===== */
        .readiness-progress {
            background: var(--readiness-surface);
            border: 1px solid var(--readiness-border);
            border-radius: 16px;
            padding: 14px 16px;
            margin-bottom: 16px;
            position: sticky;
            top: 76px;
            z-index: 5;
            backdrop-filter: blur(8px);
        }

        .readiness-progress-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 600;
            color: #334155;
        }

        .readiness-progress-track {
            width: 100%;
            height: 10px;
            border-radius: 999px;
            background: #e2e8f0;
            overflow: hidden;
        }

        .readiness-progress-fill {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
            transition: width 0.35s ease;
        }

        /* ===== Форма и карточки вопросов ===== */
        .readiness-form-wrap {
            background: var(--readiness-surface);
            border: 1px solid var(--readiness-border);
            border-radius: 20px;
            box-shadow: var(--readiness-shadow);
            padding: 22px;
        }

        .readiness-form {
            display: grid;
            gap: 16px;
        }

        .readiness-question {
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
            padding: 16px;
            transition: border-color 0.25s ease, box-shadow 0.25s ease, transform 0.2s ease;
            animation: riseIn 0.45s ease both;
        }

        .readiness-question:hover {
            border-color: #c7d2fe;
            box-shadow: 0 8px 24px rgba(79, 70, 229, 0.08);
            transform: translateY(-1px);
        }

        .readiness-question legend {
            width: 100%;
            margin-bottom: 10px;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.45;
        }

        .readiness-question-number {
            color: var(--readiness-primary);
            margin-right: 8px;
        }

        .readiness-options {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .readiness-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .readiness-option-card {
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
            line-height: 1.5;
            color: #1f2937;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s ease;
            display: block;
        }

        .readiness-option-card:hover {
            border-color: #a5b4fc;
            background: #f8faff;
        }

        .readiness-option input[type="radio"]:focus-visible + .readiness-option-card {
            outline: 3px solid rgba(79, 70, 229, 0.28);
            outline-offset: 1px;
        }

        .readiness-option input[type="radio"]:checked + .readiness-option-card {
            border-color: var(--readiness-primary);
            background: var(--readiness-primary-soft);
            box-shadow: 0 0 0 1px rgba(79, 70, 229, 0.25);
            transform: translateY(-1px);
        }

        .readiness-option-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            border-radius: 999px;
            background: #eef2ff;
            color: #4338ca;
            font-weight: 700;
            margin-right: 8px;
            font-size: 13px;
        }

        .readiness-actions {
            margin-top: 8px;
            display: flex;
            justify-content: flex-start;
        }

        .readiness-btn {
            border: none;
            border-radius: 12px;
            padding: 13px 20px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, opacity 0.2s ease;
        }

        .readiness-btn:hover {
            transform: translateY(-1px);
        }

        .readiness-btn-primary {
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            box-shadow: 0 10px 24px rgba(79, 70, 229, 0.28);
        }

        .readiness-btn-secondary {
            background: #eef2ff;
            color: #312e81;
        }

        .readiness-btn-outline {
            background: transparent;
            border: 1px solid #cbd5e1;
            color: #1f2937;
        }

        .readiness-btn:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
        }

        .readiness-message {
            margin-top: 12px;
            color: #b91c1c;
            font-size: 14px;
            font-weight: 600;
            min-height: 20px;
        }

        /* ===== Экран результата ===== */
        .readiness-result {
            display: none;
            margin-top: 20px;
            background: #fff;
            border: 1px solid var(--readiness-border);
            box-shadow: var(--readiness-shadow);
            border-radius: 20px;
            padding: 22px;
            animation: fadeInUp 0.45s ease both;
        }

        .readiness-result.visible {
            display: block;
        }

        .readiness-score {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 20px;
            align-items: center;
            margin-bottom: 20px;
        }

        .readiness-circle-wrap {
            display: flex;
            justify-content: center;
        }

        .readiness-circle {
            width: 172px;
            height: 172px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: conic-gradient(var(--score-color) calc(var(--score-percent) * 1%), #e2e8f0 0);
            transition: background 0.5s ease;
            position: relative;
        }

        .readiness-circle::after {
            content: '';
            position: absolute;
            inset: 14px;
            background: #fff;
            border-radius: 50%;
        }

        .readiness-circle-value {
            position: relative;
            z-index: 1;
            font-weight: 800;
            font-size: 38px;
            color: var(--score-color);
            letter-spacing: -0.02em;
        }

        .readiness-result-title {
            margin: 0 0 8px;
            font-size: clamp(24px, 4vw, 30px);
            line-height: 1.2;
            font-weight: 800;
        }

        .readiness-result-text {
            margin: 0;
            color: #475569;
            font-size: 17px;
        }

        .readiness-hard-alert {
            margin-top: 10px;
            border-left: 4px solid #dc2626;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 8px;
            padding: 10px 12px;
            font-weight: 700;
        }

        .readiness-recommendations {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .readiness-rec-card {
            border: 1px solid #fde68a;
            background: #fffbeb;
            border-radius: 12px;
            padding: 12px;
        }

        .readiness-rec-title {
            margin: 0 0 6px;
            font-size: 16px;
            font-weight: 700;
            color: #78350f;
        }

        .readiness-rec-text {
            margin: 0 0 8px;
            line-height: 1.5;
            color: #92400e;
        }

        .readiness-rec-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: #7c2d12;
            font-size: 14px;
            font-weight: 600;
        }

        .readiness-rec-meta span {
            border-radius: 999px;
            padding: 5px 10px;
            background: rgba(251, 191, 36, 0.2);
        }

        .readiness-result-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .readiness-share-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .readiness-share-link {
            font-size: 14px;
            text-decoration: none;
            border-radius: 10px;
            padding: 9px 12px;
            font-weight: 700;
            color: #fff;
            transition: opacity 0.2s ease;
        }

        .readiness-share-link:hover {
            opacity: 0.9;
        }

        .readiness-share-link.tg { background: #08c; }
        .readiness-share-link.vk { background: #07f; }
        .readiness-share-link.fb { background: #1877f2; }

        .readiness-no-recs {
            margin: 0;
            color: #166534;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 12px;
            padding: 12px 14px;
            font-weight: 600;
        }

        .hidden-for-results {
            display: none;
        }

        /* ===== Адаптивность для мобильных устройств ===== */
        @media (max-width: 820px) {
            .readiness-progress {
                top: 66px;
            }

            .readiness-score {
                grid-template-columns: 1fr;
                text-align: center;
            }

            .readiness-circle {
                width: 154px;
                height: 154px;
            }

            .readiness-circle-value {
                font-size: 34px;
            }
        }

        @media (max-width: 560px) {
            .readiness-page {
                padding: 18px 10px 50px;
            }

            .readiness-hero,
            .readiness-form-wrap,
            .readiness-result {
                border-radius: 16px;
                padding: 14px;
            }

            .readiness-title {
                font-size: 28px;
            }

            .readiness-question {
                padding: 12px;
            }

            .readiness-option-card {
                font-size: 14px;
            }

            .readiness-result-actions .readiness-btn,
            .readiness-result-actions a.readiness-btn {
                width: 100%;
                justify-content: center;
                text-align: center;
            }
        }

        /* ===== Простые keyframes-анимации для появления блоков ===== */
        @keyframes riseIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="nav-content">
                <a href="/" class="logo">
                    <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                    <span class="logo-text">SmartBizSell.ru</span>
                </a>
                <ul class="nav-menu">
                    <li><a href="/">Главная</a></li>
                    <li><a href="/#how-it-works">Как это работает</a></li>
                    <li><a href="/#buy-business">Купить бизнес</a></li>
                    <li><a href="/estimate.php">Оценить бизнес</a></li>
                    <?php if (isLoggedIn()): ?>
                        <li><a href="/dashboard.php">Личный кабинет</a></li>
                        <li><a href="/logout.php">Выйти</a></li>
                    <?php else: ?>
                        <li><a href="/login.php">Войти</a></li>
                        <li><a href="/register.php">Регистрация</a></li>
                    <?php endif; ?>
                </ul>
                <button class="nav-toggle" aria-label="Меню"><span></span><span></span><span></span></button>
            </div>
        </div>
    </nav>

    <main class="readiness-page">
        <!-- Блок возврата на главную -->
        <a class="readiness-back" href="/">← На главную</a>

        <!-- Hero блок сервиса с ценностным оффером -->
        <section class="readiness-hero">
            <p class="readiness-kicker">Экспресс-анализ за 2–3 минуты</p>
            <h1 class="readiness-title">Оцените готовность бизнеса к продаже за 2 минуты</h1>
            <p class="readiness-subtitle">Ответьте на 8 вопросов и получите персональные рекомендации с оценкой сложности и сроков доработки.</p>
        </section>

        <!-- Липкий индикатор заполненности анкеты -->
        <section class="readiness-progress" aria-label="Прогресс заполнения анкеты">
            <div class="readiness-progress-head">
                <span id="readiness-progress-label">Заполнено 0 из 8 вопросов</span>
                <span id="readiness-progress-percent">0%</span>
            </div>
            <div class="readiness-progress-track">
                <div class="readiness-progress-fill" id="readiness-progress-fill"></div>
            </div>
        </section>

        <!-- Форма анкеты со всеми вопросами на одной странице -->
        <section class="readiness-form-wrap" id="readiness-form-wrap">
            <form id="sale-readiness-form" class="readiness-form" novalidate>
                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">1.</span>Финансовая прозрачность. Есть ли у компании финансовая отчетность за последние 2–3 года.</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q1" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Есть аудированная или подробная управленческая отчетность, совпадающая с финансовой</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q1" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Учет ведется в упрощенном виде, может не совпадать с РСБУ</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">2.</span>Юридическая структура:</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q2" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Бизнес сосредоточен в 1-2 компаниях (ООО, АО), структура владения простая (прямое владение бенефициарами).</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q2" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Бизнес представлен множеством юридических лиц или ИП, структура владения сложная или многоуровневая.</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">3.</span>Операционная независимость. Структура управления:</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q3" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Есть команда независимых менеджеров (генеральный, финансовый и пр. директора), либо в найме, либо с небольшой долей в бизнесе.</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q3" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Собственники занимают ключевые управленческие позиции.</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">4.</span>Налоговая дисциплина. Были ли налоговые споры, доначисления, отсрочки или рассрочки по уплате налогов за последние 2 года:</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q4" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Нет</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q4" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Да</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">5.</span>Права на активы. Кому принадлежат ключевые активы бизнеса (основные средства, контракты с клиентами, товарные знаки, патенты, лицензии):</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q5" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Напрямую компании, которая продается</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q5" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Прочим сторонам (акционерам, связанным лицам и т.п.)</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">6.</span>Клиентская диверсификация. Доля крупнейшего клиента в выручке компании:</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q6" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Менее 30%</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q6" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Более 30%</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">7.</span>Диверсификация поставщиков сырья/услуг. Доля крупнейшего поставщика в структуре расходов компании:</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q7" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Менее 30%</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q7" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Более 30%</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="readiness-question">
                    <legend><span class="readiness-question-number">8.</span>Информационные технологии. Для управления основными бизнес-процессами (финансы, закупки, склад, производство) используется:</legend>
                    <div class="readiness-options">
                        <label class="readiness-option">
                            <input type="radio" name="q8" value="a">
                            <span class="readiness-option-card"><span class="readiness-option-tag">a</span>Единая учетная система (ERP или специализированное ПО)</span>
                        </label>
                        <label class="readiness-option">
                            <input type="radio" name="q8" value="b">
                            <span class="readiness-option-card"><span class="readiness-option-tag">b</span>Учет ведется в Excel или вручную</span>
                        </label>
                    </div>
                </fieldset>

                <!-- Кнопка расчета и зона ошибок валидации -->
                <div class="readiness-actions">
                    <button type="submit" class="readiness-btn readiness-btn-primary" id="readiness-submit">Показать готовность к продаже</button>
                </div>
                <div id="readiness-message" class="readiness-message" aria-live="polite"></div>
            </form>
        </section>

        <!-- Экран результата со скорингом, рекомендациями и CTA -->
        <section id="readiness-result" class="readiness-result" aria-live="polite" aria-atomic="true">
            <div class="readiness-score">
                <div class="readiness-circle-wrap">
                    <div id="readiness-circle" class="readiness-circle" style="--score-percent:0;--score-color:#16a34a;">
                        <span id="readiness-circle-value" class="readiness-circle-value">0%</span>
                    </div>
                </div>
                <div>
                    <h2 class="readiness-result-title">Ваш результат</h2>
                    <p class="readiness-result-text" id="readiness-result-text">Готовность вашего бизнеса к продаже: 0%</p>
                    <div id="readiness-hard-alert" class="readiness-hard-alert hidden-for-results">Общая сложность доработки до 100% — высокая</div>
                </div>
            </div>

            <div id="readiness-recommendations" class="readiness-recommendations"></div>

            <div class="readiness-result-actions">
                <a class="readiness-btn readiness-btn-primary" href="/seller_form.php">Начать продажу бизнеса</a>
                <button type="button" id="readiness-share-button" class="readiness-btn readiness-btn-secondary">Поделиться результатами</button>
                <button type="button" id="readiness-edit-button" class="readiness-btn readiness-btn-outline">Изменить ответы</button>
            </div>
            <div id="readiness-share-links" class="readiness-share-links" style="margin-top:10px;"></div>
        </section>
    </main>

    <script>
    /**
     * Основной JS модуль анкеты:
     * - считает прогресс и скоринг
     * - формирует рекомендации
     * - рендерит результат и share-ссылки
     */
    (function () {
        // Справочник рекомендаций для каждого ответа b.
        const recommendationsMap = {
            q1: {
                title: '1. Финансовая прозрачность',
                text: 'Привести финансовую отчётность в порядок (аудированная или подробная управленческая)',
                complexity: 'средняя',
                timeline: '1–3 месяца'
            },
            q2: {
                title: '2. Юридическая структура',
                text: 'Провести реструктуризацию: объединить компании в 1–2 юрлица и упростить структуру владения',
                complexity: 'высокая',
                timeline: '3–6 месяцев'
            },
            q3: {
                title: '3. Операционная независимость',
                text: 'Нанять независимых ключевых менеджеров (гендиректор, финдиректор и др.) и делегировать оперативное управление',
                complexity: 'средняя',
                timeline: '2–4 месяца'
            },
            q4: {
                title: '4. Налоговая дисциплина',
                text: 'Закрыть/разрешить все налоговые споры и доначисления за последние 2 года',
                complexity: 'высокая',
                timeline: '1–6 месяцев (зависит от объёма)'
            },
            q5: {
                title: '5. Права на активы',
                text: 'Перевести ключевые активы (ОС, контракты, товарные знаки, лицензии) на баланс продаваемой компании',
                complexity: 'высокая',
                timeline: '1–3 месяца'
            },
            q6: {
                title: '6. Клиентская диверсификация',
                text: 'Диверсифицировать клиентскую базу — снизить долю крупнейшего клиента ниже 30%',
                complexity: 'высокая',
                timeline: '6–12 месяцев'
            },
            q7: {
                title: '7. Диверсификация поставщиков',
                text: 'Диверсифицировать поставщиков — снизить долю крупнейшего поставщика ниже 30%',
                complexity: 'средняя',
                timeline: '3–6 месяцев'
            },
            q8: {
                title: '8. Информационные технологии',
                text: 'Внедрить единую учётную систему (ERP или отраслевое ПО) вместо Excel',
                complexity: 'средняя',
                timeline: '3–6 месяцев'
            }
        };

        // Базовые ссылки и элементы интерфейса.
        const questionNames = ['q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8'];
        const totalQuestions = questionNames.length;
        const canonicalUrl = '<?php echo addslashes($canonicalUrl); ?>';

        const form = document.getElementById('sale-readiness-form');
        const progressFill = document.getElementById('readiness-progress-fill');
        const progressLabel = document.getElementById('readiness-progress-label');
        const progressPercent = document.getElementById('readiness-progress-percent');
        const message = document.getElementById('readiness-message');
        const result = document.getElementById('readiness-result');
        const circle = document.getElementById('readiness-circle');
        const circleValue = document.getElementById('readiness-circle-value');
        const resultText = document.getElementById('readiness-result-text');
        const hardAlert = document.getElementById('readiness-hard-alert');
        const recContainer = document.getElementById('readiness-recommendations');
        const editButton = document.getElementById('readiness-edit-button');
        const shareButton = document.getElementById('readiness-share-button');
        const shareLinks = document.getElementById('readiness-share-links');

        // Получение текущих ответов из формы.
        function getAnswers() {
            return questionNames.reduce((acc, name) => {
                const selected = form.querySelector(`input[name="${name}"]:checked`);
                acc[name] = selected ? selected.value : null;
                return acc;
            }, {});
        }

        // Обновление верхнего прогресса по заполненности формы.
        function updateProgress() {
            const answers = getAnswers();
            const completed = Object.values(answers).filter(Boolean).length;
            const percent = Math.round((completed / totalQuestions) * 100);

            progressFill.style.width = `${percent}%`;
            progressLabel.textContent = `Заполнено ${completed} из ${totalQuestions} вопросов`;
            progressPercent.textContent = `${percent}%`;
        }

        // Выбор цвета индикатора по правилам ТЗ.
        function getScoreColor(score) {
            if (score >= 75) {
                return '#16a34a';
            }
            if (score >= 50) {
                return '#d97706';
            }
            return '#dc2626';
        }

        // Рендер рекомендаций для выбранных ответов b.
        function renderRecommendations(answerMap) {
            const bEntries = questionNames.filter((name) => answerMap[name] === 'b');
            const html = [];
            let hasHighComplexity = false;

            bEntries.forEach((name) => {
                const item = recommendationsMap[name];
                if (!item) {
                    return;
                }
                if (item.complexity === 'высокая') {
                    hasHighComplexity = true;
                }
                html.push(
                    `<article class="readiness-rec-card">
                        <h3 class="readiness-rec-title">${item.title}</h3>
                        <p class="readiness-rec-text">${item.text}</p>
                        <div class="readiness-rec-meta">
                            <span>Сложность: ${item.complexity}</span>
                            <span>Срок: ${item.timeline}</span>
                        </div>
                    </article>`
                );
            });

            if (html.length === 0) {
                recContainer.innerHTML = '<p class="readiness-no-recs">Отличный результат: критичных зон для доработки не выявлено.</p>';
            } else {
                recContainer.innerHTML = html.join('');
            }

            hardAlert.classList.toggle('hidden-for-results', !hasHighComplexity);
            return hasHighComplexity;
        }

        // Формирование ссылок для шаринга по аналогии с existing estimate flow.
        function buildShareLinks(score) {
            const shareText = `Моя готовность бизнеса к продаже: ${score}% (SmartBizSell)`;
            const tg = `https://t.me/share/url?url=${encodeURIComponent(canonicalUrl)}&text=${encodeURIComponent(shareText)}`;
            const vk = `https://vk.com/share.php?url=${encodeURIComponent(canonicalUrl)}&title=${encodeURIComponent(shareText)}`;
            const fb = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(canonicalUrl)}`;

            shareLinks.innerHTML = `
                <a class="readiness-share-link tg" href="${tg}" target="_blank" rel="noopener">Telegram</a>
                <a class="readiness-share-link vk" href="${vk}" target="_blank" rel="noopener">VK</a>
                <a class="readiness-share-link fb" href="${fb}" target="_blank" rel="noopener">Facebook</a>
            `;

            return { shareText };
        }

        // Рендер итогового экрана результата.
        function renderResult(score, answerMap) {
            const color = getScoreColor(score);
            circle.style.setProperty('--score-percent', String(score));
            circle.style.setProperty('--score-color', color);
            circleValue.style.color = color;
            circleValue.textContent = `${score}%`;
            resultText.textContent = `Готовность вашего бизнеса к продаже: ${score}%`;

            renderRecommendations(answerMap);
            buildShareLinks(score);

            result.classList.add('visible');
            result.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        // Сабмит анкеты: валидация + точный расчет из ТЗ.
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            message.textContent = '';

            const answers = getAnswers();
            const completed = Object.values(answers).filter(Boolean).length;

            if (completed < totalQuestions) {
                message.textContent = 'Ответьте на все 8 вопросов, чтобы увидеть персональный результат.';
                return;
            }

            // Каждый ответ "a" = 12.5 баллов, итог с Math.round.
            const countA = Object.values(answers).filter((value) => value === 'a').length;
            const score = Math.round(countA * 12.5);

            renderResult(score, answers);
        });

        // Обработчики для обновления прогресса в реальном времени.
        form.addEventListener('change', updateProgress);

        // Возврат к редактированию без потери ответов.
        editButton.addEventListener('click', function () {
            result.classList.remove('visible');
            shareLinks.innerHTML = '';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        // Кнопка share: пытаемся использовать native share API, иначе показываем ссылки.
        shareButton.addEventListener('click', async function () {
            const text = (resultText.textContent || '').trim();
            if (!text) {
                return;
            }
            if (navigator.share) {
                try {
                    await navigator.share({
                        title: 'Готовность бизнеса к продаже',
                        text,
                        url: canonicalUrl
                    });
                    return;
                } catch (error) {
                    // Игнорируем отмену/ошибку и оставляем fallback-ссылки.
                }
            }
            if (!shareLinks.innerHTML.trim()) {
                buildShareLinks(circleValue.textContent.replace('%', ''));
            }
            shareLinks.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        });

        // Инициализация прогресса при первом рендере.
        updateProgress();
    })();
    </script>
</body>
</html>
