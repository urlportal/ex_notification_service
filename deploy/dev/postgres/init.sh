#!/bin/bash
set -e

DATABASES=(
    "${NOTIFY_DB}:${NOTIFY_USER}:${NOTIFY_PASSWORD}"
)

create_user_and_database() {
    local db=$1
    local user=$2
    local pass=$3

    echo "Проверка и создание базы '$db' для пользователя '$user'..."

    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
        DO \$\$
        BEGIN
            IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '$user') THEN
                CREATE ROLE $user WITH LOGIN PASSWORD '$pass';
            END IF;
        END
        \$\$;
EOSQL

    local db_exists
    db_exists=$(psql -tAc "SELECT 1 FROM pg_database WHERE datname='$db'" --username "$POSTGRES_USER" --dbname "$POSTGRES_DB")

    if [ "$db_exists" != "1" ]; then
        psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
            CREATE DATABASE $db OWNER $user;
EOSQL
    fi

    psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" <<-EOSQL
        GRANT ALL ON SCHEMA public TO $user;
EOSQL
}

for item in "${DATABASES[@]}"; do
    IFS=":" read -r db_name user_name db_password <<< "$item"

    create_user_and_database "$db_name" "$user_name" "$db_password"
done

echo "init.sh: инициализация баз данных успешно завершена!"
