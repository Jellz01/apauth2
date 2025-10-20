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
        freeradius-config \
        freeradius-mysql \
        freeradius-utils \
        mysql-client \
        tzdata \
        ca-certificates \
        bash \
        gettext-base \
        net-tools && \
    rm -rf /var/lib/apt/lists/*

# -------------------------------------------------------------
# ✅ Copy configs
# -------------------------------------------------------------
COPY mods-enabled/sql /etc/freeradius/3.0/mods-enabled/sql
COPY sites-enabled/default /etc/freeradius/3.0/sites-enabled/default
COPY clients.conf /etc/freeradius/3.0/clients.conf
COPY insert_mac.sh /etc/freeradius/insert_mac.sh

# -------------------------------------------------------------
# ✅ Set permissions
# -------------------------------------------------------------
RUN chown -R freerad:freerad /etc/freeradius && \
    chmod 640 /etc/freeradius/3.0/clients.conf && \
    chmod 640 /etc/freeradius/3.0/radiusd.conf && \
    find /etc/freeradius/3.0/ -type f -name "*.conf" -exec chmod 640 {} \; && \
    find /etc/freeradius/3.0/ -type d -exec chmod 750 {} \; && \
    chmod +x /etc/freeradius/insert_mac.sh

# -------------------------------------------------------------
# ✅ Expose RADIUS ports (Auth + Accounting + CoA)
# -------------------------------------------------------------
EXPOSE 1812/udp
EXPOSE 1813/udp
EXPOSE 3799/udp


COPY entrypoint.sh /entrypoint.sh
RUN chmod +x entrypoint.sh
ENTRYPOINT ["entrypoint.sh"]

CMD ["freeradius", "-X"]