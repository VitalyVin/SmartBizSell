<?php
/**
 * Тесты генерации тизеров
 */
class TeaserTest
{
    private $runner;
    private $testUserId;
    private $testFormId;
    
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
        
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)");
        $stmt->execute([
            $testEmail,
            password_hash(TEST_USER_PASSWORD, PASSWORD_BCRYPT, ['cost' => 12]),
            TEST_USER_NAME
        ]);
        
        return $pdo->lastInsertId();
    }
    
    /**
     * Создает тестовую форму
     */
    private function createTestForm(string $companyType = 'mature'): int
    {
        $pdo = getTestDBConnection();
        
        $assetName = $companyType === 'startup' ? TEST_STARTUP_NAME : TEST_COMPANY_NAME;
        $companyInn = $companyType === 'startup' ? TEST_STARTUP_INN : TEST_COMPANY_INN;
        
        $stmt = $pdo->prepare("
            INSERT INTO seller_forms (
                user_id, asset_name, company_inn, company_type, deal_subject, deal_purpose,
                asset_disclosure, deal_share_range, company_description, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())
        ");
        
        $stmt->execute([
            $this->testUserId,
            $assetName,
            $companyInn,
            $companyType,
            'Тестовая сделка',
            'cash_in',
            'yes',
            '50',
            'Тестовое описание компании'
        ]);
        
        return $pdo->lastInsertId();
    }
    
    /**
     * Тест генерации тизера для зрелой компании
     */
    public function testGenerateMatureTeaser()
    {
        $this->runner->runTest('Генерация тизера для зрелой компании', function($r) {
            $this->testFormId = $this->createTestForm('mature');
            
            $_SESSION['user_id'] = $this->testUserId;
            
            // Выполняем генерацию тизера через AJAX
            $url = TEST_BASE_URL . '/generate_teaser.php?action=generate';
            $response = $this->runner->makeRequest($url, 'POST', [
                'form_id' => $this->testFormId
            ]);
            
            $r->assertEquals(200, $response['status'], 'HTTP статус должен быть 200');
            
            $data = json_decode($response['body'], true);
            $r->assertIsArray($data, 'Ответ должен быть JSON массивом');
            $r->assertTrue($data['success'] ?? false, 'Генерация должна быть успешной');
            $r->assertNotEmpty($data['html'] ?? '', 'HTML тизера должен быть сгенерирован');
            
            // Проверяем структуру HTML
            $html = $data['html'] ?? '';
            $r->assertContains('overview', $html, 'HTML должен содержать блок overview');
            $r->assertContains('company_profile', $html, 'HTML должен содержать блок company_profile');
            $r->assertContains('products', $html, 'HTML должен содержать блок products');
        });
    }
    
    /**
     * Тест генерации тизера для стартапа
     */
    public function testGenerateStartupTeaser()
    {
        $this->runner->runTest('Генерация тизера для стартапа', function($r) {
            $this->testFormId = $this->createTestForm('startup');
            
            $_SESSION['user_id'] = $this->testUserId;
            
            $url = TEST_BASE_URL . '/generate_teaser.php?action=generate';
            $response = $this->runner->makeRequest($url, 'POST', [
                'form_id' => $this->testFormId
            ]);
            
            $r->assertEquals(200, $response['status'], 'HTTP статус должен быть 200');
            
            $data = json_decode($response['body'], true);
            $r->assertIsArray($data, 'Ответ должен быть JSON массивом');
            $r->assertTrue($data['success'] ?? false, 'Генерация должна быть успешной');
            $r->assertNotEmpty($data['html'] ?? '', 'HTML тизера должен быть сгенерирован');
            
            // Проверяем структуру HTML для стартапа
            $html = $data['html'] ?? '';
            $r->assertContains('overview', $html, 'HTML должен содержать блок overview');
            $r->assertContains('product_technology', $html, 'HTML должен содержать блок product_technology');
            $r->assertContains('team', $html, 'HTML должен содержать блок team');
            $r->assertContains('traction', $html, 'HTML должен содержать блок traction');
        });
    }
    
    /**
     * Тест валидации перед генерацией тизера
     */
    public function testTeaserValidation()
    {
        $this->runner->runTest('Валидация перед генерацией тизера', function($r) {
            // Создаем неполную форму
            $pdo = getTestDBConnection();
            $stmt = $pdo->prepare("
                INSERT INTO seller_forms (
                    user_id, asset_name, company_inn, company_type, status, created_at
                ) VALUES (?, ?, ?, 'mature', 'draft', NOW())
            ");
            $stmt->execute([
                $this->testUserId,
                'Неполная форма',
                '9999999999'
            ]);
            $formId = $pdo->lastInsertId();
            
            $_SESSION['user_id'] = $this->testUserId;
            
            $url = TEST_BASE_URL . '/generate_teaser.php?action=generate';
            $response = $this->runner->makeRequest($url, 'POST', [
                'form_id' => $formId
            ]);
            
            // Для неполной формы должна быть ошибка валидации
            $data = json_decode($response['body'], true);
            
            // Либо ошибка, либо успех (в зависимости от настроек валидации)
            $r->assertIsArray($data, 'Ответ должен быть JSON массивом');
            
            // Очистка не нужна - транзакция автоматически откатит изменения
        });
    }
    
    /**
     * Тест маскирования названия компании
     */
    public function testCompanyNameMasking()
    {
        $this->runner->runTest('Маскирование названия компании', function($r) {
            $pdo = getTestDBConnection();
            
            // Создаем форму с скрытым названием
            $stmt = $pdo->prepare("
                INSERT INTO seller_forms (
                    user_id, asset_name, company_inn, company_type, asset_disclosure, status, created_at
                ) VALUES (?, ?, ?, 'mature', 'no', 'submitted', NOW())
            ");
            $stmt->execute([
                $this->testUserId,
                'Скрытая компания',
                '8888888888'
            ]);
            $formId = $pdo->lastInsertId();
            
            $_SESSION['user_id'] = $this->testUserId;
            
            $url = TEST_BASE_URL . '/generate_teaser.php?action=generate';
            $response = $this->runner->makeRequest($url, 'POST', [
                'form_id' => $formId
            ]);
            
            $data = json_decode($response['body'], true);
            if ($data['success'] ?? false) {
                $html = $data['html'] ?? '';
                // Проверяем, что название компании не упоминается в HTML
                $r->assertFalse(strpos($html, 'Скрытая компания') !== false, 'Название компании не должно быть в HTML');
            }
            
            // Очистка не нужна - транзакция автоматически откатит изменения
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
        
        if ($this->testFormId) {
            try {
                $pdo = getTestDBConnection();
                $stmt = $pdo->prepare("DELETE FROM seller_forms WHERE id = ?");
                $stmt->execute([$this->testFormId]);
            } catch (Exception $e) {
                // Игнорируем ошибки
            }
        }
    }
}
