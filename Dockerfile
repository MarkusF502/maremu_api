# Render não tem runtime nativo de PHP — este Dockerfile é o que ele usa pra
# buildar e rodar a API. Apache (não `artisan serve`) de propósito: algumas
# rotas (ex.: onboarding por IA em OnboardingIaService) podem levar até ~150s
# por causa dos retries contra o Gemini, e `artisan serve` é single-threaded
# — uma única requisição lenta travaria a API inteira pra todo mundo.

FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
        libpq-dev \
        libzip-dev \
        unzip \
        git \
    && docker-php-ext-install pdo pdo_pgsql pgsql zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache: document root aponta para /public e habilita mod_rewrite (rotas do Laravel)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite \
    && sed -ri -e "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && sed -ri -e "s!/var/www/!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# A substituição da porta (Listen 80 -> Listen $PORT) fica só no
# entrypoint.sh, em runtime. Fazer isso aqui no build quebrava o deploy: em
# build time $PORT não existe, então o sed rodava com valor vazio, apagava o
# "80" de "Listen 80" e deixava "Listen " sem número. Depois, o sed do
# entrypoint.sh (que procura dígitos) não achava mais nada pra substituir —
# Apache subia sem porta válida e o serviço nunca passava no health check.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copia só os manifests primeiro pra aproveitar cache de layer do Docker
# quando o código muda mas as dependências não.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/testing storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
