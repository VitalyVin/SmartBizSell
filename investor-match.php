<?php
require_once __DIR__ . '/config.php';
$assetVersion = getenv('ASSET_VERSION') ?: '2026-04-30';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подбор инвестора | SmartBizSell</title>
    <meta name="description" content="Заполните короткую анкету и получите список потенциальных инвесторов для вашего бизнеса.">
    <link rel="stylesheet" href="/styles.css?v=<?php echo htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8'); ?>">
    <?php include __DIR__ . '/yandex_metrika.php'; ?>
    <style>
        body {
            background: #f3f5fb;
        }

        .investor-match-page {
            max-width: 1080px;
            margin: 0 auto;
            padding: 108px 16px 56px;
            position: relative;
        }

        .investor-match-back {
            display: inline-block;
            margin-bottom: 16px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
        }

        .investor-match-back:hover {
            text-decoration: underline;
        }

        .investor-match-hero,
        .investor-match-progress,
        .investor-match-form-wrap,
        .investor-match-result {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            box-shadow: 0 6px 16px rgba(15, 23, 42, 0.05);
            padding: 24px;
        }

        .investor-match-hero {
            margin-bottom: 16px;
        }

        .investor-match-hero h1 {
            margin: 0 0 10px;
            font-size: clamp(30px, 4vw, 44px);
            line-height: 1.15;
        }

        .investor-match-hero p {
            margin: 0;
            color: var(--text-secondary);
            font-size: 16px;
            line-height: 1.65;
        }

        .investor-match-progress {
            margin-bottom: 16px;
            padding: 14px 16px;
        }

        .investor-match-progress-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            color: #374151;
            font-size: 14px;
            font-weight: 600;
        }

        .investor-match-progress-track {
            height: 10px;
            width: 100%;
            border-radius: 999px;
            overflow: hidden;
            background: #e5e7eb;
        }

        .investor-match-progress-fill {
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #6366f1 0%, #8b5cf6 100%);
            transition: width 0.35s ease;
        }

        .investor-match-form-wrap {
            margin-bottom: 16px;
        }

        .investor-match-form {
            display: grid;
            gap: 14px;
        }

        .investor-match-field {
            display: grid;
            gap: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 14px;
            background: #fcfcfd;
        }

        .investor-match-field label {
            font-weight: 700;
            color: var(--text-primary);
        }

        .investor-match-field input,
        .investor-match-field textarea {
            width: 100%;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 15px;
            font-family: inherit;
        }

        .investor-match-field textarea {
            min-height: 110px;
            resize: vertical;
        }

        .investor-match-help {
            margin: 0;
            color: var(--text-secondary);
            font-size: 13px;
        }

        .investor-match-options {
            display: grid;
            gap: 8px;
        }

        .investor-match-option {
            position: relative;
        }

        .investor-match-option input[type="checkbox"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
            width: 1px;
            height: 1px;
            margin: 0;
            padding: 0;
        }

        .investor-match-option-card {
            display: block;
            border: 1px solid #d1d5db;
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
            line-height: 1.5;
            color: #1f2937;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .investor-match-option-card:hover {
            border-color: #a5b4fc;
            background: #f8faff;
        }

        .investor-match-option input[type="checkbox"]:focus-visible + .investor-match-option-card {
            outline: 3px solid rgba(79, 70, 229, 0.28);
            outline-offset: 1px;
        }

        .investor-match-option input[type="checkbox"]:checked + .investor-match-option-card {
            border-color: #4f46e5;
            background: #eef2ff;
            box-shadow: 0 0 0 1px rgba(79, 70, 229, 0.2);
            transform: translateY(-1px);
        }

        .investor-match-actions {
            display: flex;
            justify-content: flex-start;
            margin-top: 8px;
        }

        .investor-submit-progress {
            margin-top: 10px;
            display: none;
        }

        .investor-submit-progress.active {
            display: block;
        }

        .investor-match-result {
            display: none;
            gap: 20px;
            background: linear-gradient(180deg, #ffffff 0%, #f8faff 100%);
        }

        .investor-match-result.active {
            display: grid;
        }

        .investor-result-block h2 {
            margin: 0 0 10px;
            font-size: 24px;
            line-height: 1.25;
            color: #1f2937;
        }

        .investor-result-kicker {
            display: inline-flex;
            margin-bottom: 8px;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            background: #e0e7ff;
            color: #3730a3;
        }

        .investor-summary {
            background: linear-gradient(135deg, #eef2ff 0%, #f8faff 100%);
            border: 1px solid #c7d2fe;
            border-radius: 16px;
            padding: 16px 18px;
            color: #1f2937;
            line-height: 1.7;
            font-size: 15px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
        }

        .investor-top-note {
            margin: 0 0 10px;
            font-size: 14px;
            color: #4b5563;
            line-height: 1.55;
        }

        .investor-section--public {
            margin-top: 10px;
        }

        .investor-section--public .investor-section__intro {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 14px;
        }

        .investor-section--public .investor-section__intro h3 {
            margin: 0 0 6px;
            font-size: 22px;
            color: #111827;
        }

        .investor-section--public .investor-section__intro p {
            margin: 0;
            color: #6b7280;
            line-height: 1.55;
        }

        .investor-section--public .investor-section__count {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            border-radius: 999px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
            box-shadow: 0 8px 20px rgba(102, 126, 234, 0.24);
        }

        .investor-section--public .investor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 14px;
        }

        .investor-section--public .investor-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8faff 100%);
            border: 1px solid rgba(99, 102, 241, 0.18);
            border-radius: 16px;
            padding: 16px;
            box-shadow: 0 8px 22px rgba(15, 23, 42, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .investor-section--public .investor-card:hover {
            transform: translateY(-2px);
            border-color: rgba(99, 102, 241, 0.32);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.12);
        }

        .investor-section--public .investor-card[data-source="ai"] {
            border-color: rgba(16, 185, 129, 0.34);
            background: linear-gradient(135deg, #ffffff 0%, #ecfdf5 100%);
        }

        .investor-section--public .investor-card__head h4 {
            margin: 0;
            font-size: 19px;
            color: #111827;
            line-height: 1.3;
        }

        .investor-section--public .investor-card__badge {
            display: inline-flex;
            margin-top: 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            color: #065f46;
            background: #d1fae5;
        }

        .investor-section--public .investor-card__focus,
        .investor-section--public .investor-card__check,
        .investor-section--public .investor-card__reason {
            margin: 10px 0 0;
            color: #374151;
            line-height: 1.52;
        }

        .investor-section--public .investor-card__focus {
            font-weight: 600;
            color: #1f2937;
        }

        .investor-match-final-note {
            margin-top: 8px;
            border: 1px solid #d1fae5;
            background: #ecfdf5;
            border-radius: 12px;
            padding: 12px 14px;
            color: #065f46;
            line-height: 1.55;
        }

        .investor-match-error {
            margin-top: 10px;
            color: #dc2626;
            font-size: 14px;
        }

        .investor-match-final-note a {
            font-weight: 700;
            color: #047857;
        }

        @media (max-width: 768px) {
            .investor-match-page {
                padding: 96px 12px 40px;
            }

            .investor-section--public .investor-section__intro {
                flex-direction: column;
            }

            .investor-section--public .investor-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<nav class="navbar">
    <div class="container">
        <div class="nav-content">
            <a href="/index.php" class="logo">
                <span class="logo-icon"><?php echo getLogoIcon(); ?></span>
                <span class="logo-text">SmartBizSell.ru</span>
            </a>
            <ul class="nav-menu">
                <li><a href="/index.php#how-it-works">Как это работает</a></li>
                <li><a href="/index.php#buy-business">Купить бизнес</a></li>
                <li><a href="/blog">Блог</a></li>
                <?php if (isLoggedIn()): ?>
                    <li><a href="/dashboard.php">Продать бизнес</a></li>
                    <?php if (isModerator()): ?>
                        <li><a href="/moderation.php">Модерация</a></li>
                    <?php endif; ?>
                <?php else: ?>
                    <li><a href="/login.php">Продать бизнес</a></li>
                <?php endif; ?>
                <li><a href="/index.php#contact">Контакты</a></li>
                <?php if (isLoggedIn()): ?>
                    <li><a href="/dashboard.php">Личный кабинет</a></li>
                    <li><a href="/logout.php">Выйти</a></li>
                <?php else: ?>
                    <li><a href="/login.php">Войти</a></li>
                    <li><a href="/register.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Регистрация</a></li>
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

<div class="investor-match-page">
    <a class="investor-match-back" href="/index.php">← На главную</a>

    <section class="investor-match-hero">
        <h1>Подбор потенциальных инвесторов</h1>
        <p>
            Заполните короткую анкету, чтобы получить первичный список инвесторов под ваш кейс.
            Мы сформируем краткое инвестиционное описание бизнеса с помощью AI и подберем 5 наиболее подходящих инвесторов из каталога SmartBizSell.
        </p>
    </section>

    <section class="investor-match-progress">
        <div class="investor-match-progress-head">
            <span id="investor-fill-progress-label">Заполнено 0 из 7 вопросов</span>
            <span id="investor-fill-progress-percent">0%</span>
        </div>
        <div class="investor-match-progress-track">
            <div id="investor-fill-progress-bar" class="investor-match-progress-fill"></div>
        </div>
    </section>

    <section class="investor-match-form-wrap">
        <form id="investor-match-form" class="investor-match-form">
            <div class="investor-match-field">
                <label for="inn">1. ИНН (или ОГРН) юридического лица</label>
                <input id="inn" name="inn" type="text" required placeholder="Например: 7701234567">
                <p class="investor-match-help">Если бизнес на нескольких юрлицах, укажите основное операционное.</p>
            </div>

            <fieldset class="investor-match-field">
                <label>2. Предмет сделки (можно выбрать несколько)</label>
                <div class="investor-match-options">
                    <label class="investor-match-option">
                        <input type="checkbox" name="deal_subject[]" value="100% долей/акций юрлица">
                        <span class="investor-match-option-card">100% долей/акций юрлица</span>
                    </label>
                    <label class="investor-match-option">
                        <input type="checkbox" name="deal_subject[]" value="Доля менее 100%">
                        <span class="investor-match-option-card">Доля менее 100%</span>
                    </label>
                    <label class="investor-match-option">
                        <input type="checkbox" name="deal_subject[]" value="Имущественный комплекс">
                        <span class="investor-match-option-card">Имущественный комплекс</span>
                    </label>
                </div>
            </fieldset>

            <fieldset class="investor-match-field">
                <label>3. Материальные активы (можно выбрать несколько)</label>
                <div class="investor-match-options">
                    <label class="investor-match-option">
                        <input type="checkbox" name="assets[]" value="Земля/производственные помещения в собственности">
                        <span class="investor-match-option-card">Земля/производственные помещения в собственности</span>
                    </label>
                    <label class="investor-match-option">
                        <input type="checkbox" name="assets[]" value="Оборудование/станки/спецтехника в собственности">
                        <span class="investor-match-option-card">Оборудование/станки/спецтехника в собственности</span>
                    </label>
                    <label class="investor-match-option">
                        <input type="checkbox" name="assets[]" value="Долгосрочная аренда помещений/мощностей">
                        <span class="investor-match-option-card">Долгосрочная аренда помещений/мощностей</span>
                    </label>
                    <label class="investor-match-option">
                        <input type="checkbox" name="assets[]" value="Только офис и оргтехника">
                        <span class="investor-match-option-card">Только офис и оргтехника</span>
                    </label>
                    <label class="investor-match-option">
                        <input type="checkbox" name="assets[]" value="Нет материальных активов">
                        <span class="investor-match-option-card">Нет материальных активов</span>
                    </label>
                </div>
            </fieldset>

            <div class="investor-match-field">
                <label for="offer">4. Какой продукт или услугу продает бизнес?</label>
                <textarea id="offer" name="offer" required placeholder="Опишите в 1-3 предложениях, что продаете, кому и чем отличаетесь"></textarea>
            </div>

            <div class="investor-match-field">
                <label for="website">Ссылка на сайт (если есть)</label>
                <input id="website" name="website" type="text" placeholder="https://example.ru">
            </div>

            <div class="investor-match-field">
                <label for="region">Регион присутствия</label>
                <input id="region" name="region" type="text" placeholder="Например: Москва и МО">
            </div>

            <div class="investor-match-field">
                <label for="revenue">Выручка за последний фактический период</label>
                <input id="revenue" name="revenue" type="text" placeholder="Например: 180 млн ₽">
            </div>

            <div class="investor-match-actions">
                <button class="btn btn-primary" type="submit" id="investor-match-submit">Отправить</button>
            </div>
            <div id="investor-submit-progress" class="investor-submit-progress">
                <div class="investor-match-progress-head">
                    <span id="investor-submit-progress-label">Обработка анкеты...</span>
                    <span id="investor-submit-progress-percent">0%</span>
                </div>
                <div class="investor-match-progress-track">
                    <div id="investor-submit-progress-bar" class="investor-match-progress-fill"></div>
                </div>
            </div>
            <div id="investor-match-error" class="investor-match-error" aria-live="polite"></div>
        </form>
    </section>

    <section id="investor-match-result" class="investor-match-result">
        <div class="investor-result-block">
            <div class="investor-result-kicker">AI summary</div>
            <h2>Короткое описание бизнеса</h2>
            <div id="investor-summary" class="investor-summary"></div>
        </div>
        <div class="investor-result-block">
            <div class="investor-result-kicker">Top-5</div>
            <h2>ТОП-5 потенциальных инвесторов</h2>
            <p class="investor-top-note">Подбор выполнен по краткому профилю бизнеса и релевантности к каталогу инвесторов SmartBizSell.</p>
            <div id="investor-cards"></div>
        </div>
        <div class="investor-match-final-note">
            Для более качественного подбора зарегистрируйтесь и заполните расширенную анкету продавца в личном кабинете SmartBizSell.
            <a href="/register.php">Зарегистрироваться</a>
        </div>
    </section>
</div>

<script>
    (function () {
        const navToggle = document.querySelector('.nav-toggle');
        const navMenu = document.querySelector('.nav-menu');
        if (navToggle && navMenu) {
            navToggle.addEventListener('click', function () {
                navMenu.classList.toggle('active');
            });
        }

        const form = document.getElementById('investor-match-form');
        const submitBtn = document.getElementById('investor-match-submit');
        const errorEl = document.getElementById('investor-match-error');
        const resultEl = document.getElementById('investor-match-result');
        const summaryEl = document.getElementById('investor-summary');
        const cardsEl = document.getElementById('investor-cards');
        const fillBar = document.getElementById('investor-fill-progress-bar');
        const fillLabel = document.getElementById('investor-fill-progress-label');
        const fillPercent = document.getElementById('investor-fill-progress-percent');
        const submitProgress = document.getElementById('investor-submit-progress');
        const submitBar = document.getElementById('investor-submit-progress-bar');
        const submitPercent = document.getElementById('investor-submit-progress-percent');
        const submitLabel = document.getElementById('investor-submit-progress-label');

        function collectCheckboxValues(name) {
            return Array.from(document.querySelectorAll('input[name="' + name + '"]:checked')).map((el) => el.value);
        }

        function calculateFillProgress() {
            const answers = [
                document.getElementById('inn').value.trim() !== '',
                collectCheckboxValues('deal_subject[]').length > 0,
                collectCheckboxValues('assets[]').length > 0,
                document.getElementById('offer').value.trim() !== '',
                document.getElementById('website').value.trim() !== '',
                document.getElementById('region').value.trim() !== '',
                document.getElementById('revenue').value.trim() !== ''
            ];

            const total = answers.length;
            const filled = answers.filter(Boolean).length;
            const percent = Math.round((filled / total) * 100);
            return {filled, total, percent};
        }

        function updateFillProgress() {
            const progress = calculateFillProgress();
            fillBar.style.width = progress.percent + '%';
            fillLabel.textContent = 'Заполнено ' + progress.filled + ' из ' + progress.total + ' вопросов';
            fillPercent.textContent = progress.percent + '%';
        }

        function setSubmitProgress(percent, text) {
            submitBar.style.width = percent + '%';
            submitPercent.textContent = percent + '%';
            if (text) {
                submitLabel.textContent = text;
            }
        }

        form.addEventListener('input', updateFillProgress);
        form.addEventListener('change', updateFillProgress);
        updateFillProgress();

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            errorEl.textContent = '';
            resultEl.classList.remove('active');
            submitProgress.classList.add('active');
            setSubmitProgress(8, 'Проверяем данные анкеты...');

            const payload = {
                inn: document.getElementById('inn').value.trim(),
                deal_subject: collectCheckboxValues('deal_subject[]'),
                assets: collectCheckboxValues('assets[]'),
                offer: document.getElementById('offer').value.trim(),
                website: document.getElementById('website').value.trim(),
                region: document.getElementById('region').value.trim(),
                revenue: document.getElementById('revenue').value.trim()
            };

            submitBtn.disabled = true;
            submitBtn.textContent = 'Подбираем...';
            setSubmitProgress(24, 'Формируем профиль компании...');

            try {
                const response = await fetch('/public_investor_match.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                setSubmitProgress(68, 'Ищем подходящих инвесторов...');

                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Не удалось обработать анкету.');
                }
                setSubmitProgress(100, 'Готово');

                summaryEl.textContent = data.summary || 'Описание не сформировано.';
                cardsEl.innerHTML = data.html || '';
                resultEl.classList.add('active');
                resultEl.scrollIntoView({behavior: 'smooth', block: 'start'});
            } catch (error) {
                errorEl.textContent = error.message || 'Ошибка при подборе инвесторов.';
                setSubmitProgress(100, 'Ошибка обработки');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Отправить';
                setTimeout(function () {
                    submitProgress.classList.remove('active');
                }, 900);
            }
        });
    })();
</script>
</body>
</html>
