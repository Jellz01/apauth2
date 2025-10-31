FROM alpine:3.18

MAINTAINER 2stacks <2stacks@2stacks.net>

# Use docker build --pull -t 2stacks/freeradius .

# Image details
LABEL net.2stacks.name="2stacks" \
      net.2stacks.license="MIT" \
      net.2stacks.description="Dockerfile for autobuilds" \
      net.2stacks.url="http://www.2stacks.net" \
      net.2stacks.vcs-type="Git" \
      net.2stacks.version="1.5.1" \
      net.2stacks.radius.version="3.0.20-r1"

RUN apk --update add freeradius freeradius-mysql freeradius-eap openssl openssl-dev

EXPOSE 1812/udp 1813/udp 4325/udp

ENV DB_HOST=mysql
ENV DB_PORT=3306
ENV DB_USER=radius
ENV DB_PASS=radpass
ENV DB_NAME=radius
ENV RAD_DEBUG=yes

ADD --chown=root:radius ./etc/raddb/ /etc/raddb
RUN /etc/raddb/certs/bootstrap && \
    chown -R root:radius /etc/raddb/certs && \
    chmod 640 /etc/raddb/certs/*.pem

ADD ./scripts/start.sh /start.sh
ADD ./scripts/wait-for.sh /wait-for.sh

RUN chmod +x /start.sh /wait-for.sh

CMD ["/start.sh"]