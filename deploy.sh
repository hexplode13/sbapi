#!/bin/bash
set -e

# =========================
# Конфигурация
# =========================
APP_DIR="/var/www/html/newapi"
REPO_URL="https://github.com/ВАШ_ЮЗЕР/smartbar-api.git"
BRANCH="main"
DB_USER="smartbar"
DB_PASS="h2so4"
DB_NAME="checks_db"
PHP_VERSION="8.2"
CRON_FILE="/etc/cron.d/smartbar-timesend"

# Цвета для вывода
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log() { echo -e "${GREEN}[DEPLOY]${NC} $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
err() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# =========================
# Проверка прав
# =========================
if [ "$EUID" -ne 0 ]; then
    err "Запускайте скрипт от root: sudo bash deploy.sh"
fi

# =========================
# Режим: install или update
# =========================
MODE="${1:-install}"

if [ "$MODE" = "install" ]; then
    log "=== ПЕРВИЧНАЯ УСТАНОВКА ==="

    # 1. Установка зависимостей
    log "Установка системных пакетов..."
    apt-get update -qq
    apt-get install -y -qq \
        git \
        curl \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-json \
        nginx \
        composer \
        cron \
        mariadb-server \
        flock \
        > /dev/null 2>&1

    # 2. Включение модулей PHP
    log "Включение PHP-модулей..."
    phpenmod -v ${PHP_VERSION} curl mbstring xml json 2>/dev/null || true
    systemctl enable --now php${PHP_VERSION}-fpm
    systemctl enable --now nginx
    systemctl enable --now cron
    systemctl enable --now mariadb

    # 3. Создание БД и пользователя (если ещё нет)
    log "Настройка базы данных..."
    mysql -u root <<EOSQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOSQL

    # 4. Клонирование репозитория
    log "Клонирование репозитория..."
    if [ -d "$APP_DIR/.git" ]; then
        warn "Директория $APP_DIR уже существует. Используйте: sudo bash deploy.sh update"
        exit 1
    fi

    mkdir -p "$(dirname "$APP_DIR")"
    git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"

    # 5. Создание конфига
    log "Создание конфигурации..."
    if [ ! -f "$APP_DIR/config/config.php" ]; then
        cp "$APP_DIR/config/config.example.php" "$APP_DIR/config/config.php"
        sed -i "s/CHANGE_ME/${DB_PASS}/" "$APP_DIR/config/config.php"
        log "Конфиг создан. Проверьте: $APP_DIR/config/config.php"
    fi

    # 6. Composer
    log "Установка Composer-зависимостей..."
    cd "$APP_DIR"
    composer install --no-dev --optimize-autoloader --quiet 2>/dev/null || true

    # 7. Миграции БД
    log "Применение миграций..."
    for migration in "$APP_DIR"/database/migrations/*.sql; do
        if [ -f "$migration" ]; then
            log "  Применяю: $(basename "$migration")"
            mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" 2>/dev/null || \
                warn "  Миграция $(basename "$migration") уже применена или содержит ошибки"
        fi
    done

    # 8. Nginx конфиг
    log "Настройка nginx..."
    NGINX_CONF="/etc/nginx/sites-available/newapi"
    NGINX_ENABLED="/etc/nginx/sites-enabled/newapi"

    # Вставляем блок newapi в существующий конфиг сервера
    # Если у вас один серверный блок в /etc/nginx/sites-enabled/default,
    # можно вставить include:
    if [ -f "$APP_DIR/nginx/newapi.conf" ]; then
        # Проверяем, не вставлен ли уже
        MAIN_NGINX_CONF=$(grep -rl "server_name" /etc/nginx/sites-enabled/ 2>/dev/null | head -1)
        if [ -n "$MAIN_NGINX_CONF" ]; then
            if ! grep -q "newapi.conf" "$MAIN_NGINX_CONF" 2>/dev/null; then
                # Вставляем include перед последней закрывающей скобкой
                sed -i "/^}$/i\\    include ${APP_DIR}/nginx/newapi.conf;" "$MAIN_NGINX_CONF"
                log "Nginx include добавлен в $MAIN_NGINX_CONF"
            else
                warn "Nginx include уже существует"
            fi
        fi
    fi

    nginx -t && systemctl reload nginx

    # 9. Права
    log "Настройка прав..."
    mkdir -p "$APP_DIR/storage/logs"
    chown -R www-data:www-data "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 755 {} \;
    find "$APP_DIR" -type f -exec chmod 644 {} \;
    chmod -R 775 "$APP_DIR/storage"
    chmod +x "$APP_DIR/bin/timesend.php"

    # 10. Cron
    log "Настройка cron..."
    cat > "$CRON_FILE" <<EOCRON
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin

* * * * * www-data /usr/bin/php ${APP_DIR}/bin/timesend.php --limit=5 >> ${APP_DIR}/storage/logs/timesend-cron.log 2>&1

EOCRON
    chown root:root "$CRON_FILE"
    chmod 644 "$CRON_FILE"
    systemctl restart cron

    log "=== УСТАНОВКА ЗАВЕРШЕНА ==="
    log "Проверьте: curl http://$(hostname -I | awk '{print $1}')/newapi/test"

elif [ "$MODE" = "update" ]; then
    log "=== ОБНОВЛЕНИЕ ==="

    if [ ! -d "$APP_DIR/.git" ]; then
        err "Директория $APP_DIR не является git-репозиторием. Сначала выполните: sudo bash deploy.sh install"
    fi

    cd "$APP_DIR"

    # 1. Сохраняем локальные изменения конфига
    log "Обновление из репозитория..."
    git stash --include-untracked 2>/dev/null || true
    git pull origin "$BRANCH"
    git stash pop 2>/dev/null || true

    # 2. Composer
    log "Обновление зависимостей..."
    composer install --no-dev --optimize-autoloader --quiet 2>/dev/null || true

    # 3. Миграции
    log "Применение новых миграций..."
    for migration in "$APP_DIR"/database/migrations/*.sql; do
        if [ -f "$migration" ]; then
            mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$migration" 2>/dev/null || true
        fi
    done

    # 4. Права
    log "Обновление прав..."
    mkdir -p "$APP_DIR/storage/logs"
    chown -R www-data:www-data "$APP_DIR"
    find "$APP_DIR" -type d -exec chmod 755 {} \;
    find "$APP_DIR" -type f -exec chmod 644 {} \;
    chmod -R 775 "$APP_DIR/storage"
    chmod +x "$APP_DIR/bin/timesend.php"

    # 5. Перезапуск сервисов
    log "Перезапуск сервисов..."
    systemctl reload php${PHP_VERSION}-fpm
    nginx -t && systemctl reload nginx
    systemctl restart cron

    # Убираем зависшие процессы
    pkill -f timesend.php 2>/dev/null || true
    rm -f /tmp/smartbar-timesend.lock

    log "=== ОБНОВЛЕНИЕ ЗАВЕРШЕНО ==="

else
    err "Неизвестный режим. Используйте: sudo bash deploy.sh [install|update]"
fi