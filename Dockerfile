FROM php:8.2-apache
# Librerías del sistema necesarias para compilar la extensión GD (foto.php redimensiona fotos de perfil JPG/PNG/WEBP/GIF).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg-dev libwebp-dev \
 && rm -rf /var/lib/apt/lists/*
# Extensiones de PHP:
#   mysqli -> conexión a MariaDB (config/db.php usa new mysqli)
#   gd     -> procesamiento de imágenes (foto.php)
#   exif   -> orientación de fotos JPG (foto.php, exif_read_data)
# mbstring y Argon2 (password_hash) ya vienen incluidos en la imagen oficial.
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" mysqli gd exif
# Configuración de Apache:
#  - AllowOverride All: sin esto Apache IGNORA los .htaccess y las carpetas: config/, includes/ y uploads/ (Require all denied) quedarían accesibles.
RUN printf '%s\n' \
      'ServerName localhost' \
      'ServerTokens Prod' \
      'ServerSignature Off' \
      '<Directory /var/www/html>' \
      '    Options -Indexes +FollowSymLinks' \
      '    AllowOverride All' \
      '    Require all granted' \
      '</Directory>' > /etc/apache2/conf-available/escolar.conf \
 && a2enconf escolar
# Configuración de PHP: zona horaria, fotos de hasta 8 MB (el valor por
# defecto es 2 MB) y errores al log del contenedor en vez de a pantalla.
RUN printf '%s\n' \
      'date.timezone = America/Mexico_City' \
      'upload_max_filesize = 8M' \
      'post_max_size = 10M' \
      'display_errors = Off' \
      'log_errors = On' \
      'expose_php = Off' > /usr/local/etc/php/conf.d/zz-escolar.ini
# Aplicación
WORKDIR /var/www/html
COPY ./Sistema_Escolar/ /var/www/html/
# Carpeta de fotos de perfil: debe poder escribirla Apache (usuario www-data).
RUN mkdir -p /var/www/html/uploads/perfiles \
 && chown -R www-data:www-data /var/www/html/uploads
EXPOSE 80
# Considera sano el contenedor si la página de login responde correctamente.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD php -r "exit(@file_get_contents(\"http://localhost/login.php\") === false ? 1 : 0);"