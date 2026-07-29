#!/bin/bash
set -e

# =========================
# Конфигурация
# =========================
APP_DIR="/var/www/html/newapi"
REPO_URL="https://github.com/hexplode13/sbapi.git"
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
    
    # Убрали php-json (он встроен), mariadb (чтобы не конфликтовал с MySQL) и flock (он в util-linux)
    apt-get install -y \
        git \
        curl \
        php${PHP_VERSION}-fpm \
        php${PHP_VERSION}-mysql \
        php${PHP_VERSION}-curl \
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-xml \
        nginx \
        composer \
        cron \
        util-linux

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

        # 8. Nginx конфиг (генерация целиком)
    log "Настройка nginx..."
    NGINX_DEFAULT="/etc/nginx/sites-available/default"
    NGINX_BACKUP="/etc/nginx/sites-available/default.original.backup"

    # Делаем бэкап оригинального default только один раз
    if [ -f "$NGINX_DEFAULT" ] && [ ! -f "$NGINX_BACKUP" ]; then
        cp "$NGINX_DEFAULT" "$NGINX_BACKUP"
        log "Создан бэкап оригинального nginx конфига: $NGINX_BACKUP"
    fi

    # Генерируем новый конфиг целиком
    cat > "$NGINX_DEFAULT" <<'NGINX_CONF'
server {
    charset utf-8;
    server_name 192.168.0.213 _;

    root /var/www/html/;

    access_log /var/log/nginx/sb-a.log;
    error_log /var/log/nginx/sb-e.log;

    index index.php index.html;

    client_max_body_size 10m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location /auth/ {
        try_files $uri /auth/index.html;
    }

    # =========================
    # NEW API in /newapi
    # =========================

    # Обработка статики и перенаправление на фронт-контроллер API
    location /newapi/ {
        # Используем root вместо alias, указывая путь ДО папки /newapi/
        root /var/www/html/newapi/public;

        # Меняем URI на внутренний путь к index.php
        try_files $uri $uri/ /newapi/public/index.php?$query_string;
    }

    # Обработка PHP конкретно для нового API
    location ~ ^/newapi/public/index\.php$ {
        # Скрываем локацию от внешних прямых запросов
        internal;

        fastcgi_buffer_size 32k;
        fastcgi_buffers 4 32k;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;

        include fastcgi_params;

        # Четко задаем пути к файлу
        fastcgi_param SCRIPT_FILENAME /var/www/html/newapi/public/index.php;
        fastcgi_param DOCUMENT_ROOT   /var/www/html/newapi/public;

        # Передаем правильные заголовки и параметры
        fastcgi_param REQUEST_URI       $request_uri;
        fastcgi_param QUERY_STRING      $query_string;
        fastcgi_param REQUEST_METHOD     $request_method;
        fastcgi_param CONTENT_TYPE       $content_type;
        fastcgi_param CONTENT_LENGTH     $content_length;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;

        fastcgi_ignore_client_abort off;
        fastcgi_read_timeout 360;
    }
    # END NEW API
    # =========================

    location ~ /\.ht {
        deny all;
    }

    location = /favicon.ico {
        log_not_found off;
        access_log off;
    }

    location = /robots.txt {
        allow all;
        log_not_found off;
        access_log off;
    }

    location ~ \.php$ {
        try_files $uri =404;

        fastcgi_buffer_size 32k;
        fastcgi_buffers 4 32k;

        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;

        include fastcgi_params;

        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;

        fastcgi_ignore_client_abort off;
        fastcgi_read_timeout 360;
    }
}
NGINX_CONF

    # Убеждаемся, что симлинк в sites-enabled существует
    if [ ! -L "/etc/nginx/sites-enabled/default" ]; then
        ln -sf "$NGINX_DEFAULT" /etc/nginx/sites-enabled/default
    fi

    # Проверка и перезагрузка
    if nginx -t; then
        systemctl reload nginx
        log "Nginx конфиг успешно обновлён и перезагружен"
    else
        err "Ошибка в сгенерированном nginx конфиге! Проверьте: nginx -t"
    fi

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