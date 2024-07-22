<?php
require_once __DIR__ . '/vendor/autoload.php';

use ClarionApp\Installer\EnvEditor;

$BACKEND_DIR = "/var/www/backend-framework";
$FRONTEND_DIR = "/var/www/frontend-framework";
$MAC = get_mac();
$IP = get_ip();
$NETWORK_INTERFACE = get_network_interface();
$DB_NAME = "clarion";
$DB_USER = "clarion";
$DB_PASS = generate_password(12);
$DB_HOST = "127.0.0.1";
$DB_PORT = "3306";
$MULTICHAIN_VERSION = "2.3.3";

/* Don't edit below this line */

$APT_PACKAGES = "screen git php-mbstring php-xml php-curl unzip screen openssl jq mariadb-server php php-mysql wget tar curl ssh ";
$APT_PACKAGES.= "supervisor autoconf automake build-essential libgssdp-1.6-dev libcurl4-openssl-dev libpugixml-dev ";
$APT_PACKAGES.= "libsystemd-dev vim screen php-cli npm";
$HOSTNAME = "clarion-".implode("", array_slice(explode(":", $MAC), 3, 3));

print "Changing hostname to $HOSTNAME\n";
change_hostname($HOSTNAME);

print "Installing apt packages: $APT_PACKAGES\n";
install_apt_packages($APT_PACKAGES);

print "Setting up mysql:\n";
print "DB_NAME=$DB_NAME\n";
print "DB_USER=$DB_USER\n";
print "DB_PASS=$DB_PASS\n";
print "DB_HOST=$DB_HOST\n";
setup_mysql($DB_NAME, $DB_USER, $DB_PASS, $DB_HOST);

print "Cloning backend repo\n";
git_clone("https://github.com/clarion-app/backend.git", "/var/www/backend");

print "Creating Laravel project in $BACKEND_DIR\n";
create_laravel_project($BACKEND_DIR);

print "Configuring Laravel\n";
configure_laravel_project($BACKEND_DIR, $DB_HOST, $DB_PORT, $DB_NAME, $DB_USER, $DB_PASS, "http://$IP:8000", "http://$IP");

print "Configuring Apache for backend\n";
configure_apache_backend($BACKEND_DIR);

$env = new EnvEditor("$BACKEND_DIR/.env");
print "Configuring Vite for frontend\n";
git_clone("https://github.com/clarion-app/frontend.git", $FRONTEND_DIR);
$pwd = getcwd();
chdir($FRONTEND_DIR);
shell_exec("npm install --legacy-peer-deps");
shell_exec("npm run set-backend-url http://$IP:8000");
shell_exec("npm run set-port 80");
shell_exec("npm run set-reverb-config $IP 8080 http ".$env->get("REVERB_APP_KEY"));

print "Configuring Apache to proxy to frontend\n";
configure_apache_frontend();

