<?php
/**
 * Экспорт данных анкеты в формате JSON
 * 
 * Функциональность:
 * - Загрузка данных анкеты пользователя
 * - Приоритетное использование data_json (новый формат)
 * - Fallback на отдельные поля БД (старый формат)
 * - Формирование имени файла на основе названия актива и даты
 * - Отдача файла для скачивания с правильными HTTP-заголовками
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

if (!isLoggedIn()) {
    redirectToLogin();
}

$user = getCurrentUser();
if (!$user) {
    session_destroy();
    redirectToLogin();
}

// Получение ID анкеты из GET-параметра
$formId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($formId <= 0) {
    http_response_code(400);
    die(json_encode(['error' => 'Не указан ID анкеты']));
}

// Загрузка анкеты из базы данных
$pdo = getDBConnection();
$stmt = $pdo->prepare("SELECT * FROM seller_forms WHERE id = ? AND user_id = ?");
$stmt->execute([$formId, $user['id']]);
$form = $stmt->fetch();

if (!$form) {
    http_response_code(404);
    die(json_encode(['error' => 'Анкета не найдена']));
}

// Собираем полные данные анкеты
$formData = [];

// Приоритет: если есть data_json (новый формат), используем его как основу
// data_json содержит полную структуру формы в едином формате
if (!empty($form['data_json'])) {
    $decoded = json_decode($form['data_json'], true);
    if (is_array($decoded)) {
        $formData = $decoded;
    }
}

// Добавляем метаданные из базы (ID, статус, даты создания/обновления)
$formData['_metadata'] = [
    'id' => $form['id'],
    'user_id' => $form['user_id'],
    'status' => $form['status'],
    'created_at' => $form['created_at'],
    'updated_at' => $form['updated_at'],
    'submitted_at' => $form['submitted_at'],
];

// Fallback: если data_json пустой или содержит только метаданные,
// собираем данные из отдельных полей БД (старый формат)
// Это обеспечивает совместимость со старыми анкетами
if (empty($formData) || (count($formData) === 1 && isset($formData['_metadata']))) {
    $formData = [
        'asset_name' => $form['asset_name'] ?? '',
        'deal_share_range' => $form['deal_subject'] ?? '',
        'deal_goal' => $form['deal_purpose'] ?? '',
        'asset_disclosure' => $form['asset_disclosure'] ?? '',
        'company_description' => $form['company_description'] ?? '',
        'presence_regions' => $form['presence_regions'] ?? '',
        'products_services' => $form['products_services'] ?? '',
        'company_brands' => $form['company_brands'] ?? '',
        'own_production' => $form['own_production'] ?? '',
        'production_sites_count' => $form['production_sites_count'] ?? '',
        'production_sites_region' => $form['production_sites_region'] ?? '',
        'production_area' => $form['production_area'] ?? '',
        'production_capacity' => $form['production_capacity'] ?? '',
        'production_load' => $form['production_load'] ?? '',
        'production_building_ownership' => $form['production_building_ownership'] ?? '',
        'production_land_ownership' => $form['production_land_ownership'] ?? '',
        'contract_production_usage' => $form['contract_production_usage'] ?? '',
        'contract_production_region' => $form['contract_production_region'] ?? '',
        'contract_production_logistics' => $form['contract_production_logistics'] ?? '',
        'offline_sales_presence' => $form['offline_sales_presence'] ?? '',
        'offline_sales_points' => $form['offline_sales_points'] ?? '',
        'offline_sales_regions' => $form['offline_sales_regions'] ?? '',
        'offline_sales_area' => $form['offline_sales_area'] ?? '',
        'offline_sales_third_party' => $form['offline_sales_third_party'] ?? '',
        'offline_sales_distributors' => $form['offline_sales_distributors'] ?? '',
        'online_sales_presence' => $form['online_sales_presence'] ?? '',
        'online_sales_share' => $form['online_sales_share'] ?? '',
        'online_sales_channels' => $form['online_sales_channels'] ?? '',
        'main_clients' => $form['main_clients'] ?? '',
        'sales_share' => $form['sales_share'] ?? '',
        'personnel_count' => $form['personnel_count'] ?? '',
        'company_website' => $form['company_website'] ?? '',
        'additional_info' => $form['additional_info'] ?? '',
        'financial_results_vat' => $form['financial_results_vat'] ?? '',
        'financial_source' => $form['financial_source'] ?? '',
        '_metadata' => $formData['_metadata'] ?? [
            'id' => $form['id'],
            'user_id' => $form['user_id'],
            'status' => $form['status'],
            'created_at' => $form['created_at'],
            'updated_at' => $form['updated_at'],
            'submitted_at' => $form['submitted_at'],
        ],
    ];

    // Добавляем динамические таблицы из JSON-полей БД
    if (!empty($form['production_volumes'])) {
        $formData['production'] = json_decode($form['production_volumes'], true) ?: [];
    }
    if (!empty($form['financial_results'])) {
        $formData['financial'] = json_decode($form['financial_results'], true) ?: [];
    }
    if (!empty($form['balance_indicators'])) {
        $formData['balance'] = json_decode($form['balance_indicators'], true) ?: [];
    }
}

// Формируем безопасное имя файла для скачивания
// Формат: anketa_{название_актива}_{дата}.json
$assetName = $form['asset_name'] ?: 'form';
$assetName = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ_-]/u', '_', $assetName); // Удаляем недопустимые символы
$assetName = mb_substr($assetName, 0, 50); // Ограничиваем длину
$dateLabel = date('Y-m-d', strtotime($form['updated_at'] ?: $form['created_at']));
$fileName = "anketa_{$assetName}_{$dateLabel}.json";

// Устанавливаем HTTP-заголовки для скачивания файла
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
header('Pragma: public');

// Выводим JSON с красивым форматированием (отступы, Unicode, слэши)
echo json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit;
