#!/usr/bin/env python3
"""
Тестовый скрипт для проверки работы Qwen через together.ai
"""

import os
import together

# Установка API ключа через переменную окружения (рекомендуемый способ)
os.environ["TOGETHER_API_KEY"] = "c0bf29d89744dd1e091c9eca2b1cfeda9d7af4dacedadcf82872b4698d8365ba"

print("Тестирование Qwen через together.ai...")
print("=" * 50)

# Используем рабочую модель
model = "Qwen/Qwen2.5-72B-Instruct-Turbo"

try:
    print(f"\nИспользуемая модель: {model}")
    print("Отправка запроса...")
    
    response = together.Complete.create(
        prompt="Рассчитай DCF при FCFF=100, WACC=10%, g=2%",
        model=model,
        max_tokens=300,
        temperature=0.1
    )
    
    print("\n✅ Успешно! API работает корректно.")
    print("\nОтвет от модели:")
    print("-" * 50)
    
    # Извлекаем текст ответа (поддерживаем разные форматы)
    if "output" in response and "choices" in response["output"]:
        text = response["output"]["choices"][0]["text"]
    elif "choices" in response:
        text = response["choices"][0]["text"]
    elif "output" in response:
        text = response["output"]
    else:
        text = str(response)
    
    print(text)
    print("-" * 50)
    print(f"\n✅ Тест пройден успешно! Модель {model} работает.")
    
except Exception as e:
    print(f"\n❌ Ошибка: {e}")
    print("\nВозможные причины:")
    print("1. Не установлена библиотека together: pip install together")
    print("2. Неверный API ключ")
    print("3. Проблемы с сетью или сервисом together.ai")
    print("4. Модель недоступна - проверьте https://api.together.ai/models")
