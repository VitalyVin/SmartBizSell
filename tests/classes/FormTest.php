<?php
/**
 * Тесты анкет продавца
 */
class FormTest
{
    private $runner;
    private $testUserId;
    private $testFormIds = [];
    
    public function __construct(TestRunner $runner)
    {
        $this->runner = $runner;
        $this->testUserId = $this->getOrCreateTestUser();
    }
    
    /**
     * Получает или создает тестового пользователя
     */
    private function getOrCreateTestUser(): int
    {
        $pdo = getTestDBConnection();
        $testEmail = TEST_USER_EMAIL;
        
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$testEmail]);
        $user = $stmt->fetch();
        
        if ($user) {
            return $user['id'];
        }
        
        // Создаем тестового пользователя
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)");
        $stmt->execute([
            $testEmail,
            password_hash(TEST_USER_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]),
            TEST_USER_NAME
        ]);
        
        return $pdo->lastInsertId();
    }
    
    /**
     * Тест создания анкеты для зрелой компании
     */
    public function testCreateMatureForm()
    {
        $this->runner->runTest('Создание анкеты для зрелой компании', function($r) {
            $pdo = getTestDBConnection();
            
            $formData = [
                'asset_name' => TEST_COMPANY_NAME,
                'company_inn' => TEST_COMPANY_INN,
                'company_type' => 'mature',
                'deal_subject' => 'Продажа бизнеса',
                'deal_purpose' => 'cash_in',
                'asset_disclosure' => 'yes',
                'deal_share_range' => '100',
                'company_description' => 'Тестовое описание компании',
                'presence_regions' => 'Москва, Санкт-Петербург',
                'products_services' => 'Производство товаров',
                'main_clients' => 'Крупные компании',
                'sales_share' => '50',
                'personnel_count' => '50',
                'financial_results_vat' => 'yes',
                'financial_source' => 'accounting',
                'agree' => '1'
            ];
            
            // Выполняем создание формы
            $_SESSION['user_id'] = $this->testUserId;
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../seller_form.php',
                $formData,
                'POST'
            );
            
            // Проверяем, что форма создана
            $stmt = $pdo->prepare("SELECT id, asset_name, company_type FROM seller_forms WHERE user_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$this->testUserId]);
            $form = $stmt->fetch();
            
            $r->assertNotEmpty($form, 'Форма должна быть создана');
            $r->assertEquals('mature', $form['company_type'], 'Тип компании должен быть mature');
            $r->assertEquals(TEST_COMPANY_NAME, $form['asset_name'], 'Название актива должно совпадать');
            
            if ($form) {
                $this->testFormIds[] = $form['id'];
            }
        });
    }
    
    /**
     * Тест создания анкеты для стартапа
     */
    public function testCreateStartupForm()
    {
        $this->runner->runTest('Создание анкеты для стартапа', function($r) {
            $pdo = getTestDBConnection();
            
            $formData = [
                'asset_name' => TEST_STARTUP_NAME,
                'company_inn' => TEST_STARTUP_INN,
                'company_type' => 'startup',
                'deal_subject' => 'Привлечение инвестиций',
                'deal_purpose' => 'cash_in',
                'asset_disclosure' => 'yes',
                'deal_share_range' => '40',
                'startup_product_description' => 'Тестовый продукт',
                'startup_product_stage' => 'mvp',
                'startup_target_market' => 'B2B рынок',
                'agree' => '1'
            ];
            
            // Выполняем создание формы
            $_SESSION['user_id'] = $this->testUserId;
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../seller_form.php',
                $formData,
                'POST'
            );
            
            // Проверяем, что форма создана
            $stmt = $pdo->prepare("SELECT id, asset_name, company_type FROM seller_forms WHERE user_id = ? AND company_type = 'startup' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$this->testUserId]);
            $form = $stmt->fetch();
            
            $r->assertNotEmpty($form, 'Форма стартапа должна быть создана');
            $r->assertEquals('startup', $form['company_type'], 'Тип компании должен быть startup');
            $r->assertEquals(TEST_STARTUP_NAME, $form['asset_name'], 'Название актива должно совпадать');
            
            if ($form) {
                $this->testFormIds[] = $form['id'];
            }
        });
    }
    
    /**
     * Тест сохранения черновика
     */
    public function testSaveDraft()
    {
        $this->runner->runTest('Сохранение черновика', function($r) {
            $pdo = getTestDBConnection();
            
            $formData = [
                'asset_name' => 'Черновик тест',
                'company_inn' => '1111111111',
                'company_type' => 'mature',
                'save_draft' => '1' // Флаг сохранения черновика
            ];
            
            $_SESSION['user_id'] = $this->testUserId;
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../seller_form.php',
                $formData,
                'POST'
            );
            
            // Проверяем, что черновик сохранен
            $stmt = $pdo->prepare("SELECT id, status, data_json FROM seller_forms WHERE user_id = ? AND asset_name = 'Черновик тест' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$this->testUserId]);
            $form = $stmt->fetch();
            
            $r->assertNotEmpty($form, 'Черновик должен быть сохранен');
            $r->assertEquals('draft', $form['status'], 'Статус должен быть draft');
            $r->assertNotEmpty($form['data_json'], 'data_json должен быть заполнен');
            
            // Проверяем, что данные в JSON корректны
            $data = json_decode($form['data_json'], true);
            $r->assertIsArray($data, 'data_json должен быть валидным JSON массивом');
            $r->assertEquals('1111111111', $data['company_inn'] ?? null, 'ИНН должен быть сохранен в data_json');
            
            if ($form) {
                $this->testFormIds[] = $form['id'];
            }
        });
    }
    
    /**
     * Тест валидации обязательных полей для зрелой компании
     */
    public function testMatureFormValidation()
    {
        $this->runner->runTest('Валидация обязательных полей для зрелой компании', function($r) {
            $pdo = getTestDBConnection();
            
            // Пытаемся создать форму без обязательных полей
            $formData = [
                'asset_name' => 'Неполная форма',
                'company_type' => 'mature',
                // Отсутствуют обязательные поля
            ];
            
            $_SESSION['user_id'] = $this->testUserId;
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../seller_form.php',
                $formData,
                'POST'
            );
            
            // Проверяем, что форма не была отправлена (статус не должен быть submitted)
            $stmt = $pdo->prepare("SELECT status FROM seller_forms WHERE user_id = ? AND asset_name = 'Неполная форма' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$this->testUserId]);
            $form = $stmt->fetch();
            
            if ($form) {
                $r->assertNotEquals('submitted', $form['status'], 'Форма без обязательных полей не должна быть отправлена');
                $this->testFormIds[] = $form['id'];
            }
        });
    }
    
    /**
     * Тест валидации обязательных полей для стартапа
     */
    public function testStartupFormValidation()
    {
        $this->runner->runTest('Валидация обязательных полей для стартапа', function($r) {
            $pdo = getTestDBConnection();
            
            // Пытаемся создать форму стартапа с минимальными полями
            $formData = [
                'asset_name' => 'Минимальный стартап',
                'company_inn' => '2222222222',
                'company_type' => 'startup',
                'deal_share_range' => '30',
                'deal_goal' => 'cash_in',
                'asset_disclosure' => 'yes',
                'startup_product_description' => 'Продукт',
                'startup_product_stage' => 'mvp',
                'startup_target_market' => 'Рынок',
                'agree' => '1'
            ];
            
            $_SESSION['user_id'] = $this->testUserId;
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../seller_form.php',
                $formData,
                'POST'
            );
            
            // Проверяем, что форма создана (для стартапов меньше обязательных полей)
            $stmt = $pdo->prepare("SELECT id, status FROM seller_forms WHERE user_id = ? AND asset_name = 'Минимальный стартап' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$this->testUserId]);
            $form = $stmt->fetch();
            
            $r->assertNotEmpty($form, 'Форма стартапа с минимальными полями должна быть создана');
            
            if ($form) {
                $this->testFormIds[] = $form['id'];
            }
        });
    }
    
    /**
     * Тест сохранения данных в data_json
     */
    public function testDataJsonStorage()
    {
        $this->runner->runTest('Сохранение данных в data_json', function($r) {
            $pdo = getTestDBConnection();
            
            $formData = [
                'asset_name' => 'Тест data_json',
                'company_inn' => '3333333333',
                'company_type' => 'mature',
                'save_draft' => '1',
                'production' => json_encode([['name' => 'Продукт 1', 'volume' => '100']]),
                'financial' => json_encode([['2022_fact' => '1000', '2023_fact' => '1200']])
            ];
            
            $_SESSION['user_id'] = $this->testUserId;
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../seller_form.php',
                $formData,
                'POST'
            );
            
            // Проверяем, что данные сохранены в data_json
            $stmt = $pdo->prepare("SELECT data_json FROM seller_forms WHERE user_id = ? AND asset_name = 'Тест data_json' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$this->testUserId]);
            $form = $stmt->fetch();
            
            $r->assertNotEmpty($form, 'Форма должна быть создана');
            
            if ($form && $form['data_json']) {
                $data = json_decode($form['data_json'], true);
                $r->assertIsArray($data, 'data_json должен быть валидным JSON');
                $r->assertInArray('production', array_keys($data), 'production должен быть в data_json');
                $r->assertInArray('financial', array_keys($data), 'financial должен быть в data_json');
                
                $this->testFormIds[] = $form['id'];
            }
        });
    }
    
    /**
     * Очистка тестовых данных
     * Вызывается только если транзакции отключены (TEST_USE_TRANSACTIONS = false)
     */
    public function cleanup()
    {
        // Если транзакции включены, очистка не нужна - все изменения автоматически откатываются
        if ($this->runner->isTransactionsEnabled()) {
            return;
        }
        
        if (!empty($this->testFormIds)) {
            try {
                $pdo = getTestDBConnection();
                $placeholders = implode(',', array_fill(0, count($this->testFormIds), '?'));
                $stmt = $pdo->prepare("DELETE FROM seller_forms WHERE id IN ($placeholders)");
                $stmt->execute($this->testFormIds);
            } catch (Exception $e) {
                // Игнорируем ошибки при очистке
            }
        }
    }
}
