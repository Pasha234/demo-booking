# Бронирование переговорных

API на PHP 8.4, Laravel 13 и PostgreSQL 17. Для запуска нужны Docker и Docker Compose.

## Первый запуск

Из корня проекта:

```sh
docker compose up -d --build --wait && docker compose exec app php artisan migrate --seed
```

Контейнер установит зависимости и создаст `.env`. Миграции подготовят базу, сидер добавит офисы, комнаты и демонстрационных пользователей. Первый запуск занимает несколько минут.

## Тесты

```sh
docker compose exec app php artisan test --compact
```

Тесты используют отдельную базу PostgreSQL `testing`.

