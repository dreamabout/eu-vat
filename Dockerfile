# The package is a pure library: no web server, no database. A CLI image is enough.
FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
        git \
        libzip-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# bcmath is not optional here — every rate and amount is decimal-string arithmetic.
RUN docker-php-ext-install bcmath zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

CMD ["php", "-a"]
