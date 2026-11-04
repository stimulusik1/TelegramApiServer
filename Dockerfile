# Pin the base used by the last built image; latest changed its default build user.
FROM danog/madelineproto@sha256:5e987806f071dd8b2fa1fab7850538eec81ab8d4b1088d14385166e1762db9ac

STOPSIGNAL SIGTERM

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest \
    /wait /usr/local/bin/docker-compose-wait

# Сначала копируем файлы Composer
COPY composer.json composer.lock ./

# Install locked archives: the fork contains a locked commit not reachable by git clone.
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

# Затем копируем приложение
COPY . .

RUN chmod +x /app/entrypoint.sh

RUN echo 1.0.0 > /tas_version

RUN echo -e "\nopcache.jit=off" >> $PHP_INI_DIR/php.ini

EXPOSE 9503

ENV UV_USE_IO_URING=0

ENTRYPOINT ["/app/entrypoint.sh"]
