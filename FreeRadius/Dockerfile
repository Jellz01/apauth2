FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

# Set timezone
RUN ln -fs /usr/share/zoneinfo/UTC /etc/localtime


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

# Create directories
RUN mkdir -p /etc/freeradius/3.0/sites-available \
    /etc/freeradius/3.0/sites-enabled \
    /etc/freeradius/3.0/mods-available \
    /etc/freeradius/3.0/mods-enabled \
    /etc/freeradius/3.0/certs




# Copy configuration files
COPY radiusd.conf /etc/freeradius/3.0/
COPY clients.conf /etc/freeradius/3.0/
COPY sites-enabled/ /etc/freeradius/3.0/sites-available/
COPY mods-enabled/ /etc/freeradius/3.0/mods-available/


# Enable sites and modules
RUN ln -sf /etc/freeradius/3.0/sites-available/default /etc/freeradius/3.0/sites-enabled/ \
    && ln -sf /etc/freeradius/3.0/sites-available/inner-tunnel /etc/freeradius/3.0/sites-enabled/ \
    && ln -sf /etc/freeradius/3.0/mods-available/sql /etc/freeradius/3.0/mods-enabled/ \
    && ln -sf /etc/freeradius/3.0/mods-available/eap /etc/freeradius/3.0/mods-enabled/

# Set permissions
RUN chown -R freerad:freerad /etc/freeradius/3.0/ \
    && chmod -R 644 /etc/freeradius/3.0/*.conf \
    && chmod 755 /etc/freeradius/3.0/sites-available/ \
    && chmod 755 /etc/freeradius/3.0/mods-available/ 

# Copy startup script
COPY scripts/start-radius.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/start-radius.sh

EXPOSE 1812/udp 1813/udp

CMD ["/usr/local/bin/start-radius.sh"]