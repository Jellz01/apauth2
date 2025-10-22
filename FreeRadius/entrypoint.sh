#!/bin/bash
set -e

echo " Starting FreeRADIUS entrypoint..."

# Wait for MySQL to be ready
echo " Waiting for MySQL to be ready..."
until mysql -h"${MYSQL_HOST}" -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; do
  echo "   MySQL is unavailable - sleeping"
  sleep 2
done
echo " MySQL is ready!"

# Substitute environment variables in SQL config
echo " Configuring SQL module with environment variables..."
envsubst < /etc/freeradius/3.0/mods-enabled/sql > /tmp/sql.tmp
mv /tmp/sql.tmp /etc/freeradius/3.0/mods-enabled/sql
chown freerad:freerad /etc/freeradius/3.0/mods-enabled/sql
chmod 640 /etc/freeradius/3.0/mods-enabled/sql

# Test database connection
echo " Testing database connection..."
mysql -h"${MYSQL_HOST}" -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" "${MYSQL_DATABASE}" -e "SHOW TABLES;" || {
  echo " Database connection failed!"
  exit 1
}
echo " Database connection successful!"

# Debug mode (optional)
if [ "${DEBUG}" = "true" ]; then
  echo " Debug mode enabled - starting FreeRADIUS in debug mode..."
  exec freeradius -X
else
  echo " Starting FreeRADIUS in normal mode..."
  exec freeradius -f
fi