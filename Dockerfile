FROM php:8.4-fpm

# Install system dependencies & libzip-dev
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions wajib Laravel, Redis & Zip
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip

# Install Redis extension via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Set safe directory untuk Git agar tidak error dubious ownership
RUN git config --global --add safe.directory /var/www

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www
