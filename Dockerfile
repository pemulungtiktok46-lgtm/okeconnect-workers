FROM php:8.3-cli
RUN apt-get update && apt-get install -y git unzip libpq-dev libsqlite3-dev && docker-php-ext-install pdo pdo_sqlite pdo_pgsql curl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --prefer-dist --no-interaction
COPY . .
RUN mkdir -p storage logs && chmod -R 775 storage logs
EXPOSE 8080
CMD ["php","-S","0.0.0.0:8080","-t","public","public/router.php"]
