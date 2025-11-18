/**
 * SmartBizSell.ru - Основной JavaScript файл
 * 
 * Содержит:
 * - Управление мобильным меню
 * - Плавную прокрутку по якорям
 * - Функциональность карточек бизнесов (фильтрация, модальные окна)
 * - Динамическое добавление строк в таблицах формы
 * - Условное отключение полей формы (production, retail, online)
 * - Анимации карточек при загрузке
 * 
 * @version 1.0
 */

// Debug: Script loaded
console.log('SmartBizSell script.js loaded at:', new Date().toISOString());

/**
 * Мобильное меню - переключение видимости на мобильных устройствах
 */
const navToggle = document.querySelector('.nav-toggle');
const navMenu = document.querySelector('.nav-menu');

if (navToggle) {
    navToggle.addEventListener('click', () => {
        navMenu.classList.toggle('active');
        navToggle.classList.toggle('active');
    });
}

/**
 * Закрытие мобильного меню при клике на ссылку
 */
const navLinks = document.querySelectorAll('.nav-menu a');
navLinks.forEach(link => {
    link.addEventListener('click', () => {
        navMenu.classList.remove('active');
        navToggle.classList.remove('active');
    });
});

/**
 * Плавная прокрутка к якорным ссылкам
 * Учитывает высоту фиксированной навигации (offset 80px)
 */
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            const offsetTop = target.offsetTop - 80;
            window.scrollTo({
                top: offsetTop,
                behavior: 'smooth'
            });
        }
    });
});

/**
 * Изменение стиля навигации при прокрутке страницы
 * Добавляет тень при прокрутке вниз
 */
const navbar = document.querySelector('.navbar');
let lastScroll = 0;

window.addEventListener('scroll', () => {
    const currentScroll = window.pageYOffset;
    
    if (currentScroll > 50) {
        navbar.style.boxShadow = '0 4px 16px rgba(0, 0, 0, 0.08)';
    } else {
        navbar.style.boxShadow = 'none';
    }
    
    lastScroll = currentScroll;
});

// Intersection Observer for fade-in animations
const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
};

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.style.opacity = '1';
            entry.target.style.transform = 'translateY(0)';
        }
    });
}, observerOptions);

// Animate elements on scroll
const animateElements = document.querySelectorAll('.feature-card, .step-item, .contact-card');
animateElements.forEach(el => {
    el.style.opacity = '0';
    el.style.transform = 'translateY(20px)';
    el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
    observer.observe(el);
});

/**
 * Улучшенная валидация формы на клиентской стороне
 * Проверка полей в реальном времени при вводе данных
 */
