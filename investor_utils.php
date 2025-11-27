<?php
/**
 * Набор вспомогательных функций для подбора и отображения инвесторов.
 * Выделен в отдельный файл, чтобы generate_teaser.php оставался компактным.
 */

function renderInvestorSection(array $investors): string
{
    $cards = array_map('renderInvestorCard', $investors);
    $headline = '<div class="investor-section__intro">'
        . '<div>'
        . '<h3>Возможные инвесторы</h3>'
        . '<p>Комбинация релевантных контактов из базы SmartBizSell и свежих рекомендаций AI.</p>'
        . '</div>'
        . '<span class="investor-section__count">' . count($investors) . ' из 10</span>'
        . '</div>';

    return '<section class="investor-section">' . $headline . '<div class="investor-grid">' . implode('', $cards) . '</div></section>';
}

function renderInvestorCard(array $investor): string
{
    $name = escapeHtml($investor['name'] ?? 'Инвестор');
    $focus = escapeHtml($investor['focus'] ?? 'Область интересов уточняется');
    $check = escapeHtml($investor['check'] ?? '');
    $reason = escapeHtml($investor['reason'] ?? '');
    $source = $investor['source'] ?? 'catalog';
    $badge = $source === 'ai' ? '<span class="investor-card__badge">AI рекомендация</span>' : '';
    $checkHtml = $check !== '' ? '<p class="investor-card__check">Целевой чек: ' . $check . '</p>' : '';
    $reasonHtml = $reason !== '' ? '<p class="investor-card__reason">' . $reason . '</p>' : '';

    $button = '<button type="button" class="btn btn-investor-send" data-investor="' . $name . '">Отправить тизер</button>';

    return <<<HTML
<div class="investor-card" data-source="{$source}">
    <div class="investor-card__head">
        <div>
            <h4>{$name}</h4>
            {$badge}
        </div>
    </div>
    <p class="investor-card__focus">{$focus}</p>
    {$checkHtml}
    {$reasonHtml}
    <div class="investor-card__actions">
        {$button}
    </div>
</div>
HTML;
}

function buildInvestorPool(array $payload, string $apiKey): array
{
    $catalogPath = __DIR__ . '/rag_investors.xlsx';
    $catalog = loadRagInvestors($catalogPath);
    if (empty($catalog)) {
        return [];
    }

    $ranked = rankInvestorsByRelevance($catalog, $payload);
    $selected = array_slice($ranked, 0, 6);
    $aiSuggestions = requestAiInvestorSuggestions($payload, $catalog, $apiKey, 4);

    $combined = array_merge($selected, $aiSuggestions);
    $unique = [];
    $seen = [];
    foreach ($combined as $row) {
        $name = mb_strtolower(trim($row['name'] ?? ''));
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        unset($row['score']);
        $unique[] = $row;
        if (count($unique) >= 10) {
            break;
        }
    }

    return $unique;
}

function rankInvestorsByRelevance(array $investors, array $payload): array
{
    $keywords = buildAssetKeywords($payload);
    $results = [];

    foreach ($investors as $item) {
        $name = trim($item['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $focus = trim($item['focus'] ?? '');
        $check = trim($item['check'] ?? '');

        $haystack = mb_strtolower($name . ' ' . $focus . ' ' . $check);
        $score = 0;
        $matched = [];

        foreach ($keywords as $keyword) {
            if ($keyword === '' || mb_strlen($keyword) < 3) {
                continue;
            }
            if (str_contains($haystack, $keyword)) {
                $score += 3;
                $matched[] = $keyword;
            }
        }

        if ($focus !== '') {
            $score += 1;
        }
        if ($check !== '') {
            $score += 0.5;
        }

        $industry = mb_strtolower(trim((string)($payload['products_services'] ?? '')));
        if ($industry !== '' && str_contains(mb_strtolower($focus), $industry)) {
            $score += 2;
        }

        $results[] = [
            'source' => 'catalog',
            'name' => $name,
            'focus' => $focus,
            'check' => $check,
            'reason' => formatInvestorReason($focus, $check, $matched, $payload),
            'score' => $score,
        ];
    }

    usort($results, static function ($a, $b) {
        return $b['score'] <=> $a['score'] ?: strcmp($a['name'], $b['name']);
    });

    return $results;
}

function buildAssetKeywords(array $payload): array
{
    $fields = [
        $payload['asset_name'] ?? '',
        $payload['products_services'] ?? '',
        $payload['company_description'] ?? '',
        $payload['presence_regions'] ?? '',
        $payload['additional_info'] ?? '',
        $payload['deal_goal'] ?? '',
        $payload['industry'] ?? '',
    ];

    $stopWords = [
        'компания','бизнес','продажа','рост','рынок','сектор','сегмент','команда','клиент',
        'инвестиции','инвестор','группа','россия','rf','поддержка','услуги','продукт','решение',
        'сделка','капитал','логистика','работа','новый','текущий','развитие','масштабирование'
    ];
    $keywords = [];
    foreach ($fields as $field) {
        $words = preg_split('/[^а-яa-z0-9]+/iu', mb_strtolower((string)$field));
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '' || mb_strlen($word) < 3) {
                continue;
            }
            if (in_array($word, $stopWords, true)) {
                continue;
            }
            $keywords[] = $word;
        }
    }

    return array_values(array_unique($keywords));
}

