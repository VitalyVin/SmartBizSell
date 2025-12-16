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
 * GSAP Animations - Плавные анимации в стиле Apple.com
 * Использует GSAP и ScrollTrigger для создания современных эффектов
 */
if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
    gsap.registerPlugin(ScrollTrigger);
    
    // Анимация hero-секции при загрузке
    gsap.from('.hero-title', {
        duration: 1.2,
        y: 60,
        opacity: 0,
        ease: 'power3.out',
        delay: 0.2
    });
    
    gsap.from('.hero-subtitle', {
        duration: 1,
        y: 40,
        opacity: 0,
        ease: 'power3.out',
        delay: 0.4
    });
    
    gsap.from('.hero-buttons', {
        duration: 1,
        y: 30,
        opacity: 0,
        ease: 'power3.out',
        delay: 0.6
    });
    
    // Анимация статистики с счетчиками
    const statItems = document.querySelectorAll('.stat-item');
    statItems.forEach((item, index) => {
        gsap.from(item, {
            duration: 0.8,
            y: 30,
            opacity: 0,
            ease: 'power2.out',
            delay: 0.8 + (index * 0.1),
            scrollTrigger: {
                trigger: item,
                start: 'top 80%',
                toggleActions: 'play none none none'
            }
        });
    });
    
    // Анимация карточек преимуществ при скролле
    gsap.utils.toArray('.feature-card').forEach((card, index) => {
        gsap.from(card, {
            duration: 0.8,
            y: 60,
            opacity: 0,
            ease: 'power3.out',
            scrollTrigger: {
                trigger: card,
                start: 'top 85%',
                toggleActions: 'play none none none'
            },
            delay: index * 0.1
        });
        
        // Hover эффект для иконок
        const icon = card.querySelector('.feature-icon');
        if (icon) {
            card.addEventListener('mouseenter', () => {
                gsap.to(icon, {
                    duration: 0.3,
                    scale: 1.1,
                    rotation: 5,
                    ease: 'power2.out'
                });
            });
            
            card.addEventListener('mouseleave', () => {
                gsap.to(icon, {
                    duration: 0.3,
                    scale: 1,
                    rotation: 0,
                    ease: 'power2.out'
                });
            });
        }
    });
    
    // Анимация шагов "Как это работает"
    gsap.utils.toArray('.step-item').forEach((step, index) => {
        gsap.from(step, {
            duration: 0.8,
            x: -60,
            opacity: 0,
            ease: 'power3.out',
            scrollTrigger: {
                trigger: step,
                start: 'top 85%',
                toggleActions: 'play none none none'
            },
            delay: index * 0.15
        });
    });
    
    // Анимация карточек бизнесов
    gsap.utils.toArray('.business-card').forEach((card, index) => {
        gsap.from(card, {
            duration: 0.8,
            y: 80,
            opacity: 0,
            scale: 0.9,
            ease: 'power3.out',
            scrollTrigger: {
                trigger: card,
                start: 'top 85%',
                toggleActions: 'play none none none'
            },
            delay: index * 0.1
        });
        
        // Плавное увеличение при hover
        card.addEventListener('mouseenter', () => {
            gsap.to(card, {
                duration: 0.4,
                scale: 1.02,
                y: -8,
                ease: 'power2.out'
            });
        });
        
        card.addEventListener('mouseleave', () => {
            gsap.to(card, {
                duration: 0.4,
                scale: 1,
                y: 0,
                ease: 'power2.out'
            });
        });
    });
    
    // Параллакс эффект для hero background
    // Используем отдельную анимацию для каждого орба с разной скоростью
    // Отключаем CSS анимацию и используем только GSAP для плавности
    gsap.utils.toArray('.gradient-orb').forEach((orb, index) => {
        const speed = 0.2 + (index * 0.1); // Разная скорость для каждого орба (0.2, 0.3, 0.4)
        const isOrb3 = orb.classList.contains('orb-3');
        
        // Добавляем класс для отключения CSS анимации
        orb.classList.add('gsap-parallax');
        
        // Для orb-3 сохраняем начальное позиционирование (translate(-50%, -50%))
        if (isOrb3) {
            gsap.set(orb, { xPercent: -50, yPercent: -50 });
        }
        
        // Применяем параллакс через y
        gsap.to(orb, {
            y: () => {
                return ScrollTrigger.maxScroll(window) * speed;
            },
            ease: 'none',
            force3D: true, // Используем GPU ускорение для плавности
            scrollTrigger: {
                trigger: '.hero',
                start: 'top top',
                end: 'bottom top',
                scrub: 1, // Плавная синхронизация со скроллом
                invalidateOnRefresh: true // Пересчитывать при изменении размера окна
            }
        });
    });
    
    // Анимация заголовков секций
    gsap.utils.toArray('.section-title').forEach((title) => {
        gsap.from(title, {
            duration: 1,
            y: 40,
            opacity: 0,
            ease: 'power3.out',
            scrollTrigger: {
                trigger: title,
                start: 'top 80%',
                toggleActions: 'play none none none'
            }
        });
    });
    
    // Анимация контактных карточек
    gsap.utils.toArray('.contact-card').forEach((card, index) => {
        gsap.from(card, {
            duration: 0.8,
            y: 40,
            opacity: 0,
            ease: 'power3.out',
            scrollTrigger: {
                trigger: card,
                start: 'top 85%',
                toggleActions: 'play none none none'
            },
            delay: index * 0.15
        });
    });
    
    // Плавная анимация навигации при скролле
    ScrollTrigger.create({
        start: 'top -80',
        end: 99999,
        toggleClass: { className: 'scrolled', targets: '.navbar' }
    });
}

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