const form = document.querySelector('.seller-form');
if (form) {
    const inputs = form.querySelectorAll('input, select, textarea');
    
    inputs.forEach(input => {
        input.addEventListener('blur', () => {
            validateField(input);
        });
        
        input.addEventListener('input', () => {
            if (input.classList.contains('error')) {
                validateField(input);
            }
        });
    });
    
    form.addEventListener('submit', (e) => {
        let isValid = true;
        
        inputs.forEach(input => {
            if (!validateField(input)) {
                isValid = false;
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            const firstError = form.querySelector('.error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    });
}

/**
 * Валидация отдельного поля формы
 * Проверяет обязательность, формат и другие правила валидации
 * @param {HTMLElement} field - Поле для валидации
 * @returns {boolean} true если поле валидно, false если есть ошибки
 */
function validateField(field) {
    const value = field.value.trim();
    let isValid = true;
    let errorMessage = '';
    
    // Remove existing error styles
    field.classList.remove('error');
    const existingError = field.parentElement.querySelector('.error-message');
    if (existingError) {
        existingError.remove();
    }
    
    // Required field validation
    if (field.hasAttribute('required') && !value) {
        isValid = false;
        errorMessage = 'Это поле обязательно для заполнения';
    }
    
    // Email validation
    if (field.type === 'email' && value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
            errorMessage = 'Введите корректный email адрес';
        }
    }
    
    // Phone validation
    if (field.type === 'tel' && value) {
        const phoneRegex = /^[\d\s\-\+\(\)]+$/;
        if (!phoneRegex.test(value) || value.length < 10) {
            isValid = false;
            errorMessage = 'Введите корректный номер телефона';
        }
    }
    
    // Number validation
    if (field.type === 'number' && value) {
        if (parseFloat(value) < 0) {
            isValid = false;
            errorMessage = 'Значение не может быть отрицательным';
        }
    }
    
    // Show error if validation failed
    if (!isValid) {
        field.classList.add('error');
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        errorDiv.textContent = errorMessage;
        errorDiv.style.color = 'var(--accent-color)';
        errorDiv.style.fontSize = '12px';
        errorDiv.style.marginTop = '4px';
        field.parentElement.appendChild(errorDiv);
    }
    
    return isValid;
}

// Add error styles to CSS dynamically
const style = document.createElement('style');
style.textContent = `
    .form-group input.error,
    .form-group select.error,
    .form-group textarea.error {
        border-color: var(--accent-color);
        box-shadow: 0 0 0 3px rgba(255, 59, 48, 0.1);
    }
`;
document.head.appendChild(style);

/**
 * Параллакс эффект для hero секции
 * Градиентные орбы двигаются с разной скоростью при прокрутке
 */
window.addEventListener('scroll', () => {
    const scrolled = window.pageYOffset;
    const hero = document.querySelector('.hero-background');
    if (hero) {
        const orbs = hero.querySelectorAll('.gradient-orb');
        orbs.forEach((orb, index) => {
            const speed = 0.5 + (index * 0.2);
            orb.style.transform = `translateY(${scrolled * speed}px)`;
        });
    }
});

/**
 * Анимация счетчиков для статистики
 * Плавное увеличение чисел от 0 до целевого значения
 * @param {HTMLElement} element - Элемент для анимации
 * @param {number} target - Целевое значение
 * @param {number} duration - Длительность анимации в мс
 */
const animateCounter = (element, target, duration = 2000) => {
    let start = 0;
    const increment = target / (duration / 16);
    const timer = setInterval(() => {
        start += increment;
        if (start >= target) {
            element.textContent = target + (element.textContent.includes('+') ? '+' : '');
            clearInterval(timer);
        } else {
            element.textContent = Math.floor(start) + (element.textContent.includes('+') ? '+' : '');
        }
    }, 16);
};

// Observe stats section for counter animation
const statsSection = document.querySelector('.hero-stats');
if (statsSection) {
    const statsObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const statNumbers = entry.target.querySelectorAll('.stat-number');
                statNumbers.forEach(stat => {
                    const text = stat.textContent;
                    const number = parseInt(text.replace(/\D/g, ''));
                    if (number && !stat.dataset.animated) {
                        stat.dataset.animated = 'true';
                        stat.textContent = '0' + (text.includes('+') ? '+' : '') + (text.includes('ч') ? 'ч' : '');
                        animateCounter(stat, number, 2000);
                    }
                });
                statsObserver.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });
    
    statsObserver.observe(statsSection);
}

// Add loading state to form submit button
if (form) {
    form.addEventListener('submit', function(e) {
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton && !submitButton.disabled) {
            submitButton.disabled = true;
            const originalText = submitButton.innerHTML;
            submitButton.innerHTML = '<span>Обработка...</span>';
            
            // Re-enable after 3 seconds if form doesn't submit (fallback)
            setTimeout(() => {
                if (submitButton.disabled) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalText;
                }
            }, 3000);
        }
    });
}

// Add focus styles for accessibility
document.addEventListener('keydown', (e) => {
    if (e.key === 'Tab') {
        document.body.classList.add('keyboard-nav');
    }
});

document.addEventListener('mousedown', () => {
    document.body.classList.remove('keyboard-nav');
});

// Add keyboard navigation styles
const keyboardStyle = document.createElement('style');
keyboardStyle.textContent = `
    .keyboard-nav *:focus {
        outline: 2px solid var(--primary-color);
        outline-offset: 2px;
    }
`;
document.head.appendChild(keyboardStyle);

// Lazy loading for images (if any are added later)
if ('IntersectionObserver' in window) {
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                if (img.dataset.src) {
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    observer.unobserve(img);
                }
            }
        });
    });
    
    document.querySelectorAll('img[data-src]').forEach(img => {
        imageObserver.observe(img);
    });
}

/**
 * Функциональность фильтрации карточек бизнесов
 * 
 * Фильтры:
 * - По отрасли (IT, рестораны, e-commerce и т.д.)
 * - По максимальной цене
 * - По городу/региону
 * 
 * Карточки скрываются/показываются в зависимости от выбранных фильтров
 */
