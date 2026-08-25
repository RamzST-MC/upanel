#!/bin/bash
# upanel-helper — вспомогательный root-скрипт панели.
# Устанавливается в /usr/local/bin/upanel-helper, вызывается через sudo из PHP.
set -e

cmd="$1"; shift || true

case "$cmd" in
  mkdir_site)
    dir="$1"
    mkdir -p "$dir"
    chown -R www-data:www-data "$dir"
    chmod 755 "$dir"
    [ -f "$dir/index.php" ] || echo "<?php echo 'Site is up. Edit index.php'; " > "$dir/index.php"
    ;;
  add_vhost)
    domain="$1"; conf="$2"
    cp "$conf" "/etc/nginx/sites-available/${domain}.conf"
    ln -sf "/etc/nginx/sites-available/${domain}.conf" "/etc/nginx/sites-enabled/${domain}.conf"
    nginx -t && systemctl reload nginx
    ;;
  remove_vhost)
    domain="$1"
    rm -f "/etc/nginx/sites-enabled/${domain}.conf" "/etc/nginx/sites-available/${domain}.conf"
    nginx -t && systemctl reload nginx || true
    ;;
  shell)
    bash -c "$*"
    ;;
  *)
    echo "Unknown command: $cmd" >&2
    exit 1
    ;;
esac
