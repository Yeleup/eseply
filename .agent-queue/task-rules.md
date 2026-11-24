# Правила проекта для заданий очереди (esepteu)

<!-- Координатор вставляет этот файл целиком в задания исполнителю и ревьюеру. Меняет файл только владелец. -->

Читать перед задачей: docs/technical-specification.md, docs/business-rules.md, нужные docs/modules/*.md.

- Тесты: только `make test` (одноразовый контейнер с кодом этого worktree и его тестовой базой MariaDB); при работе
  `test_args="--compact --filter=…"`; перед отчётом — полный `make test` на закоммиченном дереве без незакоммиченных и
  неотслеживаемых файлов, в отчёте — сводка Pest. Никогда хостовый `php artisan`, `composer`, `vendor/bin/pest` и
  `php artisan test` через `docker exec` (он идёт по базе разработки основного чекаута, RefreshDatabase её стирает).
- PHP: после правок `vendor/bin/pint --dirty --format agent` на хосте (у worktree свой vendor/).
- Поведение и бизнес-логика (расчёты, проверки, статусы, связи сущностей, роли и права; квитанции, оплаты, начисления,
  тарифы, нормативы, счётчики, услуги): обновить docs/ (docs/technical-specification.md, docs/business-rules.md,
  docs/modules/*.md — поведение, не реализация; нет файла модуля — создать) и запись в docs/changelog.md, не переставляя
  старые записи.
- Интерфейс: изменение того, что видит пользователь, повторить в превью дизайна resources/views/design-preview.blade.php
  (маршрут /design-preview) во всех затронутых видах экрана: пустом, загрузке, ошибке, узкой ширине, тёмной теме.
- Octane: без состояния запроса в синглтонах и static-свойствах; в конструктор синглтона не передавать контейнер,
  запрос и конфиг.
- Docker, Makefile, orca.yaml, docker/app/*, docker/worktree/*: ту же правку в обобщённом виде — в
  laravel-docker-template (.ai/guidelines/laravel-docker-template.md).
- Сообщения коммитов — по-русски, в стиле `git log` репозитория («Раздел: что изменилось для пользователей (#N)»),
  без префикса `type(scope):`.
- Worktree готовит `make worktree-setup` (orca.yaml setup): свой .env, порты, базы и пользователь на MariaDB основного
  чекаута, свой vendor/; убирает их архивный хук orca.yaml при удалении worktree. Make пишет, что .env не для этого
  worktree или нет vendor/, — `make worktree-setup`, и ничего больше. Новые миграции — `make artisan
  artisan_args="migrate"` (база worktree).
- Проверка интерфейса — только `make up` в своём worktree (изолированный стенд, база пустая:
  `make artisan artisan_args="db:seed"`), после — `make down`; никогда стенд основного чекаута (его APP_URL, порт Vite,
  контейнеры).
- laravel-boost — только проектный MCP-сервер (отвечает по этому worktree и его базе); пользовательский плагин Boost
  отвечает из основного чекаута.
- В worktree нельзя: `docker exec esepteu-app-1` и другие контейнеры основного чекаута, `make init`, копировать или
  связывать .env основного чекаута, `DOCKER_PROJECT_NAME` или `docker compose -p` основного проекта, `make deploy`,
  заходить на боевой сервер.
- Ловушки: `make test` форсирует `QUEUE_CONNECTION=sync` и `CACHE_STORE=array` (джобы в тестах синхронны). После
  `Filament::setTenant()` (и хелперов, которые его зовут: `actingAsReportsTenant`, `actingAsReportsController`,
  `actingAsCrudTenant`, `actingAsDashboardMember`, `readingEntryOperator`, `readingEntryController`) фабрики ставят
  `organization_id` текущего тенанта и игнорируют `->for($другаяОрганизация)`: фикстуры чужой организации создавать до
  установки тенанта и сразу проверять их `organization_id`.
