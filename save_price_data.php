<?php
/**
 * save_price_data.php
 *
 * Сохранение финальной цены продажи и результатов расчета мультипликатора в БД.
 * 
 * @package SmartBizSell
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
    exit;
}

$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Сессия недействительна.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Получаем последнюю отправленную анкету продавца
    $stmt = $pdo->prepare("
        SELECT *
        FROM seller_forms
        WHERE user_id = ?
          AND status IN ('submitted','review','approved')
        ORDER BY submitted_at DESC, updated_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $form = $stmt->fetch();

    if (!$form) {
        echo json_encode(['success' => false, 'message' => 'Нет отправленных анкет.']);
        exit;
    }

    // Получаем данные из запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Извлекаем текущие данные из data_json
    $dataJson = !empty($form['data_json']) ? json_decode($form['data_json'], true) : [];
    if (!is_array($dataJson)) {
        $dataJson = [];
    }
    
    // Сохраняем финальную цену продажи, если она передана
    if (isset($input['final_price']) && $input['final_price'] !== null && $input['final_price'] !== '') {
        $dataJson['final_price'] = (float)$input['final_price'];
        // Сохраняем дату и время последнего изменения
        $dataJson['final_price_updated_at'] = date('c');
    }
    
    // Сохраняем результаты расчета мультипликатора, если они переданы
    if (isset($input['multiplier_valuation']) && is_array($input['multiplier_valuation'])) {
        $dataJson['multiplier_valuation'] = $input['multiplier_valuation'];
        // Сохраняем также время расчета
        $dataJson['multiplier_valuation']['calculated_at'] = date('c');
    }
    
    // Обновляем data_json в БД
    $updatedDataJson = json_encode($dataJson, JSON_UNESCAPED_UNICODE);
    $updateStmt = $pdo->prepare("
        UPDATE seller_forms 
        SET data_json = ?, 
            updated_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $updateStmt->execute([$updatedDataJson, $form['id'], $user['id']]);
    
    // Возвращаем дату и время обновления, если была сохранена финальная цена
    $response = [
        'success' => true,
        'message' => 'Данные успешно сохранены.',
    ];
    
    if (isset($input['final_price']) && $input['final_price'] !== null && $input['final_price'] !== '') {
        $response['final_price_updated_at'] = $dataJson['final_price_updated_at'] ?? date('c');
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log('Save price data error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении данных: ' . $e->getMessage()]);
}

