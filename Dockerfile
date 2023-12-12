FROM php:7.1.31-cli-alpine

RUN apk update && apk add php-imap imap-dev openssl-dev php-pcntl
RUN docker-php-ext-configure imap --with-imap --with-imap-ssl && docker-php-ext-install imap pcntl

COPY src/runner.php /app/runner.php

CMD ["php","/app/runner.php"]