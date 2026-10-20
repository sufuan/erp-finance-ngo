# TPDA e-Sheba app image (plan Step 0 — production hardening for PDF).
FROM php:8.4-cli

# System deps: Laravel extensions + Chromium runtime libraries.
# Browsershot/Chromium crashes in containers without these libs and flags:
# the app always launches Chromium with --no-sandbox --disable-dev-shm-usage.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev libicu-dev \
        libgbm1 libasound2t64 libnss3 libxss1 libatk-bridge2.0-0 libgtk-3-0 fonts-noto-core \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring xml zip gd intl bcmath opcache pcntl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node + puppeteer Chromium for spatie/laravel-pdf (Browsershot).
COPY --from=node:24-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

WORKDIR /var/www/html
COPY . /var/www/html
RUN composer install --no-interaction --prefer-dist \
 && npm ci && npx puppeteer browsers install chrome && npm run build

ENV BROWSERSHOT_CHROME_PATH=/root/.cache/puppeteer/chrome
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
