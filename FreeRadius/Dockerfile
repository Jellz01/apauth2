# ================================
# 🐳 Custom FreeRADIUS with MySQL + MAC Authorization
# ================================

FROM ubuntu:22.04

LABEL maintainer="Joseph Wellesley <you@example.com>"

ENV DEBIAN_FRONTEND=noninteractive

# -------------------------------------------------------------
# ✅ Install FreeRADIUS + MySQL support + envsubst
# -------------------------------------------------------------
RUN echo 'Acquire::Check-Valid-Until "false";\nAcquire::Check-Date "false";' \
      > /etc/apt/apt.conf.d/99allow-future && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
        freeradius \
        gettext \
        freeradius-config \
        freeradius-mysql \
        freeradius-utils \
        mysql-client \
        tzdata \
        ca-certificates \
        bash \
        gettext-base && \
    rm -rf /var/lib/apt/lists/*

  RUN apt-get update && apt-get install -y gettext-base


# -------------------------------------------------------------
# ✅ Copy configs
# -------------------------------------------------------------
COPY mods-enabled/sql /etc/freeradius/3.0/mods-enabled/sql
COPY sites-enabled/default /etc/freeradius/3.0/sites-enabled/default
COPY clients.conf /etc/freeradius/3.0/clients.conf
COPY insert_mac.sh /etc/freeradius/insert_mac.sh

# -------------------------------------------------------------
# ✅ Permissions
# -------------------------------------------------------------
RUN chown -R freerad:freerad /etc/freeradius && \
    chmod 640 /etc/freeradius/3.0/clients.conf && \
    chmod 640 /etc/freeradius/3.0/radiusd.conf && \
    find /etc/freeradius/3.0/ -type f -name "*.conf" -exec chmod 640 {} \; && \
    find /etc/freeradius/3.0/ -type d -exec chmod 750 {} \; && \
    chmod +x /etc/freeradius/insert_mac.sh


# -------------------------------------------------------------
# ✅ Expose ports
# -------------------------------------------------------------
EXPOSE 1812/udp
EXPOSE 1813/udp

# -------------------------------------------------------------
# ✅ Runtime entrypoint: export env vars before starting radius
# -------------------------------------------------------------
ENTRYPOINT ["/bin/bash", "-c", "\
  /usr/bin/envsubst < /etc/freeradius/3.0/mods-enabled/sql > /tmp/sql && \
  mv /tmp/sql /etc/freeradius/3.0/mods-enabled/sql && \
  exec freeradius -f -l stdout -X \
"]


