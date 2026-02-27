<?php
/**
 * Тесты авторизации и регистрации
 */
class AuthTest
{
    private $runner;
    private $testEmail;
    private $testPassword;
    private $testUserId = null;
    
    public function __construct(TestRunner $runner)
    {
        $this->runner = $runner;
        $this->testEmail = 'test_' . time() . '@smartbizsell.ru';
        $this->testPassword = 'TestPassword123!';
    }
    
    /**
     * Тест регистрации нового пользователя
     */
    public function testRegistration()
    {
        $this->runner->runTest('Регистрация нового пользователя', function($r) {
            // Транзакция автоматически изолирует тест, очистка не нужна
            $pdo = getTestDBConnection();
            
            // Выполняем регистрацию через локальный файл
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../register.php',
                [
                    'email' => $this->testEmail,
                    'password' => $this->testPassword,
                    'password_confirm' => $this->testPassword,
                    'full_name' => 'Тестовый Пользователь',
                    'phone' => '+79991234567',
                    'company_name' => 'Тестовая Компания'
                ],
                'POST'
            );
            
            // Проверяем, что пользователь создан в БД
            $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE email = ?");
            $stmt->execute([$this->testEmail]);
            $user = $stmt->fetch();
            
            $r->assertNotEmpty($user, 'Пользователь должен быть создан в БД');
            $r->assertEquals($this->testEmail, $user['email'], 'Email должен совпадать');
            $r->assertEquals('Тестовый Пользователь', $user['full_name'], 'Имя должно совпадать');
            
            $this->testUserId = $user['id'];
        });
    }
    
    /**
     * Тест регистрации с дублирующимся email
     */
    public function testRegistrationDuplicateEmail()
    {
        $this->runner->runTest('Регистрация с дублирующимся email', function($r) {
            // Сначала создаем пользователя
            $pdo = getTestDBConnection();
            $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)");
            $stmt->execute([$this->testEmail, password_hash($this->testPassword, PASSWORD_BCRYPT, ['cost' => 12]), 'Существующий Пользователь']);
            
            // Пытаемся зарегистрироваться с тем же email
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../register.php',
                [
                    'email' => $this->testEmail,
                    'password' => $this->testPassword,
                    'password_confirm' => $this->testPassword,
                    'full_name' => 'Новый Пользователь'
                ],
                'POST'
            );
            
            // Проверяем, что пользователь не был создан повторно
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE email = ?");
            $stmt->execute([$this->testEmail]);
            $count = $stmt->fetch()['count'];
            
            $r->assertEquals(1, $count, 'Должен быть только один пользователь с таким email');
        });
    }
    
    /**
     * Тест входа с валидными данными
     */
    public function testLoginValid()
    {
        $this->runner->runTest('Вход с валидными данными', function($r) {
            // Убеждаемся, что пользователь существует
            $pdo = getTestDBConnection();
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$this->testEmail]);
            $user = $stmt->fetch();
            
            if (!$user) {
                // Создаем пользователя для теста
                $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, is_active) VALUES (?, ?, ?, 1)");
                $stmt->execute([$this->testEmail, password_hash($this->testPassword, PASSWORD_BCRYPT, ['cost' => 12]), 'Тестовый Пользователь']);
                $this->testUserId = $pdo->lastInsertId();
            } else {
                $this->testUserId = $user['id'];
            }
            
            // Выполняем вход
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../login.php',
                [
                    'email' => $this->testEmail,
                    'password' => $this->testPassword
                ],
                'POST'
            );
            
            // Проверяем, что сессия создана
            $r->assertTrue(isset($_SESSION['user_id']), 'Сессия должна быть создана');
            $r->assertEquals($this->testUserId, $_SESSION['user_id'], 'ID пользователя в сессии должен совпадать');
            $r->assertEquals($this->testEmail, $_SESSION['user_email'], 'Email в сессии должен совпадать');
        });
    }
    
    /**
     * Тест входа с невалидными данными
     */
    public function testLoginInvalid()
    {
        $this->runner->runTest('Вход с невалидными данными', function($r) {
            // Очищаем сессию
            $_SESSION = [];
            
            // Пытаемся войти с неверным паролем
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../login.php',
                [
                    'email' => $this->testEmail,
                    'password' => 'WrongPassword123!'
                ],
                'POST'
            );
            
            // Проверяем, что сессия не создана
            $r->assertFalse(isset($_SESSION['user_id']), 'Сессия не должна быть создана при неверном пароле');
        });
    }
    
    /**
     * Тест валидации email
     */
    public function testEmailValidation()
    {
        $this->runner->runTest('Валидация email', function($r) {
            $invalidEmails = [
                'invalid-email',
                'test@',
                '@domain.com',
                'test..test@domain.com',
                ''
            ];
            
            foreach ($invalidEmails as $email) {
                $result = $this->runner->executeLocalFile(
                    __DIR__ . '/../../register.php',
                    [
                        'email' => $email,
                        'password' => $this->testPassword,
                        'password_confirm' => $this->testPassword,
                        'full_name' => 'Тест'
                    ],
                    'POST'
                );
                
                // Проверяем, что пользователь не был создан
                $pdo = getTestDBConnection();
                $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                $r->assertFalse($user !== false, "Пользователь с невалидным email '$email' не должен быть создан");
            }
        });
    }
    
    /**
     * Тест валидации пароля
     */
    public function testPasswordValidation()
    {
        $this->runner->runTest('Валидация пароля', function($r) {
            $shortPassword = 'Short1!'; // Меньше 8 символов
            
            $result = $this->runner->executeLocalFile(
                __DIR__ . '/../../register.php',
                [
                    'email' => 'test_password_' . time() . '@smartbizsell.ru',
                    'password' => $shortPassword,
                    'password_confirm' => $shortPassword,
                    'full_name' => 'Тест'
                ],
                'POST'
            );
            
            // Проверяем, что пользователь не был создан
            $pdo = getTestDBConnection();
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE email LIKE 'test_password_%'");
            $stmt->execute();
            $count = $stmt->fetch()['count'];
            
            $r->assertEquals(0, $count, 'Пользователь с коротким паролем не должен быть создан');
        });
    }
    
    /**
     * Очистка тестовых данных после тестов
     * Вызывается только если транзакции отключены (TEST_USE_TRANSACTIONS = false)
     */
    public function cleanup()
    {
        // Если транзакции включены, очистка не нужна - все изменения автоматически откатываются
        if ($this->runner->isTransactionsEnabled()) {
            return;
        }
        
        if ($this->testUserId) {
            try {
                $pdo = getTestDBConnection();
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$this->testUserId]);
            } catch (Exception $e) {
                // Игнорируем ошибки при очистке
            }
        }
        
        // Также удаляем по email на случай, если ID не был сохранен
        try {
            $pdo = getTestDBConnection();
            $stmt = $pdo->prepare("DELETE FROM users WHERE email = ?");
            $stmt->execute([$this->testEmail]);
        } catch (Exception $e) {
            // Игнорируем ошибки
        }
    }
}