// Fallback: Intersection Observer for fade-in animations (если GSAP не загружен)
if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') {
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
}

/**
 * Улучшенная валидация формы на клиентской стороне
 * Проверка полей в реальном времени при вводе данных
 */
const form = document.querySelector('.seller-form');
if (form) {
    const inputs = form.querySelectorAll('input, select, textarea');
    const saveDraftButton = form.querySelector('button[name="save_draft"]');
    const saveDraftFlagInput = form.querySelector('input[name="save_draft_flag"]');
    let skipClientValidation = false;

    if (saveDraftButton) {
        saveDraftButton.addEventListener('click', () => {
            skipClientValidation = true;
            if (saveDraftFlagInput) {
                saveDraftFlagInput.value = '1';
            }
        });
    }
    
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
        const isDraftSubmit = skipClientValidation || (e.submitter && e.submitter.name === 'save_draft');
        
        if (isDraftSubmit) {
            skipClientValidation = false;
            return; // Пропускаем клиентскую валидацию для черновика
        }

        if (saveDraftFlagInput) {
            saveDraftFlagInput.value = '0';
        }
        
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
 * Параллакс эффект для hero секции (fallback без GSAP)
 * Градиентные орбы двигаются с разной скоростью при прокрутке
 * Используется только если GSAP недоступен
 */
if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') {
    let ticking = false; // Флаг для оптимизации через requestAnimationFrame
    
    window.addEventListener('scroll', () => {
        if (!ticking) {
            window.requestAnimationFrame(() => {
                const scrolled = window.pageYOffset;
                const hero = document.querySelector('.hero-background');
                if (hero) {
                    const orbs = hero.querySelectorAll('.gradient-orb');
                    orbs.forEach((orb, index) => {
                        const speed = 0.3 + (index * 0.1);
                        // Сохраняем исходный transform для orb-3 (translate(-50%, -50%))
                        const isOrb3 = orb.classList.contains('orb-3');
                        if (isOrb3) {
                            orb.style.transform = `translate(-50%, calc(-50% + ${scrolled * speed}px))`;
                        } else {
                            orb.style.transform = `translateY(${scrolled * speed}px)`;
                        }
                    });
                }
                ticking = false;
            });
            ticking = true;
        }
    });
}

/**
 * Анимация счетчиков для статистики с использованием GSAP
 * Плавное увеличение чисел от 0 до целевого значения
 */
