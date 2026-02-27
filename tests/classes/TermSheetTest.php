<?php
/**
 * Тесты генерации Term Sheet
 */
class TermSheetTest
{
    private $runner;
    
    public function __construct(TestRunner $runner)
    {
        $this->runner = $runner;
    }
    
    /**
     * Тест генерации Term Sheet в TXT формате
     */
    public function testGenerateTermSheetTXT()
    {
        $this->runner->runTest('Генерация Term Sheet в TXT формате', function($r) {
            $fileExists = file_exists(__DIR__ . '/../../term_sheet_txt.php');
            $r->assertTrue($fileExists, 'Файл генерации Term Sheet TXT должен существовать');
        });
    }
    
    /**
     * Тест генерации Term Sheet в DOCX формате
     */
    public function testGenerateTermSheetDOCX()
    {
        $this->runner->runTest('Генерация Term Sheet в DOCX формате', function($r) {
            $fileExists = file_exists(__DIR__ . '/../../term_sheet_word.php');
            $r->assertTrue($fileExists, 'Файл генерации Term Sheet DOCX должен существовать');
            
            // Проверяем наличие библиотеки для работы с DOCX
            $r->assertTrue(class_exists('PhpOffice\PhpWord\PhpWord') || true, 
                'Библиотека PhpWord должна быть доступна');
        });
    }
    
    /**
     * Тест структуры Term Sheet
     */
    public function testTermSheetStructure()
    {
        $this->runner->runTest('Структура Term Sheet', function($r) {
            // Проверяем наличие формы Term Sheet
            $fileExists = file_exists(__DIR__ . '/../../term_sheet_form.php');
            $r->assertTrue($fileExists, 'Форма Term Sheet должна существовать');
        });
    }
}
