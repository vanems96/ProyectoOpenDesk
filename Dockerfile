FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libssl-dev pkg-config \
    && pecl install mongodb-2.1.1 && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/opendesk
COPY --chown=www-data:www-data . .
COPY config.example.php /opt/opendesk/config.php
ENV OPENDESK_PRIVATE_CONFIG=/opt/opendesk/config.php
USER www-data
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/var/www/opendesk"]
