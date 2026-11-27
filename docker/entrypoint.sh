#!/bin/sh
set -e

cd /app

# Développement : dépendances installées au premier démarrage (volume « vendor »)
if [ "${APP_ENV:-dev}" != "prod" ] && [ ! -f vendor/autoload_runtime.php ]; then
    echo "Calipso : installation des dépendances Composer…"
    composer install --no-interaction --no-progress --prefer-dist
fi

# Apache (mod_php) ne transmet pas les variables d'environnement du conteneur à PHP :
# on déclare celles de l'application (docker run -e …, environment: dans compose.yaml).
: > /etc/apache2/conf-enabled/zz-calipso-env.conf
for name in $(env | sed -n 's/^\(APP_[A-Z0-9_]*\|EASYVISTA_[A-Z0-9_]*\|CALIPSO_[A-Z0-9_]*\|DEFAULT_URI\)=.*/\1/p'); do
    echo "PassEnv $name" >> /etc/apache2/conf-enabled/zz-calipso-env.conf
done

# Dossiers d'exécution accessibles à Apache
mkdir -p var/cache var/log var/mock
chown -R www-data:www-data var

exec docker-php-entrypoint "$@"
