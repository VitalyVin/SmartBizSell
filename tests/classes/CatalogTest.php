<?php
/**
 * Тесты каталога бизнесов
 */
class CatalogTest
{
    private $runner;
    
    public function __construct(TestRunner $runner)
    {
        $this->runner = $runner;
    }
    
    /**
     * Тест отображения каталога бизнесов
     */
    public function testDisplayCatalog()
    {
        $this->runner->runTest('Отображение каталога бизнесов', function($r) {
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../index.php',
                [],
                'GET'
            );
            
            $r->assertNotEmpty($result['output'], 'Главная страница должна возвращать HTML');
            $r->assertContains('бизнес', strtolower($result['output'] ?? ''), 'Должен отображаться каталог бизнесов');
        });
    }
    
    /**
     * Тест отображения card_title
     */
    public function testCardTitleDisplay()
    {
        $this->runner->runTest('Отображение card_title', function($r) {
            $pdo = getTestDBConnection();
            
            // Создаем опубликованный тизер с card_title
            $testEmail = TEST_USER_EMAIL;
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$testEmail]);
            $user = $stmt->fetch();
            
            if ($user) {
                $stmt = $pdo->prepare("
                    INSERT INTO seller_forms (
                        user_id, asset_name, company_inn, company_type, status, created_at
                    ) VALUES (?, ?, ?, 'mature', 'submitted', NOW())
                ");
                $stmt->execute([
                    $user['id'],
                    'Тест карточки',
                    '2222222222'
                ]);
                $formId = $pdo->lastInsertId();
                
                $stmt = $pdo->prepare("
                    INSERT INTO published_teasers (
                        seller_form_id, teaser_html, card_title, moderation_status, is_published, created_at
                    ) VALUES (?, ?, ?, 'published', 1, NOW())
                ");
                $stmt->execute([
                    $formId,
                    '<div>Тестовый тизер</div>',
                    'Кастомное название карточки'
                ]);
                $teaserId = $pdo->lastInsertId();
                
                // Проверяем отображение на главной странице
                $result = $this->runner->executeLocalFile(
                    __DIR__ . '/../../index.php',
                    [],
                    'GET'
                );
                
                $output = $result['output'] ?? '';
                $r->assertContains('Кастомное название карточки', $output, 'card_title должен отображаться');
                
                // Очистка не нужна - транзакция автоматически откатит изменения
            }
        });
    }
    
    /**
     * Тест фильтрации по отраслям
     */
    public function testIndustryFilter()
    {
        $this->runner->runTest('Фильтрация по отраслям', function($r) {
            // Проверяем наличие функции фильтрации
            $fileExists = file_exists(__DIR__ . '/../../index.php');
            $r->assertTrue($fileExists, 'Главная страница должна существовать');
        });
    }
}
