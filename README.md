# Smartbar API

PHP API для заказов, столов и кухонных позиций. Основной API обслуживается через Nginx и PHP-FPM по префиксу `/newapi`. Сервис `auto_panels` работает отдельно на FastAPI в Docker и обслуживает панели через HTTP и WebSocket.

## Развёртывание

Установщик рассчитан на Linux с APT и systemd. Он использует PHP 8.2, Nginx, Composer, cron и MariaDB. Миграции рассчитаны на существующую схему Smartbar в базе `checks_db`: например, они меняют таблицы `my_orders` и `tables`. Создание пустой базы установщиком не заменяет загрузку исходной схемы.

Из копии репозитория вне целевого каталога запустите `sudo bash deploy.sh install`. При запуске из терминала установщик предложит выбрать `prod` или `test`. Нажатие Enter и запуск без терминала выбирают `prod`. Для явного выбора передайте режим аргументом:

```bash
sudo bash deploy.sh install test
sudo bash deploy.sh install prod
```

В режиме `test` установщик создаёт `/etc/smartbar/auto-update-discard-local-script`. В режиме `prod` файл отсутствует. Команда и расписание cron одинаковы в обоих режимах. Ручной `deploy.sh update` сохраняет выбранный режим.

Установщик клонирует ветку `main` в `/var/www/html/newapi`, создаёт `config/config.php` из примера при его отсутствии, настраивает cron и заменяет конфигурацию Nginx в `/etc/nginx/sites-available/default`. При первой замене существующего конфига создаётся копия `default.original.backup`. Проверьте параметры в `config/config.php` на сервере. Файл исключён из Git.

```bash
curl -fsS http://127.0.0.1/newapi/test
```

## Основные маршруты

| Метод | Путь | Назначение |
| --- | --- | --- |
| GET | `/newapi/test` | Проверка доступности PHP API |
| GET | `/newapi/orders` | Список заказов |
| GET | `/newapi/orders/on-table` | Заказы на столах |
| POST | `/newapi/orders` | Создание заказа |
| PATCH | `/newapi/orders/{id}/send` | Отправка заказа |
| POST | `/newapi/tables/{table}/clear` | Очистка стола |
| GET, POST | `/newapi/json.php` | Совместимость со старым API |

Полный список маршрутов находится в `public/index.php`. Панели обслуживаются контейнером `autopanel_server` на порту 8000. У него есть `GET /api/status` и `/ws/panel/{panel_number}`. SQLite хранится в `auto_panels/db_data`, который подключается в контейнер как `/app/data`.

## Обновления

Установщик создаёт два задания cron:

| Файл | Расписание | Действие |
| --- | --- | --- |
| `/etc/cron.d/smartbar-autoupdate` | Каждые 10 минут | Запуск `bin/auto-update.sh` от root |
| `/etc/cron.d/smartbar-timesend` | Каждую минуту | Запуск `bin/timesend.php --limit=5` от www-data |

Автообновление выполняет `git pull origin main` в каталоге приложения. Если Git сообщает, что локальные изменения будут перезаписаны, скрипт пытается восстановить только `bin/auto-update.sh` из индекса и повторяет `pull`. Правка в другом файле или правка, уже добавленная в индекс, всё ещё может заблокировать обновление. Успешный `pull` запускает Composer, миграции и перезагрузку PHP-FPM и Nginx. Для применения изменений Python требуется пересборка контейнера.

Ручное обновление выполняется из каталога приложения:

```bash
cd /var/www/html/newapi
sudo bash deploy.sh update
```

Оно выполняет `git reset --hard origin/main`, сбрасывает изменения отслеживаемых файлов и пересобирает Docker-контейнер. Сохраните нужные правки перед запуском.

### Проверка исправлений на тестовом сервере

Если тестовый сервер уже установлен, автоматический сброс локальных правок в `bin/auto-update.sh` можно включить вручную:

```bash
sudo install -d -m 0755 /etc/smartbar
sudo touch /etc/smartbar/auto-update-discard-local-script
```

Режим начинает работать, когда сервер получит версию скрипта с этой настройкой. Пока нового коммита в `origin/main` нет, локальные правки сохраняются. При появлении нового коммита скрипт проверяет возможность fast-forward, сбрасывает правки только в `bin/auto-update.sh` (в том числе добавленные через `git add`) и применяет коммит. Сброшенные правки не сохраняются. Новый и старый SHA записываются в лог. Правки в других файлах могут остановить обновление.

Без файла-маркера действует обычный режим, описанный выше. Чтобы вернуть его, удалите маркер:

```bash
sudo rm /etc/smartbar/auto-update-discard-local-script
```

Если старая версия скрипта не может получить первый коммит с этой настройкой, сохраните diff и восстановите скрипт вручную. Выполняйте команды от владельца рабочей копии Git. В типовой установке это `www-data`.

```bash
cd /var/www/html/newapi
patch=$(mktemp /tmp/sbapi-auto-update.XXXXXX) &&
git diff --binary HEAD -- bin/auto-update.sh > "$patch" &&
git restore --source=HEAD --staged --worktree -- bin/auto-update.sh &&
printf 'Патч сохранён в %s\n' "$patch"
```

После пуша в `main` дождитесь следующего запуска cron. Сверьте хеш `HEAD` с хешем коммита, который проверяете, и проверьте журнал и endpoint.

```bash
cd /var/www/html/newapi
git log -1 --format='%H %s'
git status --short
sudo tail -n 100 storage/logs/auto-update.log
curl -fsS http://127.0.0.1/newapi/test
```

Хеш показывает, какой коммит находится в рабочей копии. Проверка `/newapi/test` подтверждает доступность PHP API. Для проверки самого исправления повторите запрос или сценарий, который оно меняло. В журнале проверьте ошибки Composer, SQL и перезагрузки сервисов: текущий скрипт может вывести сообщение об успехе и после ошибки на одном из этих шагов.

Лог отправки заказов находится в `/var/www/html/newapi/storage/logs/timesend-cron.log`. Состояние панелей можно проверить командами:

```bash
curl -fsS http://127.0.0.1:8000/api/status
sudo docker logs --tail 100 autopanel_server
```
