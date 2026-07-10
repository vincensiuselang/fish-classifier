# ============================================================
# FISH-CLF - image PHP + Apache buat deploy (Railway / Render / dll)
# ============================================================
FROM php:8.2-apache

# Ekstensi yang dibutuhin: pdo_mysql (koneksi DB) + gd (proses gambar)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Setting PHP (inference agak berat)
RUN { \
      echo 'max_execution_time=120'; \
      echo 'memory_limit=512M'; \
      echo 'upload_max_filesize=12M'; \
      echo 'post_max_size=14M'; \
    } > /usr/local/etc/php/conf.d/fishclf.ini

# Docroot Apache -> folder public/ + izinkan .htaccess
RUN a2enmod rewrite \
 && printf '%s\n' \
      '<VirtualHost *:80>' \
      '  DocumentRoot /var/www/html/public' \
      '  DirectoryIndex index.php index.html' \
      '  <Directory /var/www/html/public>' \
      '    AllowOverride All' \
      '    Require all granted' \
      '  </Directory>' \
      '</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . .

RUN chmod +x docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["./docker-entrypoint.sh"]
