# Правки смежных компонентов

## Apps Script (`code` в репозитории calls_review)

Добавить в константу `HEADERS` четыре колонки (в конец):

```
review_status
review_score
reviewed_by
reviewed_at
```

Больше ничего: `ensureSheetHeaders_` допишет их на листе «Записи звонков» при следующем скане. Скрипт эти колонки не заполняет — их пишет только n8n-workflow «QA: запись вердиктов».

Значения:
- `review_status`: пусто (не проверено) | `CONFIRMED` | `SCORE_CHANGED`
- `review_score`: число 1–5 только при `SCORE_CHANGED`
- `reviewed_by`: bitrix_user_id проверяющего
- `reviewed_at`: `YYYY-MM-DD HH:MM:SS`

## Таблица журнала проверок (1VwcF6I1PjTFYw1Tpgav2t6qeZAkAaKzFm71aR5m0A80)

Создать лист `Проверки` с заголовками в строке 1:

```
timestamp | call_key | reviewer_bitrix_user_id | reviewer_name | action | ai_score | new_score | comment | group | operator_name | call_datetime | client_phone
```

Журнал append-only: workflow только добавляет строки. Ничего в нём не редактировать; исправления вердиктов в исключительных случаях — вручную и в журнале, и в колонках `review_*` основного листа.
