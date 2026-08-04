#!/bin/bash
# Скрипт автоматического обновления Smartbar API
# Запускается из cron

APP_DIR="/var/www/html/newapi"
LOG_FILE="$APP_DIR/storage/logs/auto-update.log"
DB_USER="smartbar"
DB_PASS="h2so4"
DB_NAME="checks_db"

# Функция логирования
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

# Переходим в папку проекта
cd "$APP_DIR" || exit 1

# Исправляем проблему с правами Git (dubious ownership)
git config --global --add safe.directory "$APP_DIR" 2>/dev/null

log "=== Начало проверки обновлений ==="

# Пытаемся сделать pull
# Если есть обновления, git их заберет. Если нет — напишет "Already up to date".
PULL_OUTPUT=$(git pull origin main 2>&1)
PULL_EXIT=$?

if [ $PULL_EXIT -ne 0 ]; then
    log "Ошибка git pull: $PULL_OUTPUT"
    exit 1
fi

# Проверяем, были ли реальные изменения
if echo "$PULL_OUTPUT" | grep -q "Already up to date"; then
    log "Обновлений нет."
    exit 0
else
    log "Найдены и применены обновления."
fi

# Обновляем зависимости Composer
log "Обновление Composer..."
composer install --no-dev --optimize-autoloader --quiet 2>&1 >> "$LOG_FILE"

# Применяем миграции БД
log "Применение миграций БД..."
for migration in "$APP_DIR"/database/migrations/*.sql; do
    if [ -f "$migration" ]; then
        mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" 2>> "$LOG_FILE"
    fi
done

# Фиксим регистр папок (на всякий случай)
if [ -d "$APP_DIR/App" ]; then
    mv "$APP_DIR/App" "$APP_DIR/app"
    log "Исправлен регистр папки: App -> app"
fi

# Обновляем права
chown -R www-data:www-data "$APP_DIR" 2>/dev/null
chmod -R 775 "$APP_DIR/storage" 2>/dev/null

# Перезапускаем сервисы
log "Перезапуск сервисов..."
systemctl reload php8.2-fpm 2>> "$LOG_FILE"
nginx -t 2>> "$LOG_FILE" && systemctl reload nginx 2>> "$LOG_FILE"

# Сбрасываем блокировку timesend, если она зависла
pkill -f timesend.php 2>/dev/null || true
rm -f /tmp/smartbar-timesend.lock

log "=== Обновление завершено успешно ==="