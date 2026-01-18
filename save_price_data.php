<?php
/**
 * save_price_data.php
 *
 * API-эндпоинт для сохранения финальной цены продажи и результатов расчета мультипликатора.
 * 
 * Принимает JSON-запросы с полями:
 * - final_price: финальная цена продажи (float, опционально)
 * - multiplier_valuation: результаты расчета мультипликатора (array, опционально)
 * 
 * Сохраняет данные в поле data_json таблицы seller_forms.
 * Все данные сохраняются в формате JSON с метаданными (дата/время обновления).
 * 
 * Использование:
 * POST запрос с Content-Type: application/json
 * Требуется авторизация (пользователь должен быть залогинен)
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

// Устанавливаем заголовок для JSON-ответа
// UTF-8 кодировка для корректного отображения русских символов
header('Content-Type: application/json; charset=utf-8');

// Проверка авторизации пользователя
// API доступно только для авторизованных пользователей
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Необходима авторизация.']);
    exit;
}

// Получение данных текущего пользователя
// Проверяем валидность сессии
$user = getCurrentUser();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Сессия недействительна.']);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Используем getEffectiveUserId() для поддержки режима импersonation (модератор работает от имени клиента)
    $effectiveUserId = getEffectiveUserId();
    
    // Получаем данные из JSON-тела запроса
    // file_get_contents('php://input') читает сырые данные POST-запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Если передан form_id, используем его, иначе выбираем последнюю форму
    if (isset($input['form_id']) && $input['form_id'] > 0) {
        // Используем конкретную форму, указанную в запросе
        $stmt = $pdo->prepare("
            SELECT *
            FROM seller_forms
            WHERE id = ? AND user_id = ?
              AND status IN ('submitted','review','approved')
        ");
        $stmt->execute([$input['form_id'], $effectiveUserId]);
        $form = $stmt->fetch();
        error_log('save_price_data.php: Looking for specific form_id=' . $input['form_id'] . ' for user_id=' . $effectiveUserId);
        error_log('save_price_data.php: Found form_id=' . ($form['id'] ?? 'NULL') . ' for user_id=' . $effectiveUserId . ', status=' . ($form['status'] ?? 'NULL'));
    } else {
        // Получаем последнюю отправленную анкету продавца (старое поведение для обратной совместимости)
        $stmt = $pdo->prepare("
            SELECT *
            FROM seller_forms
            WHERE user_id = ?
              AND status IN ('submitted','review','approved')
            ORDER BY submitted_at DESC, updated_at DESC
            LIMIT 1
        ");
        $stmt->execute([$effectiveUserId]);
        $form = $stmt->fetch();
        error_log('save_price_data.php: Looking for last form for user_id=' . $effectiveUserId);
        error_log('save_price_data.php: Found form_id=' . ($form['id'] ?? 'NULL') . ' for user_id=' . $effectiveUserId . ', status=' . ($form['status'] ?? 'NULL'));
    }

    // Если анкета не найдена, возвращаем ошибку
    if (!$form) {
        error_log('save_price_data.php: No form found for user_id=' . $effectiveUserId . ', requested_form_id=' . ($input['form_id'] ?? 'NULL'));
        echo json_encode(['success' => false, 'message' => 'Нет отправленных анкет.']);
        exit;
    }
    
    // Извлекаем текущие данные из data_json (если они есть)
    // data_json содержит все дополнительные данные формы в формате JSON
    // Это позволяет сохранять новые данные, не теряя существующие
    $dataJson = !empty($form['data_json']) ? json_decode($form['data_json'], true) : [];
    if (!is_array($dataJson)) {
        $dataJson = [];
    }
    
    // Логируем текущее значение final_price в БД перед обновлением
    if (isset($dataJson['final_price'])) {
        error_log('save_price_data.php: Current final_price in DB=' . $dataJson['final_price'] . ' for form_id=' . $form['id']);
    } else {
        error_log('save_price_data.php: No final_price in DB for form_id=' . $form['id']);
    }
    
    // Сохраняем финальную цену продажи, если она передана
    // Цена сохраняется как число с плавающей точкой
    // Также сохраняем метаданные: дату и время последнего изменения
    if (isset($input['final_price']) && $input['final_price'] !== null && $input['final_price'] !== '') {
        $dataJson['final_price'] = (float)$input['final_price'];
        // Сохраняем дату и время последнего изменения цены в формате ISO 8601
        // Формат 'c' возвращает дату в формате: 2025-01-15T10:30:00+00:00
        $dataJson['final_price_updated_at'] = date('c');
        error_log('save_price_data.php: Setting final_price=' . $dataJson['final_price'] . ' for form_id=' . $form['id']);
    }
    
    // Сохраняем результаты расчета мультипликатора, если они переданы
    // Мультипликатор содержит:
    // - sector: сектор компании
    // - financial_data: финансовые данные, использованные для расчета
    // - valuation: результаты расчета оценки (equity_value, ev, ev1, ev2, applied_multipliers)
    if (isset($input['multiplier_valuation']) && is_array($input['multiplier_valuation'])) {
        $dataJson['multiplier_valuation'] = $input['multiplier_valuation'];
        // Сохраняем также время расчета для отслеживания актуальности данных
        // Это позволяет понять, когда был выполнен последний расчет
        $dataJson['multiplier_valuation']['calculated_at'] = date('c');
    }
    
    // Обновляем data_json в базе данных
    // JSON_UNESCAPED_UNICODE сохраняет кириллицу без экранирования (\uXXXX)
    // Это делает JSON более читаемым и уменьшает его размер
    $updatedDataJson = json_encode($dataJson, JSON_UNESCAPED_UNICODE);
    
    // Для модераторов разрешаем обновлять анкеты клиентов (в режиме импersonation)
    // Для обычных пользователей проверяем, что анкета принадлежит им
    if (isModerator() && isImpersonating()) {
        // Модератор работает от имени клиента - обновляем без проверки user_id
        $updateStmt = $pdo->prepare("
            UPDATE seller_forms 
            SET data_json = ?, 
                updated_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$updatedDataJson, $form['id']]);
        $rowsAffected = $updateStmt->rowCount();
        error_log('save_price_data.php: UPDATE executed (moderator mode) for form_id=' . $form['id'] . ', rows_affected=' . $rowsAffected . ', final_price=' . ($dataJson['final_price'] ?? 'NOT SET'));
        // rowCount() может вернуть 0, если данные не изменились (MySQL behavior)
        // Поэтому не выбрасываем исключение, а просто логируем предупреждение
        if ($rowsAffected === 0) {
            error_log('save_price_data.php: WARNING - No rows affected! This may be normal if data_json did not change. form_id=' . $form['id']);
        }
    } else {
        // Обычный пользователь - проверяем, что анкета принадлежит ему
        $updateStmt = $pdo->prepare("
            UPDATE seller_forms 
            SET data_json = ?, 
                updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        $updateStmt->execute([$updatedDataJson, $form['id'], $effectiveUserId]);
        $rowsAffected = $updateStmt->rowCount();
        error_log('save_price_data.php: UPDATE executed for form_id=' . $form['id'] . ', user_id=' . $effectiveUserId . ', rows_affected=' . $rowsAffected . ', final_price=' . ($dataJson['final_price'] ?? 'NOT SET'));
        // rowCount() может вернуть 0, если данные не изменились (MySQL behavior)
        // Поэтому не выбрасываем исключение, а просто логируем предупреждение
        if ($rowsAffected === 0) {
            error_log('save_price_data.php: WARNING - No rows affected! This may be normal if data_json did not change. form_id=' . $form['id'] . ', effectiveUserId=' . $effectiveUserId);
        }
    }
    
    // Формируем успешный ответ с дополнительной информацией
    $response = [
        'success' => true,
        'message' => 'Данные успешно сохранены.',
    ];
    
    // Добавляем время обновления цены в ответ, если цена была сохранена
    // Это позволяет клиенту отобразить актуальную информацию о времени изменения
    if (isset($input['final_price']) && $input['final_price'] !== null && $input['final_price'] !== '') {
        $response['final_price_updated_at'] = $dataJson['final_price_updated_at'] ?? date('c');
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Логируем ошибку для отладки
    // В production окружении это поможет выявить проблемы
    error_log('Save price data error: ' . $e->getMessage());
    
    // Возвращаем ошибку клиенту
    // HTTP 500 - внутренняя ошибка сервера
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка при сохранении данных: ' . $e->getMessage()]);
}