const filterIndustry = document.getElementById('filter-industry');
const filterPrice = document.getElementById('filter-price');
const filterLocation = document.getElementById('filter-location');
const businessesGrid = document.getElementById('businesses-grid');
const noResults = document.getElementById('no-results');
const businessCards = document.querySelectorAll('.business-card');

/**
 * Фильтрация карточек бизнесов по выбранным критериям
 * Показывает сообщение "Нет результатов", если ничего не найдено
 */
function filterBusinesses() {
    const industryValue = filterIndustry?.value || '';
    const priceValue = filterPrice?.value || '';
    const locationValue = filterLocation?.value || '';
    
    let visibleCount = 0;
    
    businessCards.forEach(card => {
        const cardIndustry = card.getAttribute('data-industry');
        const cardPrice = parseInt(card.getAttribute('data-price'));
        const cardLocation = card.getAttribute('data-location');
        
        let shouldShow = true;
        
        // Filter by industry
        if (industryValue && cardIndustry !== industryValue) {
            shouldShow = false;
        }
        
        // Filter by price
        if (priceValue && cardPrice > parseInt(priceValue)) {
            shouldShow = false;
        }
        
        // Filter by location
        if (locationValue) {
            if (locationValue === 'other' && ['moscow', 'spb', 'ekb'].includes(cardLocation)) {
                shouldShow = false;
            } else if (locationValue !== 'other' && cardLocation !== locationValue) {
                shouldShow = false;
            }
        }
        
        if (shouldShow) {
            card.classList.remove('hidden');
            card.style.display = '';
            visibleCount++;
        } else {
            card.classList.add('hidden');
            card.style.display = 'none';
        }
    });
    
    // Show/hide "no results" message
    if (noResults) {
        if (visibleCount === 0) {
            noResults.style.display = 'block';
            businessesGrid.style.display = 'none';
        } else {
            noResults.style.display = 'none';
            businessesGrid.style.display = 'grid';
        }
    }
}

// Add event listeners to filters
if (filterIndustry) {
    filterIndustry.addEventListener('change', filterBusinesses);
}

if (filterPrice) {
    filterPrice.addEventListener('change', filterBusinesses);
}

if (filterLocation) {
    filterLocation.addEventListener('change', filterBusinesses);
}

// Make logo clickable to scroll to top
const logos = document.querySelectorAll('.logo, .footer-logo');
logos.forEach(logo => {
    if (logo) {
        logo.addEventListener('click', (e) => {
            e.preventDefault();
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
            // Close mobile menu if open
            if (navMenu) {
                navMenu.classList.remove('active');
            }
            if (navToggle) {
                navToggle.classList.remove('active');
            }
        });
    }
});

/**
 * Динамическое добавление строк в таблицах формы
 * 
 * Позволяет пользователю добавлять дополнительные строки в таблицы:
 * - Объемы производства
 * - Финансовые показатели
 * 
 * При добавлении новой строки все поля очищаются
 */
const addRowButtons = document.querySelectorAll('[data-add-row]');
addRowButtons.forEach(button => {
    button.addEventListener('click', () => {
        const targetSelector = button.dataset.addRow;
        if (!targetSelector) return;
        const table = document.querySelector(targetSelector);
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const templateRow = tbody.querySelector('tr');
        if (!templateRow) return;
        const newRow = templateRow.cloneNode(true);
        newRow.querySelectorAll('input').forEach(input => {
            if (input.type === 'radio' || input.type === 'checkbox') {
                input.checked = false;
            } else {
                input.value = '';
            }
        });
        tbody.appendChild(newRow);
    });
});

/**
 * Инициализация функциональности условного отключения полей формы
 * 
 * Обрабатывает следующие разделы:
 * - Собственные производственные мощности (own_production)
 * - Контрактное производство (contract_production_usage)
 * - Офлайн-продажи (offline_sales_presence)
 * - Онлайн-продажи (online_sales_presence)
 * 
 * При выборе "нет" соответствующие поля становятся неактивными
 */
