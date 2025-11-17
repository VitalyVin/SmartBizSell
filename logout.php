<?php
require_once 'config.php';

// Уничтожение сессии
session_destroy();

// Редирект на главную страницу
header('Location: index.php');
exit;

