#!/bin/bash
# /var/www/html/newapi/bin/auto-update.sh

APP_DIR="/var/www/html/newapi"
LOG_FILE="$APP_DIR/storage/logs/auto-update.log"
DB_USER="smartbar"
DB_PASS="h2so4"
DB_NAME="checks_db"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"; }

cd "$APP_DIR" || exit 1

# Проверяем, есть ли новые коммиты
LOCAL=$(git rev-parse HEAD 2>/dev/null)
REMOTE=$(git ls-remote origin HEAD 2>/dev/null | cut -f1)

if [ -z "$REMOTE" ]; then
    log "Нет доступа к репозиторию"
    exit 0
fi

if [ "$LOCAL" = "$REMOTE" ]; then
    # Обновлений нет — выходим молча
    exit 0
fi

log "Найдено обновление: $LOCAL -> $REMOTE"

# Сохраняем локальные изменения (на всякий случай)
git stash --include-untracked >> "$LOG_FILE" 2>&1

# Тянем изменения
if ! git pull --ff-only origin main >> "$LOG_FILE" 2>&1; then
    log "Ошибка git pull"
    git stash pop >> "$LOG_FILE" 2>&1
    exit 1
fi

git stash pop >> "$LOG_FILE" 2>&1 || true

# Обновляем composer
composer install --no-dev --optimize-autoloader --quiet >> "$LOG_FILE" 2>&1 || true

# Применяем миграции
for migration in "$APP_DIR"/database/migrations/*.sql; do
    [ -f "$migration" ] || continue
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" >> "$LOG_FILE" 2>&1 || true
done

# Фиксим регистр папок (если в git снова заедет App вместо app)
if [ -d "$APP_DIR/App" ]; then
    mv "$APP_DIR/App" "$APP_DIR/app"
    log "Исправлен регистр: App -> app"
fi

# Права
chown -R www-data:www-data "$APP_DIR" >> /dev/null 2>&1
chmod -R 775 "$APP_DIR/storage" >> /dev/null 2>&1

# Перезапуск сервисов
systemctl reload php8.2-fpm >> "$LOG_FILE" 2>&1
nginx -t >> "$LOG_FILE" 2>&1 && systemctl reload nginx >> "$LOG_FILE" 2>&1

# Убиваем зависшие процессы timesend
pkill -f timesend.php >> /dev/null 2>&1 || true
rm -f /tmp/smartbar-timesend.lock

log "Обновление завершено успешно"