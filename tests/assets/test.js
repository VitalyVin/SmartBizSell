/**
 * JavaScript для тестового модуля
 * Совместимо с Safari
 */

document.addEventListener('DOMContentLoaded', function() {
    var runAllBtn = document.getElementById('run-all-btn');
    var clearResultsBtn = document.getElementById('clear-results-btn');
    var testClassLinks = document.querySelectorAll('.test-class-link');
    var runClassBtns = document.querySelectorAll('.btn-run-class');
    var testResults = document.getElementById('test-results');
    var testDetails = document.getElementById('test-details');
    var testStats = document.getElementById('test-stats');
    
    var currentResults = [];
    
    // Проверка поддержки fetch
    if (typeof fetch === 'undefined') {
        testResults.innerHTML = '<div class="error-item">Ваш браузер не поддерживает Fetch API. Пожалуйста, обновите Safari до последней версии.</div>';
        return;
    }
    
    // Запуск всех тестов
    if (runAllBtn) {
        runAllBtn.addEventListener('click', function() {
            runAllBtn.disabled = true;
            runAllBtn.textContent = 'Запуск...';
            testResults.innerHTML = '<div class="test-placeholder">Запуск всех тестов...</div>';
            
            fetch('?action=run_all', {
                method: 'GET',
                cache: 'no-cache',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('HTTP ошибка: ' + response.status + ' ' + response.statusText);
                }
                return response.text();
            })
            .then(function(text) {
                try {
                    var data = JSON.parse(text);
                    if (!data.success) {
                        var errorMsg = 'Ошибка выполнения тестов';
                        if (data.error) {
                            errorMsg += ': ' + data.error;
                        }
                        if (data.file) {
                            errorMsg += '<br>Файл: ' + escapeHtml(data.file);
                            if (data.line) {
                                errorMsg += ':' + data.line;
                            }
                        }
                        testResults.innerHTML = '<div class="error-item">' + errorMsg + '</div>';
                        if (data.trace) {
                            testResults.innerHTML += '<pre style="font-size: 11px; overflow-x: auto;">' + escapeHtml(data.trace) + '</pre>';
                        }
                        runAllBtn.disabled = false;
                        runAllBtn.textContent = 'Запустить все тесты';
                        return;
                    }
                    currentResults = data.results || [];
                    displayResults(currentResults);
                    updateStats(data.stats || { total: 0, passed: 0, failed: 0 });
                } catch (e) {
                    console.error('Ошибка парсинга JSON:', e);
                    console.error('Ответ сервера:', text);
                    testResults.innerHTML = '<div class="error-item">Ошибка парсинга ответа сервера. Проверьте консоль браузера.<br>Ответ: <pre style="font-size: 11px;">' + escapeHtml(text.substring(0, 500)) + '</pre></div>';
                }
                runAllBtn.disabled = false;
                runAllBtn.textContent = 'Запустить все тесты';
            })
            .catch(function(error) {
                console.error('Error:', error);
                testResults.innerHTML = '<div class="error-item">Ошибка при выполнении тестов: ' + escapeHtml(error.message || 'Неизвестная ошибка') + '</div>';
                runAllBtn.disabled = false;
                runAllBtn.textContent = 'Запустить все тесты';
            });
        });
    }
    
    // Очистка результатов
    if (clearResultsBtn) {
        clearResultsBtn.addEventListener('click', function() {
            currentResults = [];
            testResults.innerHTML = '<p class="test-placeholder">Результаты очищены</p>';
            var detailsContent = testDetails.querySelector('.test-details-content');
            if (detailsContent) {
                detailsContent.innerHTML = '<p class="test-placeholder">Выберите тест для просмотра деталей</p>';
            }
            updateStats({ total: 0, passed: 0, failed: 0 });
        });
    }
    
    // Запуск тестов класса
    for (var i = 0; i < runClassBtns.length; i++) {
        (function(btn) {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var className = btn.getAttribute('data-class');
                runTestClass(className);
            });
        })(runClassBtns[i]);
    }
    
    // Выбор тестового класса
    for (var j = 0; j < testClassLinks.length; j++) {
        (function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                for (var k = 0; k < testClassLinks.length; k++) {
                    testClassLinks[k].classList.remove('active');
                }
                link.classList.add('active');
                
                var className = link.getAttribute('data-class');
                showTestClassInfo(className);
            });
        })(testClassLinks[j]);
    }
    
    // Функция запуска тестов класса
    function runTestClass(className) {
        var btn = document.querySelector('.btn-run-class[data-class="' + className + '"]');
        if (btn) {
            btn.disabled = true;
            btn.textContent = '⏳';
        }
        
        testResults.innerHTML = '<div class="test-placeholder">Запуск тестов класса ' + escapeHtml(className) + '...</div>';
        
        fetch('?action=run&class=' + encodeURIComponent(className), {
            method: 'GET',
            cache: 'no-cache',
            headers: {
                'Cache-Control': 'no-cache'
            }
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ошибка: ' + response.status + ' ' + response.statusText);
            }
            return response.text();
        })
        .then(function(text) {
            try {
                var data = JSON.parse(text);
                if (!data.success) {
                    var errorMsg = 'Ошибка выполнения тестов';
                    if (data.error) {
                        errorMsg += ': ' + data.error;
                    }
                    if (data.file) {
                        errorMsg += '<br>Файл: ' + escapeHtml(data.file);
                        if (data.line) {
                            errorMsg += ':' + data.line;
                        }
                    }
                    testResults.innerHTML = '<div class="error-item">' + errorMsg + '</div>';
                    if (data.trace) {
                        testResults.innerHTML += '<pre style="font-size: 11px; overflow-x: auto;">' + escapeHtml(data.trace) + '</pre>';
                    }
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = '▶';
                    }
                    return;
                }
                currentResults = data.results || [];
                displayResults(currentResults);
                updateStats(data.stats || { total: 0, passed: 0, failed: 0 });
            } catch (e) {
                console.error('Ошибка парсинга JSON:', e);
                console.error('Ответ сервера:', text);
                testResults.innerHTML = '<div class="error-item">Ошибка парсинга ответа сервера. Проверьте консоль браузера.<br>Ответ: <pre style="font-size: 11px;">' + escapeHtml(text.substring(0, 500)) + '</pre></div>';
            }
            if (btn) {
                btn.disabled = false;
                btn.textContent = '▶';
            }
        })
        .catch(function(error) {
            console.error('Error:', error);
            testResults.innerHTML = '<div class="error-item">Ошибка при выполнении тестов: ' + escapeHtml(error.message || 'Неизвестная ошибка') + '</div>';
            if (btn) {
                btn.disabled = false;
                btn.textContent = '▶';
            }
        });
    }
    
    // Отображение результатов
    function displayResults(results) {
        if (!results || results.length === 0) {
            testResults.innerHTML = '<p class="test-placeholder">Нет результатов тестов</p>';
            return;
        }
        
        var html = '';
        for (var i = 0; i < results.length; i++) {
            var result = results[i];
            var statusClass = result.status;
            var statusText = result.status === 'passed' ? 'Пройден' : result.status === 'failed' ? 'Провален' : 'Выполняется';
            var duration = result.duration || 0;
            var errorsCount = result.errors && result.errors.length > 0 ? result.errors.length : 0;
            
            html += '<div class="test-item ' + statusClass + '" data-index="' + i + '">';
            html += '<div class="test-item-header">';
            html += '<span class="test-item-name">' + escapeHtml(result.name) + '</span>';
            html += '<span class="test-item-status ' + statusClass + '">' + statusText + '</span>';
            html += '</div>';
            html += '<div class="test-item-duration">Время выполнения: ' + duration + 's</div>';
            if (errorsCount > 0) {
                html += '<div style="margin-top: 8px; color: #ef4444; font-size: 12px;">Ошибок: ' + errorsCount + '</div>';
            }
            html += '</div>';
        }
        
        testResults.innerHTML = html;
        
        // Добавляем обработчики кликов для просмотра деталей
        var testItems = document.querySelectorAll('.test-item');
        for (var j = 0; j < testItems.length; j++) {
            (function(item, idx) {
                item.addEventListener('click', function() {
                    var index = parseInt(item.getAttribute('data-index'));
                    showTestDetails(results[index]);
                });
            })(testItems[j], j);
        }
    }
    
    // Отображение деталей теста
    function showTestDetails(result) {
        var detailsContent = testDetails.querySelector('.test-details-content');
        if (!detailsContent) {
            return;
        }
        
        var statusText = result.status === 'passed' ? 'Пройден' : result.status === 'failed' ? 'Провален' : 'Выполняется';
        var duration = result.duration || 0;
        
        var html = '<div class="test-detail-section">';
        html += '<h4>Название</h4>';
        html += '<p>' + escapeHtml(result.name) + '</p>';
        html += '</div>';
        
        html += '<div class="test-detail-section">';
        html += '<h4>Статус</h4>';
        html += '<p><span class="test-item-status ' + result.status + '">' + statusText + '</span></p>';
        html += '</div>';
        
        html += '<div class="test-detail-section">';
        html += '<h4>Время выполнения</h4>';
        html += '<p>' + duration + ' секунд</p>';
        html += '</div>';
        
        if (result.assertions && result.assertions.length > 0) {
            html += '<div class="test-detail-section">';
            html += '<h4>Проверки (' + result.assertions.length + ')</h4>';
            for (var i = 0; i < result.assertions.length; i++) {
                var assertion = result.assertions[i];
                var assertionClass = assertion.passed ? 'passed' : 'failed';
                var assertionSymbol = assertion.passed ? '✓' : '✗';
                var assertionText = assertion.message || assertion.type || '';
                html += '<div class="assertion-item ' + assertionClass + '">';
                html += assertionSymbol + ' ' + escapeHtml(assertionText);
                html += '</div>';
            }
            html += '</div>';
        }
        
        if (result.errors && result.errors.length > 0) {
            html += '<div class="test-detail-section">';
            html += '<h4>Ошибки (' + result.errors.length + ')</h4>';
            for (var j = 0; j < result.errors.length; j++) {
                var error = result.errors[j];
                html += '<div class="error-item">';
                html += '<strong>' + escapeHtml(error.message || 'Ошибка') + '</strong>';
                if (error.file) {
                    html += '<div style="margin-top: 4px; font-size: 11px;">Файл: ' + escapeHtml(error.file);
                    if (error.line) {
                        html += ':' + escapeHtml(String(error.line));
                    }
                    html += '</div>';
                }
                if (error.trace) {
                    html += '<pre>' + escapeHtml(error.trace) + '</pre>';
                }
                html += '</div>';
            }
            html += '</div>';
        }
        
        if (result.warnings && result.warnings.length > 0) {
            html += '<div class="test-detail-section">';
            html += '<h4>Предупреждения (' + result.warnings.length + ')</h4>';
            for (var k = 0; k < result.warnings.length; k++) {
                html += '<div class="warning-item">';
                html += escapeHtml(result.warnings[k]);
                html += '</div>';
            }
            html += '</div>';
        }
        
        detailsContent.innerHTML = html;
    }
    
    // Показать информацию о тестовом классе
    function showTestClassInfo(className) {
        var detailsContent = testDetails.querySelector('.test-details-content');
        if (!detailsContent) {
            return;
        }
        
        var html = '<div class="test-detail-section">';
        html += '<h4>Класс</h4>';
        html += '<p>' + escapeHtml(className) + '</p>';
        html += '</div>';
        html += '<div class="test-detail-section">';
        html += '<p>Нажмите кнопку ▶ для запуска всех тестов этого класса</p>';
        html += '</div>';
        
        detailsContent.innerHTML = html;
    }
    
    // Обновление статистики
    function updateStats(stats) {
        var statTotal = document.getElementById('stat-total');
        var statPassed = document.getElementById('stat-passed');
        var statFailed = document.getElementById('stat-failed');
        
        if (statTotal) {
            statTotal.textContent = stats.total || 0;
        }
        if (statPassed) {
            statPassed.textContent = stats.passed || 0;
        }
        if (statFailed) {
            statFailed.textContent = stats.failed || 0;
        }
    }
    
    // Экранирование HTML
    function escapeHtml(text) {
        if (text === null || text === undefined) {
            return '';
        }
        var div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }
});
