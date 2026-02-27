<?php
/**
 * Тесты модерации тизеров
 */
class ModerationTest
{
    private $runner;
    private $testUserId;
    private $testFormId;
    private $testTeaserId;
    
    public function __construct(TestRunner $runner)
    {
        $this->runner = $runner;
        $this->testUserId = $this->getOrCreateTestUser();
    }
    
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
     * Тест отправки тизера на модерацию
     */
    public function testSubmitTeaserForModeration()
    {
        $this->runner->runTest('Отправка тизера на модерацию', function($r) {
            $pdo = getTestDBConnection();
            
            // Создаем форму
            $stmt = $pdo->prepare("
                INSERT INTO seller_forms (
                    user_id, asset_name, company_inn, company_type, status, created_at
                ) VALUES (?, ?, ?, 'mature', 'submitted', NOW())
            ");
            $stmt->execute([
                $this->testUserId,
                'Тест модерации',
                '7777777777'
            ]);
            $this->testFormId = $pdo->lastInsertId();
            
            // Создаем тизер
            $stmt = $pdo->prepare("
                INSERT INTO published_teasers (
                    seller_form_id, teaser_html, moderation_status, created_at
                ) VALUES (?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $this->testFormId,
                '<div>Тестовый тизер</div>'
            ]);
            $this->testTeaserId = $pdo->lastInsertId();
            
            $_SESSION['user_id'] = $this->testUserId;
            
            // Отправляем на модерацию
            $url = TEST_BASE_URL . '/submit_teaser_moderation.php';
            $response = $this->runner->makeRequest($url, 'POST', [
                'form_id' => $this->testFormId
            ]);
            
            // Проверяем статус модерации
            $stmt = $pdo->prepare("SELECT moderation_status FROM published_teasers WHERE id = ?");
            $stmt->execute([$this->testTeaserId]);
            $teaser = $stmt->fetch();
            
            $r->assertNotEmpty($teaser, 'Тизер должен существовать');
            $r->assertEquals('pending', $teaser['moderation_status'], 'Статус модерации должен быть pending');
        });
    }
    
    /**
     * Тест одобрения тизера
     */
    public function testApproveTeaser()
    {
        $this->runner->runTest('Одобрение тизера модератором', function($r) {
            $pdo = getTestDBConnection();
            
            // Создаем тизер на модерации
            $stmt = $pdo->prepare("
                INSERT INTO seller_forms (
                    user_id, asset_name, company_inn, company_type, status, created_at
                ) VALUES (?, ?, ?, 'mature', 'submitted', NOW())
            ");
            $stmt->execute([
                $this->testUserId,
                'Тест одобрения',
                '6666666666'
            ]);
            $formId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("
                INSERT INTO published_teasers (
                    seller_form_id, teaser_html, moderation_status, created_at
                ) VALUES (?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $formId,
                '<div>Тизер для одобрения</div>'
            ]);
            $teaserId = $pdo->lastInsertId();
            
            // Одобряем тизер
            $url = TEST_BASE_URL . '/moderation_api.php';
            $response = $this->runner->makeRequest($url, 'POST', [
                'action' => 'approve',
                'teaser_id' => $teaserId
            ]);
            
            // Проверяем статус
            $stmt = $pdo->prepare("SELECT moderation_status FROM published_teasers WHERE id = ?");
            $stmt->execute([$teaserId]);
            $teaser = $stmt->fetch();
            
            $r->assertEquals('approved', $teaser['moderation_status'], 'Статус должен быть approved');
            
            // Очистка не нужна - транзакция автоматически откатит изменения
        });
    }
    
    /**
     * Тест отклонения тизера
     */
    public function testRejectTeaser()
    {
        $this->runner->runTest('Отклонение тизера модератором', function($r) {
            $pdo = getTestDBConnection();
            
            // Создаем тизер на модерации
            $stmt = $pdo->prepare("
                INSERT INTO seller_forms (
                    user_id, asset_name, company_inn, company_type, status, created_at
                ) VALUES (?, ?, ?, 'mature', 'submitted', NOW())
            ");
            $stmt->execute([
                $this->testUserId,
                'Тест отклонения',
                '5555555555'
            ]);
            $formId = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("
                INSERT INTO published_teasers (
                    seller_form_id, teaser_html, moderation_status, created_at
                ) VALUES (?, ?, 'pending', NOW())
            ");
            $stmt->execute([
                $formId,
                '<div>Тизер для отклонения</div>'
            ]);
            $teaserId = $pdo->lastInsertId();
            
            // Отклоняем тизер
            $url = TEST_BASE_URL . '/moderation_api.php';
            $response = $this->runner->makeRequest($url, 'POST', [
                'action' => 'reject',
                'teaser_id' => $teaserId,
                'notes' => 'Тестовое отклонение'
            ]);
            
            // Проверяем статус
            $stmt = $pdo->prepare("SELECT moderation_status, moderation_notes FROM published_teasers WHERE id = ?");
            $stmt->execute([$teaserId]);
            $teaser = $stmt->fetch();
            
            $r->assertEquals('rejected', $teaser['moderation_status'], 'Статус должен быть rejected');
            $r->assertContains('Тестовое отклонение', $teaser['moderation_notes'] ?? '', 'Должны быть заметки модератора');
            
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
                if ($this->testTeaserId) {
                    $stmt = $pdo->prepare("DELETE FROM published_teasers WHERE id = ?");
                    $stmt->execute([$this->testTeaserId]);
                }
                $stmt = $pdo->prepare("DELETE FROM seller_forms WHERE id = ?");
                $stmt->execute([$this->testFormId]);
            } catch (Exception $e) {
                // Игнорируем ошибки
            }
        }
    }
}