const initCounterAnimations = () => {
    const statItems = document.querySelectorAll('.stat-item[data-stat]');
    
    statItems.forEach((item) => {
        const statNumber = item.querySelector('.stat-number');
        if (!statNumber || statNumber.dataset.animated) return;
        
        const originalText = statNumber.textContent;
        const target = parseInt(item.dataset.stat);
        const hasPlus = originalText.includes('+');
        const hasHours = originalText.includes('ч');
        
        if (typeof gsap !== 'undefined') {
            // Используем GSAP для плавной анимации
            ScrollTrigger.create({
                trigger: item,
                start: 'top 80%',
                onEnter: () => {
                    if (statNumber.dataset.animated) return;
                    statNumber.dataset.animated = 'true';
                    
                    const obj = { value: 0 };
                    gsap.to(obj, {
                        value: target,
                        duration: 2,
                        ease: 'power2.out',
                        onUpdate: () => {
                            const val = Math.floor(obj.value);
                            statNumber.textContent = val + (hasPlus ? '+' : '') + (hasHours ? 'ч' : '');
                        }
                    });
                }
            });
        } else {
            // Fallback: простая анимация без GSAP
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !statNumber.dataset.animated) {
                        statNumber.dataset.animated = 'true';
                        let start = 0;
                        const increment = target / 120;
                        const timer = setInterval(() => {
                            start += increment;
                            if (start >= target) {
                                statNumber.textContent = target + (hasPlus ? '+' : '') + (hasHours ? 'ч' : '');
                                clearInterval(timer);
                            } else {
                                statNumber.textContent = Math.floor(start) + (hasPlus ? '+' : '') + (hasHours ? 'ч' : '');
                            }
                        }, 16);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            
            observer.observe(item);
        }
    });
};

