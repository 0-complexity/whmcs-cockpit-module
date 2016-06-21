#!/usr/bin/env bash
set -euf
container_name=$1
cd /tmp
rm -rf whmcs html
tar -xvf cockpit.tgz
mv whmcs html
mv html/templates/itsyouonline html/templates/fusion
docker cp html ${container_name}:/var/www/
rm -rf html cockpit.tgz docker-deploy.sh
