FROM danog/madelineproto:latest

STOPSIGNAL SIGTERM

WORKDIR /app

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

COPY --from=ghcr.io/ufoscout/docker-compose-wait:latest \
    /wait /usr/local/bin/docker-compose-wait

# Сначала копируем файлы Composer
COPY composer.json composer.lock ./

# Устанавливаем зависимости через Git, а не через GitHub ZIP
RUN composer install \
    --no-dev \
    --prefer-source \
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
