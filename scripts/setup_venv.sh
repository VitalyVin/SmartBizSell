#!/usr/bin/env bash
set -euo pipefail

TARGET_DIR="/home/u3064951/smartbizsell.ru"
VENV_DIR="$TARGET_DIR/venv"

echo "==> Создаём виртуальное окружение в $VENV_DIR"
python3 -m venv "$VENV_DIR"

echo "==> Активируем окружение и обновляем pip"
source "$VENV_DIR/bin/activate"
python -m pip install --upgrade pip

echo "==> Устанавливаем зависимости"
pip install together

echo "==> Готово. Для использования:"
echo "    source $VENV_DIR/bin/activate"

