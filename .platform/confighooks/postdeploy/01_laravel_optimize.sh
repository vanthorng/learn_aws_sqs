#!/usr/bin/env bash
set -euo pipefail

cd /var/app/current

# Rebuild cached configuration when Elastic Beanstalk environment properties
# change without deploying a new application version.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
