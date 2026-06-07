# CatalogTest

**Сравнение трёх способов хранения каталога тарифов в PostgreSQL под OR-поиск по 100 свойствам.**

[![PHP](https://img.shields.io/badge/PHP-8.5-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony)](https://symfony.com/)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ed?logo=docker&logoColor=white)](https://www.docker.com/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

---

## 📖 Что это

Бенчмарк-стенд для сравнения трёх паттернов хранения **«сущность + 100 свойств»** в PostgreSQL 16:

| # | Реализация | Файл | Суть |
|---|---|---|---|
| **R1** | `tariff_flat` | [docs/schema.sql](docs/schema.sql) | Широкая таблица, 102 колонки (70 bool + 20 int + 10 string), без вторичных индексов |
| **R2** | `tariff_rel` + `tariff_property` + `tariff_value` | [docs/schema.sql](docs/schema.sql) | EAV: 1M родителей → 100M значений, индексы по `(property_id, value_*)` |
| **R3** | `tariff_jsonb` | [docs/schema.sql](docs/schema.sql) | JSONB + GIN(`jsonb_path_ops`), ключи `f001..f070`, `i001..i020`, `s001..s010` |

Все три таблицы заполняются **одним и тем же детерминированным сидером** (`Mt19937(20260607)`), поэтому id и значения свойств идентичны между реализациями — сравнение корректно.

Эталонный запрос: **100 условий, объединённых через `OR`** (по одному на каждое свойство), с `LIMIT 1000`.

---

## 🚀 Быстрый старт

```bash
git clone https://github.com/kostikpenzin/catalogtest.git
cd catalogtest
docker compose up -d --build
```

Поднимутся три сервиса: `database` (PostgreSQL 16), `php` (PHP-FPM 8.5), `nginx` (HTTP).

### Проверить работоспособность

- 🌐 **Главная**: <http://localhost:8080/>
- ❤️ **Health-check**: <http://localhost:8080/health>

```bash
curl http://localhost:8080/health
```

```json
{
  "status": "ok",
  "php": "8.5.x",
  "symfony": "8.1.x",
  "database": "ok",
  "timestamp": "2026-06-08T..."
}
```

### Заполнить каталог и прогнать бенчмарк

```bash
# 1. Засеять все три таблицы (по 100 000 тарифов в каждой)
docker compose exec php php bin/console app:seed-catalog --variant=flat  -c 100000 --truncate
docker compose exec php php bin/console app:seed-catalog --variant=rel   -c 100000 --truncate
docker compose exec php php bin/console app:seed-catalog --variant=jsonb -c 100000 --truncate

# 2. Запустить бенчмарк (5 итераций каждой реализации → EXPLAIN ANALYZE)
docker compose exec php php bin/console app:benchmark -i 5

# 3. Открыть результаты
open http://localhost:8080/search         # сводная таблица + описание реализаций
open http://localhost:8080/benchmark      # детальный дашборд (p50/p95/max, SQL, EXPLAIN)
open http://localhost:8080/benchmark.pdf  # PDF-отчёт на русском
```

---

## 🧩 Эндпоинты

| URL | Описание |
|---|---|
| `GET /` | Главная — навигация по всем возможностям |
| `GET /health` | Health-check приложения и БД (JSON) |
| `GET /search` | Сводная таблица бенчмарка + описание реализаций |
| `GET /search/run?plan=1` | Запустить OR-поиск по всем 3 реализациям, вернуть SQL + EXPLAIN (JSON) |
| `GET /benchmark` | Детальный дашборд: p50/p95/max, размеры таблиц, SQL, EXPLAIN |
| `GET /benchmark.pdf` | PDF-отчёт на русском (генерируется TCPDF + DejaVu Sans) |
| `GET /api/criteria` | Сгенерированные критерии поиска (bool/int/string, JSON) |

---

## 🏗️ Архитектура

```
┌───────────────────────────────┐         ┌──────────────────────────────┐
│  PostgreSQL 16                │         │  PHP-FPM 8.5                 │
│  ─────────────────────────    │  port   │  ───────────────────────     │
│  database (PostgreSQL)        │◄────────┤  app:seed-catalog (CLI)      │
│  - tariff_flat (102 cols)     │  5432   │  app:benchmark    (CLI)      │
│  - tariff_rel   (1M rows)     │         │  Symfony 8.1 web             │
│  - tariff_property (100 rows) │         │  /search, /benchmark, …      │
│  - tariff_value (100M rows)   │         │                              │
│  - tariff_jsonb  (1M rows)    │         │  TCPDF (PDF-отчёт)           │
└───────────────────────────────┘         └──────────────────────────────┘
                                                    ▲
                                                    │  HTTP :8080
                                                    │
                                          ┌──────────────────┐
                                          │  Nginx 1.27      │
                                          │  (reverse proxy) │
                                          └──────────────────┘
```

**Стек**

| Компонент | Версия | Назначение |
|---|---|---|
| Symfony | 8.1.* | Веб-фреймворк |
| PHP | 8.5 | Язык + FPM |
| PostgreSQL | 16-alpine | БД |
| Nginx | 1.27-alpine | Reverse proxy |
| Composer | 2.8 | Зависимости |
| Doctrine ORM | 3.x | ORM + миграции |
| Twig | 3.x | Шаблоны |
| TCPDF | 6.11 | Генерация PDF (с поддержкой кириллицы) |

---

## 📊 Что внутри

```
catalogtest/
├── bin/console                       # Symfony CLI
├── config/                           # Конфигурация (пакеты, маршруты, сервисы)
├── docker/
│   ├── nginx/default.conf            # Конфиг Nginx
│   └── postgres/initdb.d/            # Скрипты инициализации БД
├── docs/
│   ├── schema.md                     # Подробное описание схем
│   └── schema.sql                    # DDL
├── migrations/                       # Doctrine migrations
├── public/index.php                  # Front controller
├── src/
│   ├── Catalog/PropertyCatalog.php   # Словарь из 100 свойств (f001..f070, i001..i020, s001..s010)
│   ├── Command/                      # CLI-команды (seed, benchmark)
│   ├── Controller/                   # Web-контроллеры (Home, Search, Api)
│   ├── Entity/                       # Doctrine entities
│   ├── Repository/                   # Репозитории (flat, rel, jsonb)
│   └── Service/
│       ├── BenchmarkRunner.php       # Прогон OR-поиска по 3 реализациям
│       ├── CriteriaGenerator.php     # Генерация 100 OR-условий
│       └── ReportPdfBuilder.php      # PDF-отчёт (TCPDF + DejaVu)
├── templates/                        # Twig-шаблоны
├── var/                              # Кэш, логи, рантайм-данные (volume)
├── composer.json
├── docker-compose.yml
├── Dockerfile                        # Multi-stage PHP-FPM с DejaVu-шрифтами
├── .env
└── README.md
```

---

## 🛠️ Полезные команды

```bash
# === Сидирование ===
docker compose exec php php bin/console app:seed-catalog --variant=flat  -c 100000 --truncate
docker compose exec php php bin/console app:seed-catalog --variant=rel   -c 100000 --truncate
docker compose exec php php bin/console app:seed-catalog --variant=jsonb -c 100000 --truncate

# === Бенчмарк ===
docker compose exec php php bin/console app:benchmark -i 5                     # 5 итераций + EXPLAIN
docker compose exec php php bin/console app:benchmark -i 10 --no-plan          # 10 итераций без EXPLAIN
docker compose exec php php bin/console app:benchmark -i 5 -o /tmp/r.json     # свой путь к JSON

# === Миграции ===
docker compose exec php php bin/console doctrine:migrations:migrate -n
docker compose exec php php bin/console make:migration

# === Symfony CLI ===
docker compose exec php php bin/console debug:router
docker compose exec php php bin/console cache:clear
docker compose exec php php bin/console cache:clear --env=prod

# === Логи ===
docker compose logs -f php
docker compose logs -f nginx
docker compose logs -f database

# === psql к БД ===
docker compose exec database psql -U app -d app
docker compose exec database psql -U app -d app -c "SELECT count(*) FROM tariff_flat;"

# === Полная пересборка ===
docker compose down -v
docker compose up -d --build
```

---

## 🔌 Подключение к БД

Параметры для подключения из IDE (DBeaver, DataGrip, TablePlus):

| Параметр | Значение |
|---|---|
| Host | `localhost` |
| Port | `5432` |
| Database | `app` |
| User | `app` |
| Password | `app` |

Внутри Docker-сети имя хоста — `database` (см. `DATABASE_URL` в `.env`).

---

## 🎯 Сценарий тестирования

Эталонный сценарий (см. [src/Service/CriteriaGenerator.php](src/Service/CriteriaGenerator.php)):

```php
// 70 булевых: f001 IS TRUE … f070 IS TRUE
// 20 целых:   i001 = $v1  … i020 = $v20
// 10 строк:   s001 = $s1  … s010 = $s10
//
// WHERE p_b_001 IS TRUE OR … OR p_b_070 IS TRUE
//    OR p_i_001 = :v_i001 OR … OR p_i_020 = :v_i020
//    OR p_s_001 = :v_s001 OR … OR p_s_010 = :v_s010
// LIMIT 1000
```

Все три реализации получают **один и тот же набор критериев** и **одни и те же данные** — разница только в способе хранения.

---

## 🔐 Безопасность

Перед деплоем на прод:

1. Смените `APP_SECRET` (см. `.env`).
2. Смените пароль БД (`POSTGRES_PASSWORD` в `docker-compose.yml`).
3. Поменяйте учётные данные в `DATABASE_URL`.
4. Используйте отдельный `compose.prod.yaml` для production-конфига.
5. Запустите `composer dump-env prod` для компиляции `.env` в production-режиме.
6. **Секреты храните через Symfony Secrets или переменные окружения CI/CD, не в git.**

---

## 🐛 Troubleshooting

**`Class "App\Service\SimplePdf" not found`**

После изменения состава классов `Service/` сбросьте кэш контейнера:
```bash
docker compose exec php rm -rf var/cache/*
docker compose exec php php bin/console cache:clear
```

**`TTF is missing required tables`**

Если используете свою версию SimplePdf — она требует TTF-шрифт с таблицами `head/hhea/maxp/cmap/loca/glyf/hmtx`. Установите `font-dejavu` в Alpine:
```bash
docker compose exec php apk add --no-cache font-dejavu
```

**PDF не открывается / кириллица квадратиками**

Убедитесь, что TCPDF установлен:
```bash
docker compose exec php composer show tecnickcom/tcpdf
```

**`Too few arguments to function SearchController::__construct()`**

DI-контейнер закеширован со старой сигнатурой:
```bash
docker compose exec php php bin/console cache:clear
```

---

## 📜 Лицензия

[MIT](LICENSE)
