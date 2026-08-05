<?php
/**
 * Скопируйте в config.php и заполните. config.php в git не коммитить.
 */
return [
    // --- Битрикс24 (локальное приложение: Разработчикам → Другое → Локальное приложение) ---
    'bitrix_portal'        => 'ваш-портал.bitrix24.ru',
    'bitrix_client_id'     => 'local.xxxxxxxxxxxxxx.xxxxxxxx',
    'bitrix_client_secret' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    // Оставьте пустым — адрес соберётся автоматически:
    // https://ваш-сайт/internal/calls-review-web/auth/callback
    // Ровно этот адрес указывается в настройках приложения Битрикс24.
    'bitrix_redirect_uri'  => '',

    // --- Google Sheets (сервисный аккаунт, доступ read-only к обеим таблицам) ---
    // JSON-ключ сервисного аккаунта (файл кладётся рядом с config.php).
    // Email аккаунта добавить читателем в обе таблицы.
    'google_service_account_json' => __DIR__ . '/service-account.json',
    // Основная таблица проекта (где «Записи звонков» и «Операторы»).
    'main_spreadsheet_id'  => '1xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    'calls_sheet_name'     => 'Записи звонков',
    'operators_sheet_name' => 'Операторы',

    // --- Запись вердиктов через n8n ---
    'n8n_verdict_webhook_url' => 'https://n8n.example.com/webhook/qa-verdict',
    'n8n_webhook_secret'      => 'длинный-случайный-секрет', // тот же в workflow

    // --- Прочее ---
    'cache_dir'        => __DIR__ . '/storage',
    'cache_ttl_sec'    => 45,      // кеш чтения Google Sheets
    'session_lifetime' => 43200,   // 12 часов
    'per_page_default' => 50,
];
