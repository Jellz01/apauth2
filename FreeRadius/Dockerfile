# ================================
# 🐳 Custom FreeRADIUS with MySQL + MAC Authorization
# ================================

FROM ubuntu:22.04


# -------------------------------------------------------------
# ✅ Install FreeRADIUS + MySQL support + envsubst
# -------------------------------------------------------------
 #-------------------------------------------------------------
# ✅ Install FreeRADIUS + MySQL client + helpers
# -------------------------------------------------------------
RUN echo 'Acquire::Check-Valid-Until "false";\nAcquire::Check-Date "false";' \
      > /etc/apt/apt.conf.d/99allow-future && \
    apt-get update && \
    DEBIAN_FRONTEND=noninteractive \
    apt-get install -y --no-install-recommends \
        freeradius \
        freeradius-config \
        freeradius-mysql \
        freeradius-utils \
        mysql-client \
        tzdata \
        ca-certificates \
        bash \
        gettext-base \
        net-tools \
        procps && \
    rm -rf /var/lib/apt/lists/*

# -------------------------------------------------------------
# ✅ Default ENV (override in docker-compose)
# -------------------------------------------------------------
ENV MYSQL_HOST=mysql_server \
    MYSQL_USER=radius \
    MYSQL_PASSWORD=radiuspass \
    MYSQL_DATABASE=radius \
    SQL_CONFIG_FILE=/etc/freeradius/3.0/mods-enabled/sql \
    DEBUG=false

# -------------------------------------------------------------
# ✅ Copy configs (adjust these paths to your context)
#    NOTE: If your 'sql' is a single file, use the first COPY.
#    If it's a directory with multiple files, use the second one.
# -------------------------------------------------------------
# (1) Single file variant — most common:
COPY mods-enabled/sql /etc/freeradius/3.0/mods-enabled/sql
# (2) Directory variant (uncomment if you truly have a folder):
# COPY mods-enabled/sql/ /etc/freeradius/3.0/mods-enabled/sql/

COPY sites-enabled/default /etc/freeradius/3.0/sites-enabled/default
COPY clients.conf /etc/freeradius/3.0/clients.conf

# MAC normalization policy
RUN mkdir -p /etc/freeradius/3.0/policy.d
COPY policy.d/mac-auth /etc/freeradius/3.0/policy.d/mac-auth

# Helper script if you use it
COPY insert_mac.sh /etc/freeradius/insert_mac.sh
RUN chmod +x /etc/freeradius/insert_mac.sh || true

# -------------------------------------------------------------
# ✅ Permissions (safe defaults)
# -------------------------------------------------------------
RUN chown -R freerad:freerad /etc/freeradius && \
    chmod 640 /etc/freeradius/3.0/clients.conf && \
    find /etc/freeradius/3.0/ -type f -name "*.conf" -exec chmod 640 {} \; || true && \
    find /etc/freeradius/3.0/ -type d -exec chmod 750 {} \; || true

# -------------------------------------------------------------
# ✅ Expose RADIUS ports (Auth + Accounting + CoA)
# -------------------------------------------------------------
EXPOSE 1812/udp 1813/udp 3799/udp

# -------------------------------------------------------------
# ✅ Entrypoint
# -------------------------------------------------------------
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && \
    # Convert possible CRLF from Windows checkouts
    sed -i 's/\r$//' /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]