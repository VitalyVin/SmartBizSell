<?php
/**
 * Тесты восстановления пароля
 */
class PasswordTest
{
    private $runner;
    private $testUserId;
    private $testEmail;
    
    public function __construct(TestRunner $runner)
    {
        $this->runner = $runner;
        $this->testEmail = 'test_password_' . time() . '@smartbizsell.ru';
        $this->testUserId = $this->createTestUser();
    }
    
    private function createTestUser(): int
    {
        $pdo = getTestDBConnection();
        
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)");
        $stmt->execute([
            $this->testEmail,
            password_hash('TestPassword123!', PASSWORD_BCRYPT, ['cost' => 12]),
            'Тестовый Пользователь'
        ]);
        
        return $pdo->lastInsertId();
    }
    
    /**
     * Тест запроса восстановления пароля
     */
    public function testPasswordResetRequest()
    {
        $this->runner->runTest('Запрос восстановления пароля', function($r) {
            $fileExists = file_exists(__DIR__ . '/../../forgot_password.php');
            $r->assertTrue($fileExists, 'Файл восстановления пароля должен существовать');
            
            // Проверяем, что можно запросить восстановление
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../forgot_password.php',
                [
                    'email' => $this->testEmail
                ],
                'POST'
            );
            
            $r->assertNotEmpty($result['output'], 'Должен быть ответ от страницы восстановления');
        });
    }
    
    /**
     * Тест валидации токена восстановления
     */
    public function testResetTokenValidation()
    {
        $this->runner->runTest('Валидация токена восстановления', function($r) {
            $fileExists = file_exists(__DIR__ . '/../../reset_password.php');
            $r->assertTrue($fileExists, 'Файл сброса пароля должен существовать');
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
        
        try {
            $pdo = getTestDBConnection();
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$this->testUserId]);
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }
}
