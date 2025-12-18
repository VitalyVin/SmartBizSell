<?php
/**
 * Тестовый скрипт для проверки работы Alibaba Cloud Qwen 3 Max API
 * 
 * Использование:
 * php test_alibaba_qwen.php
 * 
 * Или через браузер: test_alibaba_qwen.php
 */

// Определяем, запущен ли скрипт через браузер или CLI
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Тест Alibaba Cloud Qwen 3 Max</title>';
    echo '<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}pre{background:#fff;padding:15px;border-radius:5px;overflow:auto;}</style></head><body>';
    echo '<h1>Тест Alibaba Cloud Qwen 3 Max API</h1><pre>';
}

// API ключ Alibaba Cloud
$apiKey = 'sk-bfcf015974d0414281c1d9904e5e1f12';

// Модель (пробуем разные варианты названия)
$model = 'qwen3-max'; // Сначала пробуем qwen3-max, потом qwen-max

// Base URL для Alibaba Cloud Qwen API (OpenAI-совместимый)
$baseUrl = 'https://dashscope-intl.aliyuncs.com/compatible-mode/v1';

// Возможные endpoint'ы для тестирования
$endpoints = [
    'chat_completions' => $baseUrl . '/chat/completions',
    'completions' => $baseUrl . '/completions',
    // Резервные варианты (DashScope native API)
    'text_generation' => 'https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation',
    'multimodal' => 'https://dashscope.aliyuncs.com/api/v1/services/aigc/multimodal-generation/generation',
];

// Тестовый промпт
$testPrompt = 'Напиши краткое описание компании для инвестора. Компания занимается разработкой SaaS-решений.';

echo "=== Тест Alibaba Cloud Qwen 3 Max API ===\n\n";
echo "API Key: " . substr($apiKey, 0, 10) . "...\n";
echo "Model: $model\n\n";

