FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql

RUN a2enmod rewrite

RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf

RUN sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/

RUN chmod -R 755 /var/www/html/

RUN printf 'DirectoryIndex index.php index.html\n' > /etc/apache2/conf-available/pinkride.conf

RUN a2enconf pinkride

EXPOSE 10000