print "Cloning ssdp-advertiser\n";
git_clone("https://github.com/metaverse-systems/ssdp-advertiser.git", "/home/clarion/ssdp-advertiser");
$pwd = getcwd();
chdir("/home/clarion/ssdp-advertiser");
shell_exec("./autogen.sh; ./configure; make; make install");
$ssdp_conf = new stdClass();
$ssdp_conf->networkInterface = $NETWORK_INTERFACE;
$ssdp_conf->descriptionUrl = "http://$IP:8000/Description.xml";
file_put_contents("/etc/ssdp-advertiser.json", json_encode($ssdp_conf, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
shell_exec("systemctl enable ssdp-advertiser");
shell_exec("systemctl start ssdp-advertiser");

print "Setting www-data shell to /bin/bash\n";
shell_exec("chsh -s /bin/bash www-data");

print "Allowing node to bind to port 80\n";
shell_exec("setcap 'cap_net_bind_service=+ep' $(readlink -f $(which node))");

print "Setting up Supervisord\n";
$config = "[program:clarion-frontend]
process_name=%(program_name)s_%(process_num)02d
directory = $FRONTEND_DIR
command = $FRONTEND_DIR/node_modules/.bin/npm run dev
autostart=true
autorestart=true
user = www-data
numprocs= 1
redirect_stderr=true
stdout_logfile=/var/www/clarion-frontend.log";
file_put_contents("/etc/supervisor/conf.d/clarion-frontend.conf", $config);
shell_exec("supervisorctl reread; supervisorctl update;");

shell_exec("chown -R www-data:www-data /var/www");

print "Setup www-data's crontab to run php artisan schedule:run every minute\n";
shell_exec("crontab -l > /tmp/crontab");
shell_exec("echo '* * * * * cd /var/www/backend-framework && php artisan schedule:run >> /dev/null 2>&1' >> /tmp/crontab");
shell_exec("crontab /tmp/crontab");

print "Setting up supervisor to run php artisan queue:work --queue=default\n";
$config = "[program:clarion-queue]
process_name=%(program_name)s_%(process_num)02d
directory = $BACKEND_DIR
command = php artisan queue:work --queue=default
autostart=true
autorestart=true
user = www-data
numprocs= 1
redirect_stderr=true
stdout_logfile=/var/www/clarion-queue.log";
file_put_contents("/etc/supervisor/conf.d/clarion-queue.conf", $config);

print "Setting up supervisor to run php aristan reverb:start\n";
$config = "[program:clarion-reverb]
process_name=%(program_name)s_%(process_num)02d
directory = $BACKEND_DIR
command = php artisan reverb:start --debug
autostart=true
autorestart=true
user = www-data
numprocs= 1
redirect_stderr=true
stdout_logfile=/var/www/clarion-reverb.log";
file_put_contents("/etc/supervisor/conf.d/clarion-reverb.conf", $config);

$supervisordConf = file_get_contents("/etc/supervisor/supervisord.conf");
$supervisordConf.= "\nminfds=10000\n";
file_put_contents("/etc/supervisor/supervisord.conf", $supervisordConf);


shell_exec("supervisorctl reread; supervisorctl update;");
shell_exec("supervisorctl start clarion-queue");

function get_network_interface()
{
    $lines = explode("\n", shell_exec('ip -o link show'));
    $func = function($line)
    {
        $parts = explode(' ', $line);
        if(!isset($parts[1])) return null;
        $iface = str_replace(':', '', $parts[1]);
        if($iface == 'lo') return null;
        return $iface;
    };

    $ifaces = array_values(array_filter(array_map($func, $lines)));
    return $ifaces[0];
}

function get_ip()
{
    $lines = explode("\n", shell_exec('ip -o addr show'));
    $func = function($line)
    {
        $parts = explode(' ', $line);
        if(!isset($parts[1])) return null;
        $iface = str_replace(':', '', $parts[1]);
        if($iface == 'lo') return null;
	    if($parts[5] != "inet") return null;
        if(!isset($parts[6])) return null;
	    $ip_parts = explode('/', $parts[6]);
        return $ip_parts[0];
    };

    $ips = array_values(array_filter(array_map($func, $lines)));
    return $ips[0];
}

function get_mac()
{
    $lines = explode("\n", shell_exec('ip -o link show'));
    $func = function($line)
    {
        $parts = explode(' ', $line);
        if(!isset($parts[1])) return null;
        $iface = str_replace(':', '', $parts[1]);
        if($iface == 'lo') return null;
        if(!isset($parts[19])) return null;
        return $parts[19];
    };

    $macs = array_values(array_filter(array_map($func, $lines)));
    return $macs[0];
}

function generate_password($size)
{
    $chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $password = "";
    for($i = 0; $i < $size; $i++) {
        $password .= $chars[rand(0, strlen($chars) - 1)];
    }
    return $password;
}

function install_apt_packages($packages)
{
    shell_exec("apt-get update");
    shell_exec("apt-get install -y $packages");
}

function change_hostname($hostname)
{
    shell_exec("hostnamectl set-hostname $hostname");

    $hosts = file_get_contents("/etc/hosts");
    $hosts = preg_replace("/debian/", $hostname, $hosts);
    file_put_contents("/etc/hosts", $hosts);
}

function setup_mysql($db_name, $db_user, $db_pass, $db_host)
{
    shell_exec("mysql -e \"CREATE DATABASE $db_name\"");
    shell_exec("mysql -e \"CREATE USER '$db_user'@'$db_host' IDENTIFIED BY '$db_pass'\"");
    shell_exec("mysql -e \"GRANT ALL PRIVILEGES ON $db_name.* TO '$db_user'@'$db_host'\"");
    shell_exec("mysql -e \"FLUSH PRIVILEGES\"");
}

function create_laravel_project($dir)
{
    shell_exec("composer create-project -n --prefer-dist laravel/laravel $dir");
    print "Editing $dir/composer.json\n";
    $composerJson = json_decode(file_get_contents("$dir/composer.json"), true);
    $composerJson['minimum-stability'] = 'dev';
    $composerJson['repositories'] = [
        [
            'type' => 'path',
            'url' => '../backend'
        ]
    ];
    file_put_contents("$dir/composer.json", json_encode($composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    shell_exec("composer require clarion-app/backend:dev-main -q --working-dir=$dir");
    $pwd = getcwd();
    chdir($dir);

    shell_exec("php artisan clarion:setup-node-id");

    print "Installing passport\n";
    shell_exec("composer require laravel/passport -q --working-dir=$dir");
    print shell_exec("php artisan passport:install --uuids --no-interaction");
    chdir($pwd);
    shell_exec("chown -R www-data:www-data $dir");
}

function configure_laravel_project($backend_dir, $db_host, $db_port, $db_name, $db_user, $db_pass, $app_url, $frontend_url)
{
    $env = new EnvEditor("$backend_dir/.env");
    $env->set("DB_CONNECTION", "mysql");
    $env->set("DB_HOST", $db_host);
    $env->set("DB_PORT", $db_port);
    $env->set("DB_DATABASE", $db_name);
    $env->set("DB_USERNAME", $db_user);
    $env->set("DB_PASSWORD", $db_pass);
    $env->set("APP_URL", $app_url);
    $env->set("FRONTEND_URL", $frontend_url);
    $env->set("APP_KEY", "");
    $env->set("BROADCAST_CONNECTION", "reverb");
    $env->set("REVERB_APP_ID", generate_password(8));
    $env->set("REVERB_APP_KEY", generate_password(32));
    $env->set("REVERB_APP_SECRET", generate_password(32));
    $env->set("REVERB_HOST", "localhost");
    $env->set("REVERB_PORT", "8080");
    $env->set("REVERB_SCHEME", "http");
    $env->save();

    shell_exec("php $backend_dir/artisan key:generate");
    shell_exec("php $backend_dir/artisan migrate");
    shell_exec("php $backend_dir/artisan vendor:publish --tag=clarion-config");
    shell_exec("php $backend_dir/artisan vendor:publish --tag=emc-config");

    // edit config/eloquent-multichain-bridge.php and set 'disabled' to true
    $config = file_get_contents("$backend_dir/config/eloquent-multichain-bridge.php");
    $config = str_replace("'disabled' => false", "'disabled' => true", $config);
    file_put_contents("$backend_dir/config/eloquent-multichain-bridge.php", $config);
    shell_exec("chown -R www-data:www-data /var/www");

    shell_exec("php $backend_dir/artisan install:broadcasting -n");
    shell_exec("php $backend_dir/artisan vendor:publish --tag=reverb");
}

function install_multichain($version)
{
    $url = "https://www.multichain.com/download/multichain-$version.tar.gz";
    shell_exec("wget $url");
    shell_exec("tar -xvzf multichain-$version.tar.gz");
    
    if(!file_exists("/home/clarion/bin"))
    {
        mkdir("/home/clarion/bin", 0755, true);
    }

    // Copy multichaind multichain-cli multichain-util to /home/clarion/bin
    shell_exec("cp multichain-$version/multichaind multichain-$version/multichain-cli multichain-$version/multichain-util /home/clarion/bin");
}

function git_clone($repo, $dir)
{
    shell_exec("git clone $repo $dir");
}

function configure_apache_backend()
{
    $apacheConfig = <<<EOF
<VirtualHost *:8000>
    ServerName clarion-backend
    DocumentRoot /var/www/backend-framework/public

    <Directory /var/www/backend-framework/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF;

    file_put_contents("/etc/apache2/sites-available/clarion-backend.conf", $apacheConfig);
    shell_exec("a2ensite clarion-backend");
    shell_exec("a2enmod rewrite");

    // Add port 8000 to ports.conf
    $portsConf = file_get_contents("/etc/apache2/ports.conf");
    $portsConf.= "\nListen 8000\n";
    file_put_contents("/etc/apache2/ports.conf", $portsConf);

    shell_exec("systemctl restart apache2");
}

function configure_apache_frontend()
{
    shell_exec("a2dissite 000-default");
    
    // Remove port 80 from ports.conf
    $portsConf = file_get_contents("/etc/apache2/ports.conf");
    $portsConf = str_replace("Listen 80\n", "", $portsConf);
    file_put_contents("/etc/apache2/ports.conf", $portsConf);
    
    shell_exec("systemctl restart apache2");
}
