#!/bin/bash

cd /home/clarion

# wait for network
ip a > ip.log
sleep 10
ip a > ip2.log

# Install Composer
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php --install-dir=/usr/local/bin --filename=composer --force
rm composer-setup.php

git clone https://github.com/clarion-app/installer.git
cd /home/clarion/installer
composer -n install
cd /home/clarion
chown -R clarion:clarion installer

chmod a-x /home/clarion/prereqs.sh

php installer/install.php 2>&1 >installer.log
