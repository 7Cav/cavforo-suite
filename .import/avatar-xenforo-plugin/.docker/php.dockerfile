FROM jarvvski/xenforo-2-php-fpm:alpine

# Install PHP pecl deps for installing php modules
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS;

RUN if [ "$(uname -m)" = "x86_64" ]; then \
        pecl install xdebug && \
        docker-php-ext-enable xdebug; \
    fi

# clear up php pecl deps
RUN apk del .phpize-deps
