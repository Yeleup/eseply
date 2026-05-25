#!/bin/sh
set -eu

test_database="${MARIADB_TEST_DATABASE:-}"

if [ -z "${test_database}" ]; then
    echo "MARIADB_TEST_DATABASE is empty, skipping test database initialization."
    exit 0
fi

main_database="${MARIADB_DATABASE:-}"

if [ "${test_database}" = "${main_database}" ]; then
    echo "MARIADB_TEST_DATABASE must differ from MARIADB_DATABASE." >&2
    exit 1
fi

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<SQL
CREATE DATABASE IF NOT EXISTS \`${test_database}\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON \`${test_database}\`.* TO '${MARIADB_USER}'@'%';
FLUSH PRIVILEGES;
SQL
