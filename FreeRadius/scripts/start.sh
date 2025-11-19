#!/bin/sh

echo "Waiting for MySQL to be ready..."
/wait-for.sh ${DB_HOST}:${DB_PORT} -t 30 -- echo "MySQL is up!"

echo "Starting FreeRADIUS..."

if [ "${RAD_DEBUG}" = "yes" ]; then
    echo "Starting in DEBUG mode"
    exec /usr/sbin/radiusd -X -f -d /etc/raddb
else
    echo "Starting in normal mode" 
    exec /usr/sbin/radiusd -f -d /etc/raddb
fi