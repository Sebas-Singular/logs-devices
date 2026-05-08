FROM php:8.3-apache
 
# -----------------------------------------------------------------------------
# Composer
# -----------------------------------------------------------------------------
# Copiamos el binario de Composer desde su imagen oficial en lugar de instalarlo
# manualmente. Esto garantiza que usamos siempre la versión estable más reciente
# de Composer 2, sin tener que mantener un script de instalación.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
 
# -----------------------------------------------------------------------------
# Dependencias del sistema y extensiones PHP
# -----------------------------------------------------------------------------
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        libxml2-dev \
        zip \
        unzip \
        git \
    && docker-php-ext-install \
        pdo_mysql \
        mysqli \
        intl \
        zip \
        mbstring \
        dom \
        xml \
        xmlwriter \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
 
COPY .docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
 
WORKDIR /usr/home/singularthings.io/web/logs-devices
 
# -----------------------------------------------------------------------------
# NOTA: no ejecutamos "composer install" aquí.
#
# El proyecto se monta como bind mount (volume) en docker-compose.yml, lo que
# significa que el contenido del contenedor queda sobreescrito por el directorio
# local del host. Si corriéramos "composer install" en el build, el vendor/
# generado quedaría oculto bajo el mount.
#
# En su lugar, después de "docker compose up -d", ejecutar una vez:
#
#   docker compose exec app composer install
#
# Esto crea vendor/ en el directorio local del proyecto, que es montado
# automáticamente en el contenedor. Los próximos arranques del contenedor
# ya encuentran vendor/ listo.
#
# Para correr los tests:
#   docker compose exec app ./vendor/bin/phpunit
# -----------------------------------------------------------------------------