function initProductionToggle() {
    console.log('>>> TOGGLE: initProductionToggle called at', new Date().toISOString());

    const toggleSections = document.querySelectorAll('[data-toggle-source]');
    console.log('>>> TOGGLE: Found toggle sections:', toggleSections.length);

    if (toggleSections.length === 0) {
        console.warn('Toggle sections: none found on the page');
        return;
    }

    toggleSections.forEach(section => {
        const sourceName = section.dataset.toggleSource;
        const disableValue = section.dataset.disableValue || 'no';

        if (!sourceName) {
            console.warn('Toggle section missing data-toggle-source attribute', section);
            return;
        }

        if (section.dataset.toggleInitialized === 'true') {
            console.log('>>> TOGGLE: Section already initialized for', sourceName);
            return;
        }

        const radios = document.querySelectorAll(`input[name="${sourceName}"]`);
        console.log(`>>> TOGGLE: Radios for ${sourceName}:`, radios.length);

        if (radios.length === 0) {
            console.warn(`Toggle: No radios found for ${sourceName}`);
            return;
        }

        const updateSection = () => {
            const selected = document.querySelector(`input[name="${sourceName}"]:checked`);
            const shouldDisable = selected && selected.value === disableValue;

            console.log(`>>> TOGGLE: [${sourceName}] selected:`, selected ? selected.value : 'none', '=> disable:', shouldDisable);

            section.classList.toggle('is-disabled', shouldDisable);

            section.querySelectorAll('input, select, textarea, button').forEach(field => {
                field.disabled = shouldDisable;
            });

            section.style.pointerEvents = shouldDisable ? 'none' : '';
            section.style.opacity = shouldDisable ? '0.4' : '1';
        };

        radios.forEach(radio => {
            radio.addEventListener('change', updateSection);
        });

        section.dataset.toggleInitialized = 'true';
        updateSection();
    });
}

/**
 * Инициализация функциональности переключения полей при загрузке DOM
 * Используется задержка 100ms для гарантии полной загрузки PHP-сгенерированного контента
 */
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded fired, initializing toggle sections');
    setTimeout(initProductionToggle, 100);
});

/**
 * Резервная инициализация при полной загрузке страницы
 * Проверяет, не осталось ли неинициализированных секций
 */
window.addEventListener('load', function() {
    console.log('Window load fired, verifying toggle sections');
    setTimeout(function() {
        const uninitializedSection = document.querySelector('[data-toggle-source]:not([data-toggle-initialized="true"])');
        if (typeof initProductionToggle === 'function' && uninitializedSection) {
            console.log('Re-initializing toggle sections on window load');
            initProductionToggle();
        }
    }, 200);
});

/**
 * Функциональность модальных окон для карточек бизнесов
 * 
 * Позволяет открывать детальную информацию о бизнесе в модальном окне
 * Данные берутся из data-атрибутов карточки
 */
const businessModal = document.getElementById('business-modal');
const modalCloseBtn = document.querySelector('.modal-close');
const modalCloseBtnFooter = document.getElementById('modal-close-btn');
const modalContactBtn = document.getElementById('modal-contact-btn');
const viewDetailsButtons = document.querySelectorAll('.card-button, .btn-view-details');

/**
 * Форматирование чисел с пробелами (разделитель тысяч)
 * @param {number} num - Число для форматирования
 * @returns {string} Отформатированное число
 */
function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}

/**
 * Форматирование валюты
 * @param {number} num - Сумма
 * @returns {string} Отформатированная сумма с символом рубля
 */
function formatCurrency(num) {
    return formatNumber(num) + ' ₽';
}

/**
 * Открытие модального окна с данными о бизнесе
 * @param {HTMLElement} card - Элемент карточки бизнеса
 */