function requestAiInvestorSuggestions(array $payload, array $catalog, string $apiKey, int $limit = 3): array
{
    if (empty($apiKey)) {
        return [];
    }
    $limit = max(1, min($limit, 5));
    $assetSummary = buildAssetSummaryForInvestors($payload);
    $catalogExcerpt = buildInvestorCatalogExcerpt($catalog, 120);
    $namesList = implode(', ', array_map(static fn ($row) => $row['name'] ?? '', $catalog));

    $prompt = <<<PROMPT
Ты — инвестиционный банкир SmartBizSell. На основании анкеты продавца и каталога инвесторов предложи до {$limit} новых стратегических покупателей, КОТОРЫХ НЕТ в каталоге. Ориентируйся на отрасль, масштаб и стратегию компании.

Каталог инвесторов (фрагмент, уже учтён в системе — повторять имена нельзя):
{$catalogExcerpt}

Названия всех существующих инвесторов (для исключения дублей):
{$namesList}

Профиль компании:
{$assetSummary}

Требования:
- предлагай только реальных инвесторов (корпорации, фонды, private equity) с понятной мотивацией;
- каждая рекомендация должна содержать название, фокус интересов и короткую причину релевантности;
- не придумывай инвесторов из каталога и не используй абстрактные формулировки вроде «частный инвестор».

Ответ в формате JSON-массива:
[
  {"name": "...", "focus": "...", "rationale": "..."},
  ...
]
PROMPT;

    try {
        $raw = callTogetherCompletions($prompt, $apiKey);
    } catch (Throwable $e) {
        error_log('AI investor suggestion failed: ' . $e->getMessage());
        return [];
    }

    $clean = trim(sanitizeAiArtifacts($raw));
    $json = json_decode($clean, true);
    if (!is_array($json)) {
        return [];
    }

    $knownNames = array_map(static fn ($name) => mb_strtolower(trim($name)), array_column($catalog, 'name'));
    $knownMap = array_flip($knownNames);
    $suggestions = [];

    foreach ($json as $row) {
        if (!is_array($row)) {
            continue;
        }
        $name = trim((string)($row['name'] ?? ''));
        $focus = trim((string)($row['focus'] ?? ''));
        $reason = trim((string)($row['rationale'] ?? ''));
        if ($name === '') {
            continue;
        }
        if (isset($knownMap[mb_strtolower($name)])) {
            continue;
        }
        $suggestions[] = [
            'source' => 'ai',
            'name' => $name,
            'focus' => $focus !== '' ? $focus : 'Сферы уточняются',
            'check' => '',
            'reason' => $reason !== '' ? $reason : 'AI-рекомендация на основе профиля компании.',
        ];
        if (count($suggestions) >= $limit) {
            break;
        }
    }

    return $suggestions;
}

function buildInvestorCatalogExcerpt(array $catalog, int $limit = 120): string
{
    $lines = [];
    foreach (array_slice($catalog, 0, $limit) as $row) {
        $name = trim($row['name'] ?? '');
        $focus = trim($row['focus'] ?? '');
        $check = trim($row['check'] ?? '');
        if ($name === '') {
            continue;
        }
        $line = "{$name} — {$focus}";
        if ($check !== '') {
            $line .= " (чек: {$check})";
        }
        $lines[] = $line;
    }
    if (count($catalog) > $limit) {
        $lines[] = '... (список сокращён для промпта)';
    }
    return implode("\n", $lines);
}

