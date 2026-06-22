---
created: 2026-06-22T14:35:56 (UTC +05:00)
tags: []
source: https://xpayment.kz/docs
author: 
---

# API для разработчиков

> ## Excerpt
> Справочник API xpayment для интеграций на уровне устройства через xdev_*: платежи, возвраты, тестирование вебхуков, статический QR и примеры запросов.

---
[Скачать OpenAPIxpayment-developer-api.json](https://xpayment.kz/openapi/xpayment-developer-api.json)

JSON-файл для импорта в Postman, Insomnia или mock-сервер

Вставьте ваш xdev\_\* API ключ устройства. Он будет автоматически подставляться во все защищённые запросы на этой странице.

### Базовый URL

`https://api.xpayment.kz/v1`

### Авторизация

Эта страница описывает API на уровне устройства. Для защищённых эндпоинтов передавайте API ключ устройства в заголовке Authorization.

Заголовок запроса

```
Authorization: Bearer xdev_YOUR_API_KEY
```

### Идемпотентность

При создании платежей рекомендуем отправлять заголовок X-Idempotency-Key с уникальным ID запроса. Это предотвратит дублирование при повторных попытках.

```
X-Idempotency-Key: unique-request-id-per-attempt
```

### Формат ошибок

Все ошибки API возвращают JSON с двумя полями: error (машиночитаемый код) и message (человекочитаемое описание).

```
{
  "error": "PAYMENT_NOT_FOUND",
  "message": "Payment with the given ID does not exist"
}
```

|Код|HTTP|Описание|
|---|---|---|
|`PAYMENT_NOT_FOUND`|404|Платёж с указанным ID не найден.|
|`DEVICE_NOT_FOUND`|404|Устройство не найдено.|
|`DEVICE_INACTIVE`|422|Устройство деактивировано.|
|`KASPI_CLIENT_NOT_FOUND`|422|Клиент Kaspi не найден — по указанному номеру телефона не удалось выставить счёт.|
|`KASPI_ERROR`|422|Ошибка шлюза Kaspi — сессия недействительна или устройство не зарегистрировано.|
|`DEVICE_TOKEN_REVOKED`|422|Токен Kaspi отозван. Устройство требует повторной регистрации.|
|`PAYMENT_NOT_CANCELABLE`|409|Платёж нельзя отменить в текущем статусе.|
|`NO_ACTIVE_BILLING`|402|Нет активного тарифного плана.|
|`INSUFFICIENT_BALANCE`|402|Недостаточно баланса для проведения платежа.|
|`DAILY_TX_LIMIT_REACHED`|429|Достигнут дневной лимит транзакций.|
|`INTERNAL_ERROR`|500|Внутренняя ошибка сервера.|

### Пагинация

Эндпоинты-списки используют курсорную пагинацию. Если has\_more равен true, передайте значение next\_cursor в параметре cursor следующего запроса.

```
{
  "data": [...],
  "has_more": true,
  "next_cursor": "eyJpZCI6Mn0="
}
```

Возвращает пагинированный список платежей для авторизованного владельца API ключа устройства.

Bearer авторизация

### Query параметры

ИмяТипОбяз.Описание

`status`stringнетФильтр по статусу (pending, completed, cancelled, failed)

`payer_phone`stringнетФильтр по телефону плательщика

`merchant_order_id`stringнетФильтр по ID заказа мерчанта

`cursor`stringнетКурсор пагинации из предыдущего ответа

### Ответ

ИмяТипОбяз.Описание

`data`object\[\]нетdata

`has_more`booleanнетhas\_more

`next_cursor`stringнетnext\_cursor

Ответ • JSON

```
{
  "data": [
    {
      "amount": 1500,
      "cancel_reason": "",
      "cancelled_at": "",
      "comment": "Order #42",
      "completed_at": "",
      "created_at": "",
      "currency": "KZT",
      "merchant_order_id": "order-uuid",
      "metadata": {},
      "payer_phone": "+77001234567",
      "payment_id": "uuid",
      "status": "pending",
      "updated_at": "",
      "user_id": "uuid"
    }
  ],
  "has_more": false,
  "next_cursor": ""
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X GET "https://api.xpayment.kz/v1/payments?status=completed" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Возвращает детали платежа по ID.

Bearer авторизация

### Параметры пути

ИмяТипОбяз.Описание

`paymentID`stringдаID платежа

### Ответ

ИмяТипОбяз.Описание

`amount`numberнетamount

`cancel_reason`stringнетcancel\_reason

`cancelled_at`stringнетcancelled\_at

`comment`stringнетcomment

`completed_at`stringнетcompleted\_at

`created_at`stringнетcreated\_at

`currency`stringнетcurrency

`merchant_order_id`stringнетВаш идентификатор заказа (опционально)

`metadata`objectнетmetadata

`payer_phone`stringнетpayer\_phone

`payment_id`stringнетpayment\_id

`status`stringнетstatus

`updated_at`stringнетupdated\_at

`user_id`stringнетuser\_id

Ответ • JSON

```
{
  "amount": 1500,
  "cancel_reason": "",
  "cancelled_at": "",
  "comment": "Order #42",
  "completed_at": "",
  "created_at": "",
  "currency": "KZT",
  "merchant_order_id": "order-uuid",
  "metadata": {},
  "payer_phone": "+77001234567",
  "payment_id": "uuid",
  "status": "pending",
  "updated_at": "",
  "user_id": "uuid"
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X GET "https://api.xpayment.kz/v1/payments/PAYMENT_ID" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Инициирует новый QR-платёж Kaspi для авторизованного API ключа устройства.

Bearer авторизация

### Заголовки

ИмяТипОбяз.Описание

`X-Idempotency-Key`stringнетКлюч идемпотентности

### Тело запроса

ИмяТипОбяз.ОписаниеЗначение

`amount`numberнетamount

`comment`stringнетcomment

`merchant_order_id`stringнетВаш идентификатор заказа (опционально)

`metadata`objectнетmetadata

`payer_phone`stringнетpayer\_phone

JSON

```
{
  "amount": 1500,
  "comment": "Order #42",
  "merchant_order_id": "order-uuid",
  "metadata": {},
  "payer_phone": 77001234567
}
```

### Ответ

ИмяТипОбяз.Описание

`amount`numberнетamount

`cancel_reason`stringнетcancel\_reason

`cancelled_at`stringнетcancelled\_at

`comment`stringнетcomment

`completed_at`stringнетcompleted\_at

`created_at`stringнетcreated\_at

`currency`stringнетcurrency

`merchant_order_id`stringнетВаш идентификатор заказа (опционально)

`metadata`objectнетmetadata

`payer_phone`stringнетpayer\_phone

`payment_id`stringнетpayment\_id

`status`stringнетstatus

`updated_at`stringнетupdated\_at

`user_id`stringнетuser\_id

Ответ • JSON

```
{
  "amount": 1500,
  "cancel_reason": "",
  "cancelled_at": "",
  "comment": "Order #42",
  "completed_at": "",
  "created_at": "",
  "currency": "KZT",
  "merchant_order_id": "order-uuid",
  "metadata": {},
  "payer_phone": "+77001234567",
  "payment_id": "uuid",
  "status": "pending",
  "updated_at": "",
  "user_id": "uuid"
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X POST "https://api.xpayment.kz/v1/payments" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "X-Idempotency-Key: unique-request-id" \
  -d '{
    "payer_phone": "+77001234567",
    "amount": 1500,
    "comment": "Order #42",
    "merchant_order_id": "order-uuid"
  }'
```

Отменяет ожидающий платёж.

Bearer авторизация

### Параметры пути

ИмяТипОбяз.Описание

`paymentID`stringдаID платежа

### Тело запроса

ИмяТипОбяз.ОписаниеЗначение

`reason`stringнетreason

JSON

```
{
  "reason": "Customer requested cancellation"
}
```

### Ответ

ИмяТипОбяз.Описание

`amount`numberнетamount

`cancel_reason`stringнетcancel\_reason

`cancelled_at`stringнетcancelled\_at

`comment`stringнетcomment

`completed_at`stringнетcompleted\_at

`created_at`stringнетcreated\_at

`currency`stringнетcurrency

`merchant_order_id`stringнетВаш идентификатор заказа (опционально)

`metadata`objectнетmetadata

`payer_phone`stringнетpayer\_phone

`payment_id`stringнетpayment\_id

`status`stringнетstatus

`updated_at`stringнетupdated\_at

`user_id`stringнетuser\_id

Ответ • JSON

```
{
  "amount": 1500,
  "cancel_reason": "",
  "cancelled_at": "",
  "comment": "Order #42",
  "completed_at": "",
  "created_at": "",
  "currency": "KZT",
  "merchant_order_id": "order-uuid",
  "metadata": {},
  "payer_phone": "+77001234567",
  "payment_id": "uuid",
  "status": "pending",
  "updated_at": "",
  "user_id": "uuid"
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X POST "https://api.xpayment.kz/v1/payments/PAYMENT_ID/cancel" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Customer requested cancellation"}'
```

Генерирует ссылку на QR-платёж Kaspi для указанной суммы. Возвращённый URL qr\_token можно показать как QR-код или открыть напрямую.

Bearer авторизация

### Тело запроса

ИмяТипОбяз.ОписаниеЗначение

`amount`numberнетamount

`merchant_order_id`stringнетВаш идентификатор заказа (опционально)

JSON

```
{
  "amount": 1500,
  "merchant_order_id": "order-uuid"
}
```

### Ответ

ИмяТипОбяз.Описание

`expire_date`stringнетexpire\_date

`ext_tran_id`stringнетext\_tran\_id

`payment_id`stringнетpayment\_id

`qr_operation_id`integerнетqr\_operation\_id

`qr_token`stringнетqr\_token

`status`stringнетstatus

Ответ • JSON

```
{
  "expire_date": "2026-04-06T11:39:53.000+05:00",
  "ext_tran_id": "QR14893934009",
  "payment_id": "uuid",
  "qr_operation_id": 14893934009,
  "qr_token": "https://qr.kaspi.kz/55308082630746899169618031642188672381021",
  "status": "QrTokenCreated"
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X POST "https://api.xpayment.kz/v1/payments/link" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"amount": 1500, "device_interface": "Pos"}'
```

Возвращает все возвраты для указанного платежа.

Bearer авторизация

### Параметры пути

ИмяТипОбяз.Описание

`paymentID`stringдаID платежа

### Ответ

ИмяТипОбяз.Описание

`data`object\[\]нетdata

`has_more`booleanнетhas\_more

`next_cursor`stringнетnext\_cursor

Ответ • JSON

```
{
  "data": [
    {
      "amount": 500,
      "completed_at": "",
      "created_at": "",
      "fail_reason": "",
      "payment_id": "uuid",
      "reason": "Product returned",
      "refund_id": "uuid",
      "status": "pending",
      "updated_at": "",
      "user_id": "uuid"
    }
  ],
  "has_more": false,
  "next_cursor": ""
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X GET "https://api.xpayment.kz/v1/payments/PAYMENT_ID/refunds" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Возвращает пагинированный список всех возвратов для авторизованного мерчанта.

Bearer авторизация

### Query параметры

ИмяТипОбяз.Описание

`payment_id`stringнетФильтр по ID платежа

`status`stringнетФильтр по статусу (pending, completed, failed)

`cursor`stringнетКурсор пагинации

### Ответ

ИмяТипОбяз.Описание

`data`object\[\]нетdata

`has_more`booleanнетhas\_more

`next_cursor`stringнетnext\_cursor

Ответ • JSON

```
{
  "data": [
    {
      "amount": 500,
      "completed_at": "",
      "created_at": "",
      "fail_reason": "",
      "payment_id": "uuid",
      "reason": "Product returned",
      "refund_id": "uuid",
      "status": "pending",
      "updated_at": "",
      "user_id": "uuid"
    }
  ],
  "has_more": false,
  "next_cursor": ""
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X GET "https://api.xpayment.kz/v1/refunds?status=completed" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Возвращает детали возврата по ID.

Bearer авторизация

### Параметры пути

ИмяТипОбяз.Описание

`refundID`stringдаID возврата

### Ответ

ИмяТипОбяз.Описание

`amount`numberнетamount

`completed_at`stringнетcompleted\_at

`created_at`stringнетcreated\_at

`fail_reason`stringнетfail\_reason

`payment_id`stringнетpayment\_id

`reason`stringнетreason

`refund_id`stringнетrefund\_id

`status`stringнетstatus

`updated_at`stringнетupdated\_at

`user_id`stringнетuser\_id

Ответ • JSON

```
{
  "amount": 500,
  "completed_at": "",
  "created_at": "",
  "fail_reason": "",
  "payment_id": "uuid",
  "reason": "Product returned",
  "refund_id": "uuid",
  "status": "pending",
  "updated_at": "",
  "user_id": "uuid"
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X GET "https://api.xpayment.kz/v1/refunds/REFUND_ID" \
  -H "Authorization: Bearer YOUR_API_KEY"
```

Инициирует возврат для завершённого платежа.

Bearer авторизация

### Параметры пути

ИмяТипОбяз.Описание

`paymentID`stringдаID платежа

### Тело запроса

ИмяТипОбяз.ОписаниеЗначение

`amount`numberнетamount

`reason`stringнетreason

JSON

```
{
  "amount": 500,
  "reason": "Product returned"
}
```

### Ответ

ИмяТипОбяз.Описание

`amount`numberнетamount

`completed_at`stringнетcompleted\_at

`created_at`stringнетcreated\_at

`fail_reason`stringнетfail\_reason

`payment_id`stringнетpayment\_id

`reason`stringнетreason

`refund_id`stringнетrefund\_id

`status`stringнетstatus

`updated_at`stringнетupdated\_at

`user_id`stringнетuser\_id

Ответ • JSON

```
{
  "amount": 500,
  "completed_at": "",
  "created_at": "",
  "fail_reason": "",
  "payment_id": "uuid",
  "reason": "Product returned",
  "refund_id": "uuid",
  "status": "pending",
  "updated_at": "",
  "user_id": "uuid"
}
```

### Примеры кода

```
#!/usr/bin/env bash
curl -X POST "https://api.xpayment.kz/v1/payments/PAYMENT_ID/refund" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"amount": 500, "reason": "Product returned"}'
```

Возвращает все подписки вебхуков для мерчанта, которому принадлежит API ключ устройства.

Bearer авторизация

### Ответ

ИмяТипОбяз.Описание

`subscriptions`object\[\]нетsubscriptions

Ответ • JSON

```
{
  "subscriptions": [
    {
      "created_at": "",
      "description": "",
      "events": [
        ""
      ],
      "id": "uuid",
      "is_active": true,
      "organization_id": "uuid",
      "secret": "",
      "updated_at": "",
      "url": "https://example.com/webhook"
    }
  ]
}
```

### Примеры кода

```
curl -X GET "https://api.xpayment.kz/v1/webhook-simulate/subscriptions" \
  -H "Authorization: Bearer YOUR_BEARER_TOKEN"
```

Отправляет тестовую доставку вебхука для указанного типа события без создания реального платежа. Если subscription\_id не указан, тестируются все активные подписки.

Bearer авторизация

### Тело запроса

ИмяТипОбяз.ОписаниеЗначение

`event`objectнетevent

`merchant_order_id`stringнетВаш идентификатор заказа (опционально)

`subscription_id`stringнетsubscription\_id

JSON

```
{
  "event": "payment.completed",
  "merchant_order_id": "order-123",
  "subscription_id": "uuid"
}
```

### Ответ

ИмяТипОбяз.Описание

`results`object\[\]нетresults

Ответ • JSON

```
{
  "results": [
    {
      "error": "connection refused",
      "event": "payment.completed",
      "subscription_id": "uuid",
      "success": true,
      "url": "https://example.com/webhook"
    }
  ]
}
```

### Примеры кода

```
curl -X POST "https://api.xpayment.kz/v1/webhook-simulate" \
  -H "Authorization: Bearer YOUR_BEARER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
  "event": "payment.completed",
  "merchant_order_id": "order-123",
  "subscription_id": "uuid"
}'
```

Возвращает запись статического QR (Link) платежа из истории Kaspi.

Bearer авторизация

### Параметры пути

ИмяТипОбяз.Описание

`orderNumber`stringдаНомер заказа Kaspi (например, QR15029656207)

### Ответ

ИмяТипОбяз.Описание

`amount`numberнетamount

`client_short_name`stringнетclient\_short\_name

`comment`stringнетcomment

`features`integerнетfeatures

`id`integerнетid

`kaspi_operation_id`integerнетkaspi\_operation\_id

`operation_method`integerнетoperation\_method

`operation_type`integerнетoperation\_type

`order_number`stringнетorder\_number

`order_reg_date`stringнетorder\_reg\_date

`sale_id`integerнетsale\_id

`sale_type`stringнетsale\_type

`source_type`stringнетsource\_type

`synced_at`stringнетsynced\_at

Ответ • JSON

```
{
  "amount": 12,
  "client_short_name": "Рустем Е.",
  "comment": "Order #45",
  "features": 0,
  "id": 1,
  "kaspi_operation_id": 15029656207,
  "operation_method": 0,
  "operation_type": 0,
  "order_number": "QR15029656207",
  "order_reg_date": "2026-04-15T13:57:25+05:00",
  "sale_id": 14930989637,
  "sale_type": "Link",
  "source_type": "GOLD",
  "synced_at": "2026-04-15T14:00:00+05:00"
}
```

### Примеры кода

```
curl -X GET "https://api.xpayment.kz/v1/static-qr-payments/value" \
  -H "Authorization: Bearer YOUR_BEARER_TOKEN"
```