function openBusinessModal(card) {
    const iconElement = card.querySelector('.card-icon');
    const icon = iconElement ? iconElement.textContent : '💼';
    const title = card.getAttribute('data-title');
    const locationElement = card.querySelector('.card-location');
    const location = locationElement ? locationElement.textContent : card.getAttribute('data-location');
    const revenue = parseInt(card.getAttribute('data-revenue'));
    const profit = parseInt(card.getAttribute('data-profit'));
    const growth = card.getAttribute('data-growth');
    const price = parseInt(card.getAttribute('data-price'));
    const employees = card.getAttribute('data-employees');
    const years = card.getAttribute('data-years');
    const fullDescription = card.getAttribute('data-full-description');
    const advantages = card.getAttribute('data-advantages').split('|');
    const risks = card.getAttribute('data-risks').split('|');
    const contact = card.getAttribute('data-contact');
    const badge = card.querySelector('.card-badge');
    
    // Set icon
    const modalIcon = document.getElementById('modal-icon');
    if (modalIcon) modalIcon.textContent = icon;
    
    // Set title and location
    const modalTitle = document.getElementById('modal-title');
    if (modalTitle) modalTitle.textContent = title;
    const modalLocation = document.getElementById('modal-location');
    if (modalLocation) modalLocation.textContent = location;
    
    // Set badge
    const modalBadgeEl = document.getElementById('modal-badge');
    if (modalBadgeEl) {
        if (badge) {
            modalBadgeEl.innerHTML = badge.outerHTML;
            modalBadgeEl.style.display = 'block';
        } else {
            modalBadgeEl.style.display = 'none';
        }
    }
    
    // Set financial data
    document.getElementById('modal-revenue').textContent = formatCurrency(revenue);
    document.getElementById('modal-profit').textContent = formatCurrency(profit);
    document.getElementById('modal-growth').textContent = growth + '%';
    document.getElementById('modal-price').textContent = formatCurrency(price);
    
    // Set info
    document.getElementById('modal-employees').textContent = employees;
    document.getElementById('modal-years').textContent = years + ' лет';
    
    // Set description
    document.getElementById('modal-description').textContent = fullDescription;
    
    // Set advantages
    const advantagesList = document.getElementById('modal-advantages');
    advantagesList.innerHTML = '';
    advantages.forEach(advantage => {
        const li = document.createElement('li');
        li.textContent = advantage.trim();
        advantagesList.appendChild(li);
    });
    
    // Set risks
    const risksList = document.getElementById('modal-risks');
    risksList.innerHTML = '';
    risks.forEach(risk => {
        const li = document.createElement('li');
        li.textContent = risk.trim();
        risksList.appendChild(li);
    });
    
    // Set contact
    const contactLink = document.getElementById('modal-contact');
    const contactText = document.getElementById('modal-contact-text');
    contactLink.href = 'tel:' + contact.replace(/\s/g, '');
    contactText.textContent = contact;
    
    // Show modal
    businessModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

/**
 * Закрытие модального окна с информацией о бизнесе
 * Восстанавливает прокрутку страницы
 */
function closeBusinessModal() {
    businessModal.classList.remove('active');
    document.body.style.overflow = '';
}

// Add event listeners to view details buttons
viewDetailsButtons.forEach(button => {
    button.addEventListener('click', (e) => {
        e.stopPropagation();
        const card = button.closest('.business-card');
        openBusinessModal(card);
    });
});

// Also allow clicking on card to open modal
businessCards.forEach(card => {
    card.addEventListener('click', (e) => {
        // Don't open if clicking on button
        if (!e.target.closest('.card-button')) {
            openBusinessModal(card);
        }
    });
});

// Close modal events
if (modalCloseBtn) {
    modalCloseBtn.addEventListener('click', closeBusinessModal);
}

if (modalCloseBtnFooter) {
    modalCloseBtnFooter.addEventListener('click', closeBusinessModal);
}

if (modalContactBtn) {
    modalContactBtn.addEventListener('click', () => {
        const contactLink = document.getElementById('modal-contact');
        if (contactLink) {
            window.location.href = contactLink.href;
        }
    });
}

// Close modal when clicking outside
if (businessModal) {
    businessModal.addEventListener('click', (e) => {
        if (e.target === businessModal) {
            closeBusinessModal();
        }
    });
}

// Close modal on Escape key
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && businessModal.classList.contains('active')) {
        closeBusinessModal();
    }
});

/**
 * Анимация карточек бизнесов при загрузке страницы
 * Карточки появляются с эффектом плавного появления снизу вверх
 */
function animateCardsOnLoad() {
    const cards = document.querySelectorAll('.business-card');
    cards.forEach((card, index) => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';

        setTimeout(() => {
            card.style.transition = 'all 0.6s cubic-bezier(0.4, 0, 0.2, 1)';
            card.style.opacity = '1';
            card.style.transform = 'translateY(0)';
        }, index * 100);
    });
}

// Initialize animations when page loads
document.addEventListener('DOMContentLoaded', () => {
    console.log('>>> MAIN: DOMContentLoaded fired, calling animateCardsOnLoad');
    animateCardsOnLoad();
});

// Test immediate call
console.log('>>> MAIN: Script execution started');
if (document.readyState === 'loading') {
    console.log('>>> MAIN: Document still loading, will try immediate init');
    setTimeout(() => {
        console.log('>>> MAIN: Trying immediate init after timeout');
        if (typeof initProductionToggle === 'function') {
            initProductionToggle();
        }
    }, 500);
}

console.log('SmartBizSell.ru - Platform loaded successfully');

