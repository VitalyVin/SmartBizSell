<?php
/**
 * Лендинг «Оценка компании» — минимальная анкета для ориентировочной оценки по DCF и мультипликаторам.
 * Без регистрации, без сохранения в БД. Результат можно поделиться в Telegram, VK, Facebook.
 */
require_once 'config.php';

$pageTitle = 'Оценка компании за 2 минуты | SmartBizSell';
$pageDescription = 'Узнайте ориентировочную стоимость компании. Введите минимум данных — получите диапазон оценки по методу DCF и мультипликаторов. Без регистрации.';
$canonicalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . strtok($_SERVER['REQUEST_URI'] ?? '/estimate.php', '?');
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/yandex_metrika.php'; ?>
    <style>
        .estimate-page { max-width: 720px; margin: 0 auto; padding: 24px 20px 60px; }
        .estimate-hero { text-align: center; margin-bottom: 40px; }
        .estimate-hero h1 { font-size: 32px; font-weight: 800; margin-bottom: 16px; background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .estimate-hero p { font-size: 17px; color: var(--text-secondary, #64748b); line-height: 1.6; }
        .estimate-form { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); padding: 32px; margin-bottom: 32px; }
        .estimate-form .form-group { margin-bottom: 20px; }
        .estimate-form label { display: block; font-weight: 600; margin-bottom: 8px; color: #1e293b; }
        .estimate-form input[type="number"], .estimate-form input[type="text"], .estimate-form textarea, .estimate-form select { width: 100%; padding: 12px 14px; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 16px; }
        .estimate-form textarea { min-height: 80px; resize: vertical; }
        .estimate-form .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        @media (max-width: 600px) { .estimate-form .form-row { grid-template-columns: 1fr; } }
        .estimate-form .radio-group { display: flex; gap: 20px; flex-wrap: wrap; }
        .estimate-form .radio-group label { display: flex; align-items: center; gap: 8px; font-weight: 400; cursor: pointer; }
        .estimate-form .radio-group input { width: auto; }
        .estimate-submit { width: 100%; padding: 16px 24px; font-size: 18px; font-weight: 600; border: none; border-radius: 12px; background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: #fff; cursor: pointer; margin-top: 8px; }
        .estimate-submit:hover { opacity: 0.95; }
        .estimate-submit:disabled { opacity: 0.7; cursor: not-allowed; }
        .estimate-loading { display: none; text-align: center; padding: 24px; color: #64748b; }
        .estimate-loading.visible { display: block; }
        .estimate-result { display: none; background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border-radius: 16px; padding: 32px; margin-bottom: 24px; border: 1px solid #bae6fd; }
        .estimate-result.visible { display: block; }
        .estimate-result h2 { font-size: 22px; font-weight: 700; margin-bottom: 16px; color: #0c4a6e; }
        .estimate-result .range { font-size: 28px; font-weight: 800; color: #0369a1; margin-bottom: 12px; }
        .estimate-result .details { font-size: 14px; color: #64748b; margin-bottom: 24px; }
        .estimate-share { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
        .estimate-share span { font-weight: 600; color: #475569; margin-right: 8px; }
        .estimate-share a { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-weight: 500; text-decoration: none; color: #fff; }
        .estimate-share a.telegram { background: #0088cc; }
        .estimate-share a.vk { background: #0077ff; }
        .estimate-share a.facebook { background: #1877f2; }
        .estimate-share a:hover { opacity: 0.9; }
        .estimate-error { display: none; padding: 16px; background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; color: #b91c1c; margin-bottom: 24px; }
        .estimate-error.visible { display: block; }
        .estimate-back { display: inline-block; margin-bottom: 24px; color: #667EEA; font-weight: 500; text-decoration: none; }
        .estimate-back:hover { text-decoration: underline; }
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
                    <li><a href="/estimate.php" style="background: linear-gradient(135deg, #667EEA 0%, #764BA2 100%); color: white; padding: 8px 16px; border-radius: 8px;">Оценить бизнес</a></li>
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

    <main class="estimate-page">
        <a href="/" class="estimate-back">← На главную</a>

        <section class="estimate-hero">
            <h1>Оценка компании за 2 минуты</h1>
            <p>Узнайте ориентировочную стоимость компании. Введите минимум данных — получите диапазон оценки по методу DCF и мультипликаторов. Без регистрации и без сохранения данных.</p>
        </section>

        <div id="estimate-error" class="estimate-error" role="alert"></div>
        <div id="estimate-loading" class="estimate-loading">Считаем оценку…</div>

        <form id="estimate-form" class="estimate-form">
            <div class="form-group">
                <label for="activity_description">Отрасль / вид деятельности (1–2 предложения)</label>
                <textarea id="activity_description" name="activity_description" placeholder="Например: производство мебели, B2B продажи в регионах России" maxlength="500"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="revenue_last">Выручка за последний год *</label>
                    <input type="number" id="revenue_last" name="revenue_last" min="0" step="0.01" required placeholder="Например: 150">
                </div>
                <div class="form-group">
                    <label for="unit">Единицы измерения</label>
                    <select id="unit" name="unit">
                        <option value="million">млн ₽</option>
                        <option value="thousand">тыс. ₽</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="profit_last">Прибыль от продаж за последний год</label>
                    <input type="number" id="profit_last" name="profit_last" min="0" step="0.01" placeholder="Опционально">
                </div>
                <div class="form-group">
                    <label for="margin_pct">Или маржа, %</label>
                    <input type="number" id="margin_pct" name="margin_pct" min="0" max="100" step="0.1" placeholder="Например: 15">
                </div>
            </div>
            <div class="form-group">
                <label>НДС в отчётности</label>
                <div class="radio-group">
                    <label><input type="radio" name="vat" value="with_vat" checked> с НДС</label>
                    <label><input type="radio" name="vat" value="without_vat"> без НДС</label>
                </div>
            </div>
            <button type="submit" class="estimate-submit" id="estimate-submit">Рассчитать оценку</button>
        </form>

        <div id="estimate-result" class="estimate-result">
            <h2>Ориентировочный диапазон оценки</h2>
            <div class="range" id="estimate-range">—</div>
            <div class="details" id="estimate-details"></div>
            <div class="estimate-share">
                <span>Поделиться:</span>
                <a href="#" id="share-telegram" class="telegram" target="_blank" rel="noopener">Telegram</a>
                <a href="#" id="share-vk" class="vk" target="_blank" rel="noopener">VK</a>
                <a href="#" id="share-facebook" class="facebook" target="_blank" rel="noopener">Facebook</a>
            </div>
        </div>
    </main>

    <script>
(function() {
    var form = document.getElementById('estimate-form');
    var submitBtn = document.getElementById('estimate-submit');
    var loading = document.getElementById('estimate-loading');
    var resultBlock = document.getElementById('estimate-result');
    var errorBlock = document.getElementById('estimate-error');
    var rangeEl = document.getElementById('estimate-range');
    var detailsEl = document.getElementById('estimate-details');
    var shareTelegram = document.getElementById('share-telegram');
    var shareVk = document.getElementById('share-vk');
    var shareFacebook = document.getElementById('share-facebook');

    var pageUrl = '<?php echo addslashes($canonicalUrl); ?>';

    function hideAll() {
        loading.classList.remove('visible');
        resultBlock.classList.remove('visible');
        errorBlock.classList.remove('visible');
    }

    function setError(msg) {
        hideAll();
        errorBlock.textContent = msg || 'Ошибка при расчёте.';
        errorBlock.classList.add('visible');
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var revenue = parseFloat(document.getElementById('revenue_last').value, 10);
        if (!revenue || revenue <= 0) {
            setError('Укажите выручку за последний год.');
            return;
        }
        hideAll();
        loading.classList.add('visible');
        submitBtn.disabled = true;

        var payload = {
            activity_description: (document.getElementById('activity_description').value || '').trim(),
            revenue_last: revenue,
            unit: document.getElementById('unit').value,
            vat: document.querySelector('input[name="vat"]:checked').value
        };
        var profit = document.getElementById('profit_last').value.trim();
        var margin = document.getElementById('margin_pct').value.trim();
        if (profit !== '') payload.profit_last = parseFloat(profit, 10);
        if (margin !== '') payload.margin_pct = parseFloat(margin, 10);

        fetch('/api/estimate_valuation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            loading.classList.remove('visible');
            submitBtn.disabled = false;
            if (data.success) {
                var min = data.range.min;
                var max = data.range.max;
                rangeEl.textContent = 'от ' + min + ' до ' + max + ' млн ₽';
                var details = [];
                if (data.dcf_mln != null) details.push('DCF: ' + data.dcf_mln + ' млн ₽');
                if (data.multiplier_mln != null) details.push('Мультипликаторы: ' + data.multiplier_mln + ' млн ₽');
                if (data.sector) details.push('Сектор: ' + data.sector);
                detailsEl.textContent = details.join(' • ');
                var shareText = 'Ориентировочная оценка моей компании: от ' + min + ' до ' + max + ' млн ₽ (SmartBizSell)';
                shareTelegram.href = 'https://t.me/share/url?url=' + encodeURIComponent(pageUrl) + '&text=' + encodeURIComponent(shareText);
                shareVk.href = 'https://vk.com/share.php?url=' + encodeURIComponent(pageUrl) + '&title=' + encodeURIComponent(shareText);
                shareFacebook.href = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(pageUrl);
                resultBlock.classList.add('visible');
                resultBlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                setError(data.error || 'Не удалось рассчитать оценку.');
            }
        })
        .catch(function() {
            loading.classList.remove('visible');
            submitBtn.disabled = false;
            setError('Ошибка сети или сервера. Попробуйте позже.');
        });
    });
})();
    </script>
</body>
</html>
