#!/bin/bash
set -e

echo '🔍 Checking environment variables...'
printenv | grep MYSQL || echo '❌ No MYSQL variables found'

echo '🔧 Applying envsubst to SQL config...'
if [ -f /etc/freeradius/3.0/mods-enabled/sql ]; then
    envsubst < /etc/freeradius/3.0/mods-enabled/sql > /tmp/sql.tmp
    mv /tmp/sql.tmp /etc/freeradius/3.0/mods-enabled/sql
    chown freerad:freerad /etc/freeradius/3.0/mods-enabled/sql
    chmod 640 /etc/freeradius/3.0/mods-enabled/sql
    echo '✅ SQL config updated with environment variables'
    echo '📄 SQL config contents:'
    cat /etc/freeradius/3.0/mods-enabled/sql
else
    echo '⚠️  SQL config not found at /etc/freeradius/3.0/mods-enabled/sql'
    exit 1
fi

echo '🚀 Starting FreeRADIUS in foreground mode...'
exec su -s /bin/bash freerad -c "/usr/sbin/freeradius -f -l stdout -X"