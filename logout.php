<?php
/**
 * Выход из системы
 * 
 * Уничтожает сессию пользователя и перенаправляет на главную страницу
 * 
 * @package SmartBizSell
 * @version 1.0
 */

require_once 'config.php';

/**
 * Уничтожение сессии пользователя
 * Все данные сессии удаляются
 */
session_destroy();

/**
 * Редирект на главную страницу
 */
header('Location: index.php');
exit;

