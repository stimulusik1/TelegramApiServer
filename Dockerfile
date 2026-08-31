FROM danog/madelineproto:latest

STOPSIGNAL SIGTERM

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest \
    /wait /usr/local/bin/docker-compose-wait

COPY . .

RUN chmod +x /app/entrypoint.sh

RUN echo 1.0.0 > /tas_version

RUN echo -e "\nopcache.jit=off" >> $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0

ENTRYPOINT ["/app/entrypoint.sh"]
