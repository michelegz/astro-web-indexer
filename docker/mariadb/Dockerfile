FROM mariadb:10.11

COPY init.sql /docker-entrypoint-initdb.d/
COPY conf.d/custom.cnf /etc/mysql/conf.d/

# Set appropriate permissions
RUN chown -R mysql:mysql /docker-entrypoint-initdb.d/ /etc/mysql/conf.d/