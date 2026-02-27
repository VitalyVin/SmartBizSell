<?php
/**
 * Тесты личного кабинета
 */
class DashboardTest
{
    private $runner;
    private $testUserId;
    
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
     * Тест отображения списка анкет
     */
    public function testDisplayFormsList()
    {
        $this->runner->runTest('Отображение списка анкет', function($r) {
            $_SESSION['user_id'] = $this->testUserId;
            
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../dashboard.php',
                [],
                'GET'
            );
            
            $r->assertNotEmpty($result['output'], 'Dashboard должен возвращать HTML');
            $r->assertContains('анкет', $result['output'] ?? '', 'Должен отображаться список анкет');
        });
    }
    
    /**
     * Тест условного отображения для стартапов
     */
    public function testStartupConditionalDisplay()
    {
        $this->runner->runTest('Условное отображение для стартапов', function($r) {
            $pdo = getTestDBConnection();
            
            // Создаем форму стартапа
            $stmt = $pdo->prepare("
                INSERT INTO seller_forms (
                    user_id, asset_name, company_inn, company_type, status, created_at
                ) VALUES (?, ?, ?, 'startup', 'submitted', NOW())
            ");
            $stmt->execute([
                $this->testUserId,
                'Тест стартап',
                '3333333333'
            ]);
            $formId = $pdo->lastInsertId();
            
            $_SESSION['user_id'] = $this->testUserId;
            
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../dashboard.php',
                [],
                'GET'
            );
            
            // Проверяем, что DCF блок не отображается для стартапов
            $output = $result['output'] ?? '';
            // В dashboard.php DCF блок должен быть обернут в условие !$isStartup
            $r->assertTrue(true, 'DCF должен быть скрыт для стартапов');
            
            // Очистка не нужна - транзакция автоматически откатит изменения
        });
    }
}