// Инициализация анимации счетчиков
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCounterAnimations);
} else {
    initCounterAnimations();
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
async function openBusinessModal(card) {
    const iconElement = card.querySelector('.card-icon');
    const icon = iconElement ? iconElement.textContent : '💼';
    const title = card.getAttribute('data-title');
    const locationElement = card.querySelector('.card-location');
    const location = locationElement ? locationElement.textContent : card.getAttribute('data-location');
    const badge = card.querySelector('.card-badge');
    const teaserId = card.getAttribute('data-teaser-id');
    
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
    
    // Загружаем полный HTML тизера
    const teaserSection = document.getElementById('modal-teaser-section');
    const teaserContent = document.getElementById('modal-teaser-content');
    if (teaserId && teaserSection && teaserContent) {
        teaserContent.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 40px;">Загрузка тизера...</p>';
        teaserSection.style.display = 'block';
        
        try {
            const response = await fetch(`view_teaser.php?teaser_id=${teaserId}`);
            if (response.ok) {
                const html = await response.text();
                teaserContent.innerHTML = html;
                // Инициализируем графики после загрузки HTML
                // Используем setTimeout, чтобы дать браузеру время на рендеринг HTML
                setTimeout(() => {
                    // Ищем графики только внутри модального окна
                    const modalCharts = teaserContent.querySelectorAll('.teaser-chart[data-chart]');
                    console.log('Found', modalCharts.length, 'charts in modal');
                    if (modalCharts.length > 0) {
                        initTeaserCharts();
                    }
                    
                    // Загружаем документы актива
                    const sellerFormId = card.getAttribute('data-seller-form-id');
                    if (sellerFormId) {
                        loadAssetDocuments(sellerFormId);
                    }
                }, 200);
            } else {
                teaserContent.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 40px;">Не удалось загрузить тизер.</p>';
            }
        } catch (error) {
            console.error('Error loading teaser:', error);
            teaserContent.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 40px;">Ошибка загрузки тизера.</p>';
        }
    } else {
        teaserContent.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 40px;">Тизер не найден.</p>';
    }
    
    // Загружаем документы актива
    const sellerFormId = card.getAttribute('data-seller-form-id');
    if (sellerFormId) {
        loadAssetDocuments(sellerFormId);
        // Сохраняем sellerFormId в модальном окне для использования в кнопке "Связаться с продавцом"
        businessModal.setAttribute('data-seller-form-id', sellerFormId);
    } else {
        businessModal.removeAttribute('data-seller-form-id');
    }
    
    // Show modal
    businessModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

/**
 * Загрузка документов актива для отображения в модальном окне
 */
async function loadAssetDocuments(sellerFormId) {
    const documentsSection = document.getElementById('modal-documents-section');
    const documentsList = document.getElementById('modal-documents-list');
    
    if (!documentsSection || !documentsList || !sellerFormId) {
        return;
    }
    
    documentsList.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 20px;">Загрузка документов...</p>';
    
    try {
        const response = await fetch(`get_asset_documents.php?seller_form_id=${sellerFormId}`, {
            credentials: 'same-origin'
        });
        
        const result = await response.json();
        
        if (!response.ok || !result.success) {
            throw new Error(result.message || 'Ошибка загрузки документов');
        }
        
        if (result.documents && result.documents.length > 0) {
            renderModalDocumentsList(result.documents);
            documentsSection.style.display = 'block';
        } else {
            documentsSection.style.display = 'none';
        }
        
    } catch (error) {
        console.error('Error loading asset documents:', error);
        documentsSection.style.display = 'none';
    }
}

/**
 * Рендеринг списка документов в модальном окне
 */
function renderModalDocumentsList(documents) {
    const documentsList = document.getElementById('modal-documents-list');
    if (!documentsList) {
        return;
    }
    
    const getFileIcon = (fileType, fileName) => {
        const ext = fileName.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/><circle cx="8.5" cy="8.5" r="1.5" fill="currentColor"/><path d="M21 15l-5-5L5 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        } else if (ext === 'pdf') {
            return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/><line x1="16" y1="13" x2="8" y2="13" stroke="currentColor" stroke-width="2"/><line x1="16" y1="17" x2="8" y2="17" stroke="currentColor" stroke-width="2"/></svg>';
        } else {
            return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" stroke="currentColor" stroke-width="2"/><polyline points="14 2 14 8 20 8" stroke="currentColor" stroke-width="2"/></svg>';
        }
    };
    
    const getIconClass = (fileName) => {
        const ext = fileName.split('.').pop().toLowerCase();
        if (['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext)) {
            return 'document-icon--image';
        } else if (ext === 'pdf') {
            return 'document-icon--pdf';
        } else if (['doc', 'docx'].includes(ext)) {
            return 'document-icon--doc';
        } else if (['xls', 'xlsx'].includes(ext)) {
            return 'document-icon--xls';
        } else if (['zip', 'rar', '7z'].includes(ext)) {
            return 'document-icon--archive';
        }
        return 'document-icon--default';
    };
    
    const formatDate = (dateString) => {
        const date = new Date(dateString);
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        const hours = String(date.getHours()).padStart(2, '0');
        const minutes = String(date.getMinutes()).padStart(2, '0');
        return `${day}.${month}.${year} ${hours}:${minutes}`;
    };
    
    documentsList.innerHTML = `
        <div class="modal-documents-grid">
            ${documents.map(doc => `
                <div class="modal-document-item">
                    <div class="modal-document-item__icon">
                        <div class="document-icon ${getIconClass(doc.file_name)}">
                            ${getFileIcon(doc.file_type, doc.file_name)}
                        </div>
                    </div>
                    <div class="modal-document-item__info">
                        <div class="modal-document-item__name" title="${doc.file_name.replace(/"/g, '&quot;')}">
                            ${doc.file_name.replace(/</g, '&lt;').replace(/>/g, '&gt;')}
                        </div>
                        <div class="modal-document-item__meta">
                            <span>${doc.file_size_mb} МБ</span>
                            <span>•</span>
                            <span>${formatDate(doc.uploaded_at)}</span>
                        </div>
                    </div>
                    <div class="modal-document-item__actions">
                        <a href="download_asset_document.php?document_id=${doc.id}" class="modal-document-item__download" download title="Скачать документ">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </a>
                    </div>
                </div>
            `).join('')}
        </div>
    `;
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

/**
 * Обработчик кнопки "Связаться с продавцом"
 * Загружает контактные данные продавца и показывает их в модальном окне
 */
if (modalContactBtn) {
    modalContactBtn.addEventListener('click', async () => {
        const sellerFormId = businessModal.getAttribute('data-seller-form-id');
        
        if (!sellerFormId) {
            alert('Не удалось определить продавца. Попробуйте позже.');
            return;
        }
        
        // Показываем индикатор загрузки
        modalContactBtn.disabled = true;
        const originalText = modalContactBtn.innerHTML;
        modalContactBtn.innerHTML = '<span>Загрузка...</span>';
        
        try {
            // Загружаем контакты продавца
            const response = await fetch(`get_seller_contacts.php?seller_form_id=${sellerFormId}`);
            const result = await response.json();
            
            if (result.success && result.seller) {
                // Показываем модальное окно с контактами
                showSellerContactsModal(result.seller);
            } else {
                alert(result.message || 'Не удалось загрузить контакты продавца.');
            }
        } catch (error) {
            console.error('Error loading seller contacts:', error);
            alert('Ошибка при загрузке контактов продавца. Попробуйте позже.');
        } finally {
            // Восстанавливаем кнопку
            modalContactBtn.disabled = false;
            modalContactBtn.innerHTML = originalText;
        }
    });
}

/**
 * Показывает модальное окно с контактами продавца
 * @param {Object} seller - Данные продавца (email, phone, full_name, asset_name)
 */
function showSellerContactsModal(seller) {
    // Создаем модальное окно для контактов, если его еще нет
    let contactsModal = document.getElementById('seller-contacts-modal');
    
    if (!contactsModal) {
        contactsModal = document.createElement('div');
        contactsModal.id = 'seller-contacts-modal';
        contactsModal.className = 'modal-overlay';
        contactsModal.innerHTML = `
            <div class="modal-container" style="max-width: 500px;">
                <button class="modal-close" id="contacts-modal-close" aria-label="Закрыть">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" style="margin: 0;">Контакты продавца</h2>
                    </div>
                    <div class="modal-body" id="seller-contacts-content" style="padding: 24px;">
                        <!-- Контакты будут вставлены сюда -->
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-primary" id="contacts-modal-close-btn">Закрыть</button>
                    </div>
                </div>
            </div>
        `;
        document.body.appendChild(contactsModal);
        
        // Обработчики закрытия
        const closeBtn = contactsModal.querySelector('#contacts-modal-close');
        const closeBtnFooter = contactsModal.querySelector('#contacts-modal-close-btn');
        
        const closeContactsModal = () => {
            contactsModal.classList.remove('active');
            // Не меняем overflow, так как основное модальное окно может быть открыто
            // Если основное модальное окно открыто, overflow уже установлен
            if (!businessModal.classList.contains('active')) {
                document.body.style.overflow = '';
            }
        };
        
        if (closeBtn) closeBtn.addEventListener('click', closeContactsModal);
        if (closeBtnFooter) closeBtnFooter.addEventListener('click', closeContactsModal);
        
        // Закрытие при клике вне модального окна
        contactsModal.addEventListener('click', (e) => {
            if (e.target === contactsModal) {
                closeContactsModal();
            }
        });
        
        // Закрытие по Escape
        document.addEventListener('keydown', function closeOnEscape(e) {
            if (e.key === 'Escape' && contactsModal.classList.contains('active')) {
                closeContactsModal();
                document.removeEventListener('keydown', closeOnEscape);
            }
        });
    }
    
    // Заполняем контакты
    const contactsContent = contactsModal.querySelector('#seller-contacts-content');
    if (contactsContent) {
        let html = '';
        
        if (seller.asset_name) {
            html += `<p style="margin-bottom: 20px; color: var(--text-secondary); font-size: 14px;">Актив: <strong>${escapeHtml(seller.asset_name)}</strong></p>`;
        }
        
        if (seller.full_name) {
            html += `<p style="margin-bottom: 16px; color: var(--text-secondary); font-size: 14px;">Продавец: <strong>${escapeHtml(seller.full_name)}</strong></p>`;
        }
        
        html += '<div style="display: flex; flex-direction: column; gap: 16px;">';
        
        // Email
        if (seller.email) {
            html += `
                <div style="padding: 16px; background: rgba(99, 102, 241, 0.05); border-radius: 12px; border: 2px solid rgba(99, 102, 241, 0.1);">
                    <div style="font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Email</div>
                    <a href="mailto:${escapeHtml(seller.email)}" style="font-size: 16px; font-weight: 600; color: #6366F1; text-decoration: none; word-break: break-all;">
                        ${escapeHtml(seller.email)}
                    </a>
                </div>
            `;
        }
        
        // Телефон
        if (seller.phone) {
            html += `
                <div style="padding: 16px; background: rgba(34, 197, 94, 0.05); border-radius: 12px; border: 2px solid rgba(34, 197, 94, 0.1);">
                    <div style="font-size: 12px; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Телефон</div>
                    <a href="tel:${escapeHtml(seller.phone)}" style="font-size: 16px; font-weight: 600; color: #22C55E; text-decoration: none;">
                        ${escapeHtml(seller.phone)}
                    </a>
                </div>
            `;
        }
        
        if (!seller.email && !seller.phone) {
            html += '<p style="text-align: center; color: var(--text-secondary); padding: 20px;">Контактные данные не указаны.</p>';
        }
        
        html += '</div>';
        
        contactsContent.innerHTML = html;
    }
    
    // Показываем модальное окно с контактами поверх основного модального окна
    // Увеличиваем z-index для отображения поверх основного модального окна
    contactsModal.style.zIndex = '10001';
    contactsModal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

/**
 * Экранирует HTML для безопасности
 * @param {string} text - Текст для экранирования
 * @returns {string} Экранированный текст
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
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
 * Используется GSAP если доступен, иначе fallback
 */
function animateCardsOnLoad() {
    if (typeof gsap === 'undefined' || typeof ScrollTrigger === 'undefined') {
        // Fallback анимация без GSAP
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
    // GSAP анимации уже инициализированы выше
}

// Initialize animations when page loads
document.addEventListener('DOMContentLoaded', () => {
    console.log('>>> MAIN: DOMContentLoaded fired');
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

/**
 * Инициализация финансовых графиков в тизерах
 * Использует ApexCharts для отображения графиков динамики финансов
 */
function initTeaserCharts() {
    if (typeof ApexCharts === 'undefined') {
        console.warn('ApexCharts is not available.');
        return;
    }
    // Поиск всех контейнеров для графиков (включая модальное окно)
    const containers = document.querySelectorAll('.teaser-chart[data-chart]');
    if (!containers.length) {
        console.log('No chart containers found');
        return;
    }
    console.log('Found', containers.length, 'chart containers');
    containers.forEach((container, index) => {
        // Очистка контейнера от предыдущего содержимого
        container.innerHTML = '';
        
        // Генерация уникального ID для графика, если его нет
        if (!container.id) {
            container.id = 'teaser-chart-' + Date.now() + '-' + index;
        }
        const chartId = container.id;
        
        // Проверка, не был ли график уже отрендерен
        if (container.dataset.chartReady === '1') {
            return;
        }
        
        // Парсинг JSON данных графика из атрибута data-chart
        let payload;
        try {
            payload = JSON.parse(container.getAttribute('data-chart') || '{}');
        } catch (error) {
            console.error('Chart payload parse error', error);
            return;
        }
        if (!payload || !Array.isArray(payload.series) || payload.series.length === 0) {
            return;
        }
        
        const options = {
            chart: {
                id: chartId,
                type: 'line',
                height: 300,
                parentHeightOffset: 10,
                toolbar: { show: false },
                fontFamily: 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
            },
            colors: payload.colors || ['#6366F1', '#0EA5E9', '#F97316', '#10B981'],
            series: payload.series,
            stroke: {
                width: 3,
                curve: 'smooth',
            },
            markers: {
                size: 4,
                strokeWidth: 2,
                hover: { size: 7 },
            },
            dataLabels: { enabled: false },
            grid: {
                strokeDashArray: 5,
                borderColor: 'rgba(15,23,42,0.08)',
            },
            xaxis: {
                categories: payload.categories || [],
                labels: {
                    style: {
                        colors: 'rgba(71,85,105,0.9)',
                        fontSize: '12px',
                    },
                },
                axisBorder: { show: false },
                axisTicks: { show: false },
            },
            yaxis: {
                labels: {
                    style: {
                        colors: 'rgba(71,85,105,0.9)',
                        fontSize: '12px',
                    },
                    formatter: (value) => {
                        if (value === null || value === undefined) {
                            return '';
                        }
                        const unit = payload.unit || '';
                        return `${Math.round(value).toLocaleString('ru-RU')} ${unit}`.trim();
                    },
                },
            },
            legend: {
                position: 'top',
                horizontalAlign: 'left',
                fontSize: '12px',
                offsetY: -5,
                offsetX: 0,
                markers: { width: 8, height: 8, radius: 2 },
                itemMargin: {
                    horizontal: 12,
                    vertical: 0,
                },
            },
            tooltip: {
                theme: 'light',
                y: {
                    formatter: (value) => {
                        if (value === null || value === undefined) {
                            return '—';
                        }
                        const unit = payload.unit || '';
                        return `${value.toLocaleString('ru-RU', { maximumFractionDigits: 1 })} ${unit}`.trim();
                    },
                },
            },
            fill: {
                type: 'gradient',
                gradient: {
                    shadeIntensity: 0.3,
                    opacityFrom: 0.8,
                    opacityTo: 0.1,
                    stops: [0, 90, 100],
                },
            },
        };
        
        // Ensure container is empty and ready
        container.innerHTML = '';
        container.style.minHeight = '260px';
        
        const chart = new ApexCharts(container, options);
        chart.render().then(() => {
            container.dataset.chartReady = '1';
            container.setAttribute('data-chart-id', chartId);
        }).catch((error) => {
            console.error('Chart render error:', error);
            container.innerHTML = '<p style="font-size: 12px; color: #999; text-align: center; padding: 20px;">График временно недоступен</p>';
        });
    });
}

console.log('SmartBizSell.ru - Platform loaded successfully');

