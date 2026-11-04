#!/usr/bin/env bash
set -euo pipefail

cd /var/app/current

# The Elastic Beanstalk environment supplies the production environment values.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
