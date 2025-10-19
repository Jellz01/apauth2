#!/bin/bash
MAC="$1"
/usr/bin/mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" -D "$MYSQL_DATABASE" \
  -e "INSERT IGNORE INTO clients (mac, enabled) VALUES ('$MAC', 0);"
