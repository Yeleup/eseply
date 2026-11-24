#!/usr/bin/env bash
# Прослойка на время перехода на agent-queue-kit: старые вызовы `bash .ai/skills/implement-review/queue.sh <команда>`
# (сессии со старым текстом правила) идут в команду кита. Удаляется отдельным коммитом по чек-листу остановки.
exec "$HOME/.local/bin/agent-queue" "$@"
