#!/bin/bash
set -e

echo "🚀 Starting FreeRADIUS entrypoint..."

# Wait for MySQL to be ready
echo "⏳ Waiting for MySQL to be ready..."
until mysql -h"${MYSQL_HOST}" -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" -e "SELECT 1" >/dev/null 2>&1; do
  echo "   MySQL is unavailable - sleeping"
  sleep 2
done
echo "✅ MySQL is ready!"

# Substitute environment variables in SQL config
echo "🔧 Configuring SQL module with environment variables..."
envsubst < /etc/freeradius/3.0/mods-enabled/sql > /tmp/sql.tmp
mv /tmp/sql.tmp /etc/freeradius/3.0/mods-enabled/sql
chown freerad:freerad /etc/freeradius/3.0/mods-enabled/sql
chmod 640 /etc/freeradius/3.0/mods-enabled/sql

# Test database connection
echo "🔍 Testing database connection..."
mysql -h"${MYSQL_HOST}" -u"${MYSQL_USER}" -p"${MYSQL_PASSWORD}" "${MYSQL_DATABASE}" -e "SHOW TABLES;" || {
  echo "❌ Database connection failed!"
  exit 1
}
echo "✅ Database connection successful!"

# Show network configuration
echo "📡 Network Configuration:"
echo "   Container IP addresses:"
ip addr show | grep "inet " | awk '{print "   - " $2}'

# Test configuration before starting
echo "🔍 Testing FreeRADIUS configuration..."
freeradius -C || {
  echo "❌ FreeRADIUS configuration test failed!"
  exit 1
}
echo "✅ FreeRADIUS configuration is valid!"

# Show listening ports
echo "📡 FreeRADIUS will listen on:"
echo "   - Authentication: 0.0.0.0:1812/udp"
echo "   - Accounting: 0.0.0.0:1813/udp"
echo "   - CoA: 0.0.0.0:3799/udp"

# Show configured clients
echo "👥 Configured RADIUS clients:"
grep -E "^client " /etc/freeradius/3.0/clients.conf | while read -r line; do
  echo "   - $line"
done

# Show client IP addresses
echo "🌐 Client IP addresses configured:"
grep -E "^\s*ipaddr" /etc/freeradius/3.0/clients.conf | while read -r line; do
  echo "   $line"
done

# Create log directory if it doesn't exist
mkdir -p /var/log/freeradius
chown -R freerad:freerad /var/log/freeradius

# Debug mode
if [ "${DEBUG}" = "true" ]; then
  echo "🐛 Debug mode enabled - starting FreeRADIUS in debug mode..."
  echo "📝 ALL requests and responses will be logged"
  echo "⚠️  Passwords will be visible in logs - USE ONLY FOR TESTING!"
  echo ""
  echo "════════════════════════════════════════════════════════════"
  echo "  FreeRADIUS Debug Mode Active"
  echo "  Waiting for RADIUS requests..."
  echo "════════════════════════════════════════════════════════════"
  echo ""
  exec freeradius -X
else
  echo "▶️  Starting FreeRADIUS in normal mode with verbose logging..."
  echo "💡 To see debug output, set DEBUG=true in docker-compose.yml"
  echo ""
  echo "════════════════════════════════════════════════════════════"
  echo "  FreeRADIUS Started"
  echo "  Logs: docker logs -f freeradius"
  echo "════════════════════════════════════════════════════════════"
  echo ""
  exec freeradius -f -l stdout
fi