function buildAssetSummaryForInvestors(array $payload): string
{
    $parts = [];
    $asset = trim((string)($payload['asset_name'] ?? 'Компания'));
    $industry = trim((string)($payload['products_services'] ?? ''));
    $regions = trim((string)($payload['presence_regions'] ?? ''));
    $revenue = '';
    if (!empty($payload['financial']['revenue']['2024_fact'])) {
        $revenue = (string)$payload['financial']['revenue']['2024_fact'];
    }

    $parts[] = "{$asset} — {$industry} (если поле пустое, отрасль уточняется).";
    if ($regions !== '') {
        $parts[] = "Региональное присутствие: {$regions}.";
    }
    if ($revenue !== '') {
        $parts[] = "Ориентир по выручке: {$revenue}.";
    }
    if (!empty($payload['deal_goal'])) {
        $parts[] = "Цель сделки: {$payload['deal_goal']}.";
    }
    if (!empty($payload['main_clients'])) {
        $parts[] = "Клиенты: {$payload['main_clients']}.";
    }

    return implode(' ', $parts);
}

function formatInvestorReason(string $focus, string $check, array $keywords, array $payload): string
{
    if (!empty($keywords)) {
        $keywords = array_unique(array_map(static fn ($word) => mb_strtolower($word), $keywords));
        $phrases = array_map(static fn ($word) => mb_convert_case($word, MB_CASE_TITLE, 'UTF-8'), array_slice($keywords, 0, 3));
        return 'Совпадает с фокусом: ' . implode(', ', $phrases) . '.';
    }
    if ($focus !== '' && $check !== '') {
        return "Интересуется сегментом «{$focus}», диапазон сделок {$check}.";
    }
    if ($focus !== '') {
        return "Работает в сегментах: {$focus}.";
    }
    return 'Инвестор из каталога SmartBizSell с подходящим профилем сделок.';
}

function loadRagInvestors(string $path): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!is_file($path)) {
        return $cache = [];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return $cache = [];
    }
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sharedXml === false || $sheetXml === false) {
        return $cache = [];
    }

    $sharedDom = new DOMDocument();
    $sheetDom = new DOMDocument();
    if (@$sharedDom->loadXML($sharedXml) === false || @$sheetDom->loadXML($sheetXml) === false) {
        return $cache = [];
    }
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    $sharedStrings = [];
    $siNodes = $sharedDom->getElementsByTagNameNS($ns, 'si');
    foreach ($siNodes as $index => $siNode) {
        $sharedStrings[$index] = trim(getSharedStringDomText($siNode));
    }

    $rows = [];
    $rowNodes = $sheetDom->getElementsByTagNameNS($ns, 'row');
    foreach ($rowNodes as $rowNode) {
        /** @var DOMElement $rowNode */
        $rowIndex = (int)$rowNode->getAttribute('r');
        if ($rowIndex <= 1) {
            continue;
        }
        $record = ['name' => '', 'focus' => '', 'check' => ''];
        foreach ($rowNode->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== 'c') {
                continue;
            }
            $ref = $child->getAttribute('r');
            if (!preg_match('/^([A-Z]+)/', $ref, $match)) {
                continue;
            }
            $column = $match[1];
            $type = $child->getAttribute('t');
            $value = '';
            foreach ($child->childNodes as $grandChild) {
                if ($grandChild instanceof DOMElement && $grandChild->localName === 'v') {
                    $value = $grandChild->textContent;
                    break;
                }
            }
            if ($type === 's') {
                $idx = (int)$value;
                $value = $sharedStrings[$idx] ?? '';
            }
            $value = trim($value);
            if ($column === 'A') {
                $record['name'] = $value;
            } elseif ($column === 'B') {
                $record['focus'] = $value;
            } elseif ($column === 'C') {
                $record['check'] = $value;
            }
        }
        if ($record['name'] !== '') {
            $rows[] = $record;
        }
    }

    return $cache = $rows;
}

function getSharedStringDomText(DOMElement $si): string
{
    $text = '';
    foreach ($si->childNodes as $child) {
        if ($child instanceof DOMElement) {
            if ($child->localName === 't') {
                $text .= $child->textContent;
            } elseif ($child->localName === 'r') {
                foreach ($child->childNodes as $runChild) {
                    if ($runChild instanceof DOMElement && $runChild->localName === 't') {
                        $text .= $runChild->textContent;
                    }
                }
            }
        } elseif ($child instanceof DOMText) {
            $text .= $child->nodeValue;
        }
    }
    return $text;
}