// Функция для тестирования endpoint
function testEndpoint($url, $apiKey, $model, $prompt, $format = 'completions') {
    echo "Тестирую endpoint: $url\n";
    echo "Формат: $format\n";
    
    // Для OpenAI-совместимого endpoint используем другой формат запроса
    $isOpenAICompatible = (strpos($url, 'compatible-mode') !== false);
    
    // Формируем тело запроса в зависимости от формата
    if ($isOpenAICompatible) {
        // OpenAI-совместимый формат (прямой формат OpenAI API, БЕЗ обертки в input)
        if ($format === 'chat_completions' || strpos($url, 'chat/completions') !== false) {
            $body = json_encode([
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'max_tokens' => 200,
                'temperature' => 0.2,
                'top_p' => 0.9,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // Completions формат (для /completions endpoint)
            $body = json_encode([
                'model' => $model,
                'prompt' => $prompt,
                'max_tokens' => 200,
                'temperature' => 0.2,
                'top_p' => 0.9,
            ], JSON_UNESCAPED_UNICODE);
        }
    } else {
        // DashScope native API использует структуру: { "model": "...", "input": {...}, "parameters": {...} }
        if ($format === 'completions') {
            // Формат text-generation (prompt-based)
            $body = json_encode([
                'model' => $model,
                'input' => [
                    'prompt' => $prompt
                ],
                'parameters' => [
                    'max_tokens' => 200,
                    'temperature' => 0.2,
                    'top_p' => 0.9,
                ]
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($format === 'chat_completions') {
            // Формат chat completions (messages-based)
            $body = json_encode([
                'model' => $model,
                'input' => [
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ]
                ],
                'parameters' => [
                    'max_tokens' => 200,
                    'temperature' => 0.2,
                    'top_p' => 0.9,
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // Альтернативный формат (простой prompt)
            $body = json_encode([
                'model' => $model,
                'input' => $prompt,
                'parameters' => [
                    'max_tokens' => 200,
                    'temperature' => 0.2,
                    'top_p' => 0.9,
                ]
            ], JSON_UNESCAPED_UNICODE);
        }
    }
    
    echo "Request body: " . substr($body, 0, 200) . "...\n";
    echo "Request body (full): " . $body . "\n";
    echo "Is OpenAI Compatible: " . ($isOpenAICompatible ? 'YES' : 'NO') . "\n";
    echo "Format: $format\n\n";
    
    // Пробуем разные варианты авторизации
    // Для OpenAI-совместимого endpoint используем Bearer, для DashScope native - X-DashScope-API-Key
    if ($isOpenAICompatible) {
        $authMethods = [
            'bearer' => ['Authorization: Bearer ' . $apiKey],
        ];
    } else {
        $authMethods = [
            'dashscope_key' => ['X-DashScope-API-Key: ' . $apiKey],
            'bearer' => ['Authorization: Bearer ' . $apiKey],
            'dashscope_auth' => ['Authorization: Bearer ' . $apiKey, 'X-DashScope-API-Key: ' . $apiKey],
        ];
    }
    
    foreach ($authMethods as $methodName => $headers) {
        echo "Пробую метод авторизации: $methodName\n";
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge([
                'Content-Type: application/json',
                'X-DashScope-SSE: disable', // Отключаем Server-Sent Events
            ], $headers),
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
    
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        
        echo "HTTP Code: $httpCode\n";
        
        if ($response === false || $curlErrno !== 0) {
            echo "❌ Ошибка cURL: $curlError (код: $curlErrno)\n\n";
            continue;
        }
        
        echo "Response length: " . strlen($response) . " bytes\n";
        echo "Response preview: " . substr($response, 0, 500) . "\n\n";
        
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "❌ Ошибка парсинга JSON: " . json_last_error_msg() . "\n";
            echo "Raw response: $response\n\n";
            continue;
        }
        
        // Проверяем на ошибку авторизации
        if ($httpCode === 401 || (isset($decoded['code']) && $decoded['code'] === 'InvalidApiKey')) {
            echo "❌ Ошибка авторизации с методом $methodName\n";
            if (isset($decoded['message'])) {
                echo "Сообщение: " . $decoded['message'] . "\n";
            }
            echo "\n";
            continue;
        }
        
        echo "✅ JSON успешно распарсен\n";
        echo "Response structure:\n";
        echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
        // Пытаемся извлечь текст ответа
        $text = null;
        
        // Проверяем различные возможные структуры ответа
        if (isset($decoded['output']['text'])) {
            $text = $decoded['output']['text'];
            echo "✅ Найден текст в output.text\n";
        } elseif (isset($decoded['output']['choices'][0]['text'])) {
            $text = $decoded['output']['choices'][0]['text'];
            echo "✅ Найден текст в output.choices[0].text\n";
        } elseif (isset($decoded['output']['choices'][0]['message']['content'])) {
            $text = $decoded['output']['choices'][0]['message']['content'];
            echo "✅ Найден текст в output.choices[0].message.content\n";
        } elseif (isset($decoded['choices'][0]['message']['content'])) {
            $text = $decoded['choices'][0]['message']['content'];
            echo "✅ Найден текст в choices[0].message.content (OpenAI-совместимый формат)\n";
        } elseif (isset($decoded['choices'][0]['text'])) {
            $text = $decoded['choices'][0]['text'];
            echo "✅ Найден текст в choices[0].text (OpenAI-совместимый формат)\n";
        } elseif (isset($decoded['data']['output']['text'])) {
            $text = $decoded['data']['output']['text'];
            echo "✅ Найден текст в data.output.text\n";
        } elseif (isset($decoded['result']['output']['text'])) {
            $text = $decoded['result']['output']['text'];
            echo "✅ Найден текст в result.output.text\n";
        } else {
            echo "⚠️ Текст ответа не найден в ожидаемых полях\n";
            echo "Доступные ключи верхнего уровня: " . implode(', ', array_keys($decoded)) . "\n";
        }
        
        if ($text !== null && $httpCode === 200) {
            echo "\n=== Текст ответа ===\n";
            echo trim($text) . "\n\n";
            echo "✅ Успешно! Рабочий метод авторизации: $methodName\n";
            return ['success' => true, 'auth_method' => $methodName, 'text' => $text];
        }
    }
    
    return false;
}

// Тестируем разные endpoint'ы и форматы
$success = false;
$workingConfig = null;

// Тест 1: Chat Completions (основной метод для Qwen)
echo "--- Тест 1: Chat Completions (OpenAI-совместимый) ---\n\n";
$result = testEndpoint($endpoints['chat_completions'], $apiKey, $model, $testPrompt, 'chat_completions');
if ($result && isset($result['success'])) {
    $success = true;
    $workingConfig = [
        'base_url' => $baseUrl,
        'endpoint' => $endpoints['chat_completions'],
        'format' => 'chat_completions',
        'request_structure' => 'openai-compatible',
        'auth_method' => $result['auth_method']
    ];
    echo "✅ Успешно! Используйте этот endpoint и формат.\n\n";
} else {
    echo "❌ Не удалось. Пробуем другой endpoint...\n\n";
}

// Тест 2: Completions (альтернативный метод)
if (!$success) {
    echo "--- Тест 2: Completions (OpenAI-совместимый) ---\n\n";
    $result = testEndpoint($endpoints['completions'], $apiKey, $model, $testPrompt, 'completions');
    if ($result && isset($result['success'])) {
        $success = true;
        $workingConfig = [
            'base_url' => $baseUrl,
            'endpoint' => $endpoints['completions'],
            'format' => 'completions',
            'request_structure' => 'openai-compatible',
            'auth_method' => $result['auth_method']
        ];
        echo "✅ Успешно! Используйте этот endpoint и формат.\n\n";
    } else {
        echo "❌ Не удалось. Пробуем DashScope native API...\n\n";
    }
}

// Тест 3: DashScope Text Generation (prompt-based)
if (!$success) {
    echo "--- Тест 3: DashScope Text Generation (prompt-based) ---\n\n";
    $result = testEndpoint($endpoints['text_generation'], $apiKey, $model, $testPrompt, 'completions');
    if ($result && isset($result['success'])) {
        $success = true;
        $workingConfig = [
            'endpoint' => $endpoints['text_generation'],
            'format' => 'completions',
            'request_structure' => 'prompt-based',
            'auth_method' => $result['auth_method']
        ];
        echo "✅ Успешно! Используйте этот endpoint и формат.\n\n";
    } else {
        echo "❌ Не удалось. Пробуем другой формат...\n\n";
    }
}

// Тест 4: DashScope Chat Completions (messages-based)
if (!$success) {
    echo "--- Тест 4: DashScope Chat Completions (messages-based) ---\n\n";
    $result = testEndpoint($endpoints['text_generation'], $apiKey, $model, $testPrompt, 'chat_completions');
    if ($result && isset($result['success'])) {
        $success = true;
        $workingConfig = [
            'endpoint' => $endpoints['text_generation'],
            'format' => 'chat_completions',
            'request_structure' => 'messages-based',
            'auth_method' => $result['auth_method']
        ];
        echo "✅ Успешно! Используйте этот endpoint и формат.\n\n";
    } else {
        echo "❌ Не удалось. Пробуем альтернативный формат...\n\n";
    }
}

// Тест 5: Простой prompt
if (!$success) {
    echo "--- Тест 5: Простой prompt (без input) ---\n\n";
    $result = testEndpoint($endpoints['text_generation'], $apiKey, $model, $testPrompt, 'simple');
    if ($result && isset($result['success'])) {
        $success = true;
        $workingConfig = [
            'endpoint' => $endpoints['text_generation'],
            'format' => 'simple',
            'request_structure' => 'direct-prompt',
            'auth_method' => $result['auth_method']
        ];
        echo "✅ Успешно! Используйте этот endpoint и формат.\n\n";
    }
}

echo "\n=== ИТОГИ ТЕСТИРОВАНИЯ ===\n";
if ($success && $workingConfig) {
    echo "✅ API работает! Можно интегрировать в систему.\n\n";
    echo "=== РАБОЧАЯ КОНФИГУРАЦИЯ ===\n";
    if (isset($workingConfig['base_url'])) {
        echo "Base URL: " . $workingConfig['base_url'] . "\n";
    }
    echo "Endpoint: " . $workingConfig['endpoint'] . "\n";
    echo "Модель: " . $model . "\n";
    echo "Формат: " . $workingConfig['format'] . "\n";
    echo "Структура запроса: " . $workingConfig['request_structure'] . "\n";
    if (isset($workingConfig['auth_method'])) {
        echo "Метод авторизации: " . $workingConfig['auth_method'] . "\n";
    }
    echo "\nИспользуйте эти параметры при интеграции в config.php.\n";
} else {
    echo "❌ Не удалось подключиться к API. Проверьте:\n";
    echo "  1. Правильность API ключа\n";
    echo "  2. Доступность endpoint URL: " . $baseUrl . "\n";
    echo "  3. Формат запроса (используется OpenAI-совместимый формат)\n";
    echo "  4. Наличие доступа к модели " . $model . "\n";
    echo "  5. Проверьте документацию DashScope: https://help.aliyun.com/zh/model-studio/\n";
    echo "  6. Убедитесь, что сервис Model Studio активирован в консоли Alibaba Cloud\n";
}

if (!$isCli) {
    echo '</pre></body></html>';
}

