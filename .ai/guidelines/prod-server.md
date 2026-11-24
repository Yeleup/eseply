# Боевой сервер

- Алиас `esepteu-prod`, пользователь `developer`, проект в `~/eseply`.
- Деплой: `server-kit job esepteu deploy -- 'cd ~/eseply && make deploy'`, затем `server-kit job-wait esepteu <задание>`. Если на сервере `make deploy-status` отвечает «No rule to make target», там ещё Makefile без блокировки: этот первый деплой — `'cd ~/eseply && git pull && make deploy'` и только когда другая команда стека не идёт (`docs/deployment.md`, «Первый деплой с блокировкой»). Отката нет: команды отката не существует. Состояние (только чтение): `ssh esepteu-prod 'cd ~/eseply && make deploy-status'`.
- Нагрузка: `server-status esepteu`. Ошибки приложения: `server-kit logs esepteu --errors`.
- Стек — один Docker Compose проект из `docker-compose.yml` (Octane на FrankenPHP, очередь, планировщик, MariaDB, Redis): `make deploy` пересобирает и пересоздаёт его на месте, приложение при этом коротко недоступно. Команды, которые меняют стек, идут по одной: если занято, команда сразу отказывает и называет, что уже идёт, — не повторять её в цикле и не останавливать ту команду без просьбы владельца (`docs/deployment.md`, «Одна команда за раз»). `make import` и `make import-media` в production не отказывают и заменяют боевую базу и файлы — только по просьбе владельца.
- Переезд: `docs/deployment.md`, раздел «Перенос на другой сервер».
- Доступ — по общему правилу «Боевые серверы» и инструкции server-kit (`server-kit help`).
