#!/bin/bash
# /var/www/html/newapi/bin/auto-update.sh

APP_DIR="/var/www/html/newapi"
LOG_FILE="$APP_DIR/storage/logs/auto-update.log"
DB_USER="smartbar"
DB_PASS="h2so4"
DB_NAME="checks_db"
REPO_URL="https://github.com/hexplode13/sbapi.git" # Явно указываем HTTPS

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"; }

cd "$APP_DIR" || exit 1

# === ДОБАВИТЬ ЭТОТ БЛОК ===
# Проверяем, доверяет ли текущий пользователь этой папке
if ! git config --global --get safe.directory | grep -

# 1. Проверка связи с GitHub (пинг не всегда работает из-за ICMP, пробуем curl)
if ! curl -s --max-time 5 https://github.com > /dev/null; then
    log "Нет интернета или GitHub недоступен"
    exit 0
fi

# 2. Проверяем наличие новых коммитов через HTTPS
# Используем git ls-remote с явным указанием URL, чтобы избежать проблем с origin
REMOTE_HASH=$(git ls-remote "$REPO_URL" HEAD 2>&1 | cut -f1)

if [ -z "$REMOTE_HASH" ]; then
    log "Ошибка получения данных от репозитория: $REMOTE_HASH"
    exit 0
fi

LOCAL_HASH=$(git rev-parse HEAD 2>/dev/null)

if [ "$LOCAL_HASH" = "$REMOTE_HASH" ]; then
    # Обновлений нет — выходим молча, чтобы не засорять лог
    exit 0
fi

log "Найдено обновление: $LOCAL_HASH -> $REMOTE_HASH"

# 3. Сохраняем локальные изменения (на всякий случай)
git stash --include-untracked >> "$LOG_FILE" 2>&1

# 4. Тянем изменения
if ! git pull --ff-only "$REPO_URL" main >> "$LOG_FILE" 2>&1; then
    log "Ошибка git pull. Пробуем reset..."
    git reset --hard HEAD >> "$LOG_FILE" 2>&1
    git pull "$REPO_URL" main >> "$LOG_FILE" 2>&1
fi

git stash pop >> "$LOG_FILE" 2>&1 || true

# 5. Обновляем composer
composer install --no-dev --optimize-autoloader --quiet >> "$LOG_FILE" 2>&1 || true

# 6. Применяем миграции
for migration in "$APP_DIR"/database/migrations/*.sql; do
    [ -f "$migration" ] || continue
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" >> "$LOG_FILE" 2>&1 || true
done

# 7. Фиксим регистр папок (если в git снова заедет App вместо app)
if [ -d "$APP_DIR/app" ]; then
    mv "$APP_DIR/app" "$APP_DIR/App"
    log "Исправлен регистр: app -> App"
fi

# 8. Права
chown -R www-data:www-data "$APP_DIR" >> /dev/null 2>&1
chmod -R 775 "$APP_DIR/storage" >> /dev/null 2>&1

# 9. Перезапуск сервисов
systemctl reload php8.2-fpm >> "$LOG_FILE" 2>&1
nginx -t >> "$LOG_FILE" 2>&1 && systemctl reload nginx >> "$LOG_FILE" 2>&1

# 10. Убиваем зависшие процессы timesend
pkill -f timesend.php >> /dev/null 2>&1 || true
rm -f /tmp/smartbar-timesend.lock

log "Обновление завершено успешно"