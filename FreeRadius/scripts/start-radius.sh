#!/bin/bash

# Wait for MySQL to be ready
echo "Waiting for MySQL to be ready..."
while ! mysqladmin ping -h"$MYSQL_HOST" -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --silent; do
    echo "MySQL is unavailable - sleeping"
    sleep 2
done

echo "MySQL is up - starting FreeRADIUS"

# Substitute environment variables in SQL configuration
envsubst < /etc/freeradius/3.0/mods-available/sql > /etc/freeradius/3.0/mods-enabled/sql

# Set permissions
chown -R freerad:freerad /etc/freeradius/3.0/

# Test MySQL connection and check for MAC auth entries
echo "Testing MySQL connection and MAC authentication setup..."
mysql -h"$MYSQL_HOST" -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -e "USE $MYSQL_DATABASE; SELECT username, attribute, op, value FROM radcheck WHERE attribute = 'Auth-Type';" 2>/dev/null

if [ $? -eq 0 ]; then
    echo "MySQL connection successful"
    echo "MAC authentication entries:"
    mysql -h"$MYSQL_HOST" -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" -e "USE $MYSQL_DATABASE; SELECT username, attribute, op, value FROM radcheck WHERE attribute = 'Auth-Type';" 2>/dev/null
else
    echo "WARNING: Cannot connect to MySQL database"
fi

# Start FreeRADIUS in foreground with debug
echo "Starting FreeRADIUS..."
exec freeradius -f -X