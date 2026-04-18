FROM php:8.2-cli

# 1. Instalar dependencias del sistema necesarias para Composer y Postgres
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    && docker-php-ext-install pdo pdo_pgsql

# 2. INSTALAR COMPOSER (Esta es la línea que falta)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 3. Configurar directorio de trabajo
WORKDIR /var/www

# 4. Copiar los archivos del proyecto
COPY . .

# 5. Permisos para Laravel
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

EXPOSE 8000

# El comando se define mejor en el docker-compose.yml, 
# pero dejamos este por defecto
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]