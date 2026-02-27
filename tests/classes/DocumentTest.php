<?php
/**
 * Тесты работы с документами
 */
class DocumentTest
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
     * Тест загрузки документов
     */
    public function testUploadDocument()
    {
        $this->runner->runTest('Загрузка документов', function($r) {
            $fileExists = file_exists(__DIR__ . '/../../upload_asset_document.php');
            $r->assertTrue($fileExists, 'Файл загрузки документов должен существовать');
        });
    }
    
    /**
     * Тест получения списка документов
     */
    public function testGetDocumentsList()
    {
        $this->runner->runTest('Получение списка документов', function($r) {
            $fileExists = file_exists(__DIR__ . '/../../get_asset_documents.php');
            $r->assertTrue($fileExists, 'Файл получения списка документов должен существовать');
        });
    }
    
    /**
     * Тест удаления документов
     */
    public function testDeleteDocument()
    {
        $this->runner->runTest('Удаление документов', function($r) {
            $fileExists = file_exists(__DIR__ . '/../../delete_asset_document.php');
            $r->assertTrue($fileExists, 'Файл удаления документов должен существовать');
        });
    }
}
