#!/bin/bash
#
# UPanel installer for Ubuntu 22.04 / 24.04
# Запуск: sudo bash install.sh [опции]
#
#   --port <N>          порт панели (по умолчанию 9999)
#   --admin-user <name>  логин администратора панели (по умолчанию admin)
#   --admin-pass <pass>  пароль администратора (если не задан — сгенерируется)
#   --with-mail          установить Postfix + Dovecot + SpamAssassin + ClamAV
#   --with-dns            установить bind9
#   --php-version <ver>   версия PHP по умолчанию (по умолчанию системная из apt)
#
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PANEL_DIR="/var/www/upanel"
PANEL_PORT=9999
ADMIN_USER="admin"
ADMIN_PASS=""
WITH_MAIL=0
WITH_DNS=0
PHP_VER=""

color() { printf "\033[1;36m%s\033[0m\n" "$1"; }
err()   { printf "\033[1;31m%s\033[0m\n" "$1" >&2; }

while [[ $# -gt 0 ]]; do
  case "$1" in
    --port) PANEL_PORT="$2"; shift 2 ;;
    --admin-user) ADMIN_USER="$2"; shift 2 ;;
    --admin-pass) ADMIN_PASS="$2"; shift 2 ;;
    --with-mail) WITH_MAIL=1; shift ;;
    --with-dns) WITH_DNS=1; shift ;;
    --php-version) PHP_VER="$2"; shift 2 ;;
    *) err "Неизвестный аргумент: $1"; exit 1 ;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  err "Запускайте скрипт с sudo/от root: sudo bash install.sh"
  exit 1
fi

if [[ -z "$ADMIN_PASS" ]]; then
  ADMIN_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 16)"
fi

color "==> Обновление списков пакетов"
apt-get update -y

color "==> Установка базовых пакетов"
apt-get install -y nginx sqlite3 mariadb-server ufw curl unzip git \
  software-properties-common ca-certificates lsb-release fail2ban cron

# --- PHP ---
color "==> Установка PHP"
apt-get install -y php-fpm php-sqlite3 php-mysql php-curl php-gd php-mbstring \
  php-xml php-cli php-zip

DETECTED_PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
PHP_VER="${PHP_VER:-$DETECTED_PHP_VER}"
PHP_FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
systemctl enable --now "php${PHP_VER}-fpm"

# --- MariaDB root access для панели ---
color "==> Настройка root-доступа MySQL/MariaDB"
systemctl enable --now mariadb
if [[ ! -f /root/.my.cnf ]]; then
  MYSQL_ROOT_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 20)"
  mysql -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '${MYSQL_ROOT_PASS}';" 2>/dev/null || true
  cat > /root/.my.cnf <<EOF
[client]
user=root
password=${MYSQL_ROOT_PASS}
EOF
  chmod 600 /root/.my.cnf
fi

# --- Certbot ---
color "==> Установка Certbot (Let's Encrypt)"
apt-get install -y certbot python3-certbot-nginx

# --- Почта (опционально) ---
if [[ "$WITH_MAIL" -eq 1 ]]; then
  color "==> Установка почтового сервера (Postfix/Dovecot/SpamAssassin/ClamAV)"
  DEBIAN_FRONTEND=noninteractive apt-get install -y postfix dovecot-core dovecot-imapd \
    spamassassin clamav clamav-daemon
  systemctl enable --now postfix dovecot spamassassin clamav-daemon || true
fi

# --- DNS (опционально) ---
if [[ "$WITH_DNS" -eq 1 ]]; then
  color "==> Установка bind9"
  apt-get install -y bind9 bind9utils
  mkdir -p /etc/bind/zones
  systemctl enable --now bind9 || systemctl enable --now named || true
fi

# --- Копирование файлов панели ---
color "==> Установка файлов панели в ${PANEL_DIR}"
mkdir -p "$PANEL_DIR"
cp -r "${SCRIPT_DIR}/app/"* "$PANEL_DIR/"
mkdir -p "${PANEL_DIR}/data" "/var/backups/panel"
chown -R www-data:www-data "$PANEL_DIR" "/var/backups/panel"
chmod -R 750 "${PANEL_DIR}/data"

# --- upanel-helper ---
color "==> Установка root-хелпера"
install -m 755 "${SCRIPT_DIR}/upanel-helper.sh" /usr/local/bin/upanel-helper

# --- sudoers: точечные права для www-data ---
color "==> Настройка sudo-прав для www-data"
cat > /etc/sudoers.d/upanel <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/local/bin/upanel-helper
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl start *, /usr/bin/systemctl stop *, /usr/bin/systemctl restart *
www-data ALL=(root) NOPASSWD: /usr/bin/mysql --defaults-file=/root/.my.cnf*
www-data ALL=(root) NOPASSWD: /usr/bin/tar *
www-data ALL=(root) NOPASSWD: /usr/sbin/ufw *
www-data ALL=(root) NOPASSWD: /usr/bin/certbot *
www-data ALL=(root) NOPASSWD: /usr/sbin/useradd *, /usr/sbin/userdel *, /usr/bin/chpasswd
www-data ALL=(root) NOPASSWD: /usr/bin/crontab *
www-data ALL=(root) NOPASSWD: /usr/bin/kill *
www-data ALL=(root) NOPASSWD: /bin/mkdir *, /bin/rm *, /bin/cp *
www-data ALL=(root) NOPASSWD: /usr/bin/apt-get *, /usr/bin/add-apt-repository *
EOF
chmod 440 /etc/sudoers.d/upanel
visudo -c -f /etc/sudoers.d/upanel

# --- nginx vhost для самой панели ---
color "==> Настройка nginx для панели (порт ${PANEL_PORT})"
cat > /etc/nginx/sites-available/upanel.conf <<EOF
server {
    listen ${PANEL_PORT};
    server_name _;
    root ${PANEL_DIR}/public;
    index index.php;

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCK};
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF
ln -sf /etc/nginx/sites-available/upanel.conf /etc/nginx/sites-enabled/upanel.conf
nginx -t
systemctl enable --now nginx
systemctl reload nginx

# --- Firewall ---
color "==> Настройка UFW"
ufw allow OpenSSH || true
ufw allow "${PANEL_PORT}/tcp" || true
ufw allow 80/tcp || true
ufw allow 443/tcp || true
ufw --force enable || true

# --- Инициализация БД панели и админ-пользователя ---
color "==> Создание администратора панели"
sudo -u www-data php "${PANEL_DIR}/bin/create_admin.php" "${ADMIN_USER}" "${ADMIN_PASS}"

IP="$(hostname -I | awk '{print $1}')"

echo ""
color "======================================================"
color " Установка завершена!"
color "======================================================"
echo " Панель:   http://${IP}:${PANEL_PORT}/"
echo " Логин:    ${ADMIN_USER}"
echo " Пароль:   ${ADMIN_PASS}"
echo ""
echo " ВАЖНО:"
echo " - Смените пароль после первого входа (раздел «Безопасность»)."
echo " - Панель даёт по сути root-доступ к серверу — держите порт ${PANEL_PORT}"
echo "   закрытым для публичного интернета (VPN / IP allowlist через UFW),"
echo "   либо поставьте перед ней обратный прокси с HTTPS и Basic Auth."
echo " - Для боевого использования настройте HTTPS для самой панели"
echo "   (например, certbot + отдельный домен на панель)."
color "======================================================"
