# nginx for qflows

`qflows.conf` is the app's nginx site: HTTP redirected to HTTPS, the static files cached and compressed, every other address to `public_html/index.php` through the app's PHP-FPM pool (`<app>/run/php-fpm.sock`).
`install.sh` writes it on AlmaLinux / RHEL 9; on other systems, or to change it later, follow the steps below.

Two placeholders are filled in:

| Placeholder | Value | Example |
|-------------|-------|---------|
| `Q_HOME` | the app's folder (its user's home) | `/home/app` |
| `Q_SERVER_NAME` | the host of `BASE_URL` in `app.env` | `flows.example.com` |

The site's file is named after the app's user (`app.conf`), so several installations can live on one nginx. The app's `etc/nginx.conf` is a link to it.
Run the commands as root, with your values:

```sh
APP_USER=app
APP_HOME=/home/app
DOMAIN=flows.example.com
```

## 1. nginx

| Family | Install | Site folder | nginx's user |
|--------|---------|-------------|--------------|
| RHEL, AlmaLinux, Rocky, Oracle Linux, CentOS Stream, Fedora | `dnf install nginx` | `/etc/nginx/conf.d/` | `nginx` |
| Debian, Ubuntu | `apt-get install nginx` | `/etc/nginx/conf.d/` | `www-data` |
| openSUSE, SLES | `zypper install nginx` | `/etc/nginx/conf.d/` | `nginx` |
| Arch | `pacman -S nginx` | `/etc/nginx/conf.d/` (to be included, below) | `http` |
| Alpine | `apk add nginx` | `/etc/nginx/http.d/` | `nginx` |

- RHEL 9 ships nginx 1.20; newer ones come as module streams: `dnf module enable nginx:1.26 && dnf install nginx`.
- Arch's `/etc/nginx/nginx.conf` loads no folder: add `include /etc/nginx/conf.d/*.conf;` inside its `http { }` block, and `mkdir -p /etc/nginx/conf.d`.
- Debian / Ubuntu also load `sites-enabled/`; its `default` site only answers other host names and can stay, or go: `rm /etc/nginx/sites-enabled/default`.
- Leave `/etc/nginx/nginx.conf` as installed otherwise.

```sh
NGINX_USER=nginx                    # www-data on Debian / Ubuntu, http on Arch
SITE=/etc/nginx/conf.d/$APP_USER.conf   # /etc/nginx/http.d/$APP_USER.conf on Alpine
```

## 2. Access to the app's folder

The home is not world-readable: nginx reads the web root through the app's group, and connects to the pool's socket.

```sh
usermod -a -G "$APP_USER" "$NGINX_USER"
```

In the PHP-FPM pool (`scripts/system/fpm-pool.conf`, written to the system's pool folder), `listen.owner` and `listen.group` are nginx's user: `nginx` as shipped; set `www-data` on Debian / Ubuntu, `http` on Arch.
The pool folder: `/etc/php-fpm.d/` (RHEL family, Remi), `/etc/php/<version>/fpm/pool.d/` (Debian / Ubuntu), `/etc/php<version>/fpm/php-fpm.d/` (openSUSE), `/etc/php/php-fpm.d/` (Arch), `/etc/php<version>/php-fpm.d/` (Alpine).

**SELinux** (RHEL family, Fedora; enforcing by default): nginx and PHP-FPM may not serve a home folder. `install.sh` sets SELinux to permissive (`setenforce 0`, `SELINUX=permissive` in `/etc/selinux/config`). Check with `getenforce`.
AppArmor (Debian, Ubuntu, openSUSE) has no nginx profile as installed: nothing to do.

## 3. The certificate

nginx does not start without one. It goes in `<app>/ssl/ssl.crt` and `ssl.key` (links are fine). A self-signed one first:

```sh
openssl req -x509 -newkey rsa:2048 -nodes -days 825 -subj "/CN=$DOMAIN" -addext "subjectAltName=DNS:$DOMAIN" \
    -keyout "$APP_HOME/ssl/selfsigned.key" -out "$APP_HOME/ssl/selfsigned.crt"
chmod 600 "$APP_HOME/ssl/selfsigned.key"
ln -sfn selfsigned.crt "$APP_HOME/ssl/ssl.crt"
ln -sfn selfsigned.key "$APP_HOME/ssl/ssl.key"
chown -h "$APP_USER:$APP_USER" "$APP_HOME"/ssl/*
```

Let's Encrypt replaces it once the site runs (step 5).

## 4. The site

```sh
sed -e "s#Q_HOME#$APP_HOME#g" -e "s#Q_SERVER_NAME#$DOMAIN#g" "$APP_HOME/scripts/nginx/qflows.conf" > "$SITE"
install -d -o "$APP_USER" -g "$APP_USER" "$APP_HOME/etc"
ln -sfn "$SITE" "$APP_HOME/etc/nginx.conf"
nginx -t
```

nginx 1.25.1 and later warn that `listen ... http2` is deprecated: it still works; to silence it, write `listen 443 ssl;` (both lines) and add `http2 on;` below them.
Then start nginx, also at boot (restart it if it already ran: its workers get the new group):

```sh
systemctl enable nginx && systemctl restart nginx       # systemd (all but Alpine)
rc-update add nginx default && rc-service nginx restart # Alpine (OpenRC)
```

The firewall is not changed by any of the app's scripts: open 80 and 443 (`firewall-cmd --permanent --add-service=http --add-service=https && firewall-cmd --reload`, or `ufw allow 80,443/tcp`).

## 5. Let's Encrypt (optional)

Once `DOMAIN` points at the server and port 80 is open. The challenge is served from the web root (port 80 redirects to HTTPS, which serves `/.well-known/acme-challenge/`):

```sh
certbot certonly --webroot -w "$APP_HOME/public_html" -d "$DOMAIN" -m you@example.com --agree-tos \
    --deploy-hook "systemctl reload nginx"
ln -sfn "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "$APP_HOME/ssl/ssl.crt"
ln -sfn "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$APP_HOME/ssl/ssl.key"
systemctl reload nginx
```

certbot: `dnf install certbot` (EPEL), `apt-get install certbot`, `zypper install python3-certbot`, `pacman -S certbot`, `apk add certbot`. It renews by its timer or cron job (`systemctl enable --now certbot-renew.timer` on the RHEL family; Debian's `certbot.timer` is enabled by the package).

## Later

- After a change of `qflows.conf` (an update of the app) or of `BASE_URL`: compare, then write it again as in step 4.
  ```sh
  diff -u "$SITE" <(sed -e "s#Q_HOME#$APP_HOME#g" -e "s#Q_SERVER_NAME#$DOMAIN#g" "$APP_HOME/scripts/nginx/qflows.conf")
  cp -p "$SITE" "$SITE.bak-$(date +%Y%m%dT%H%M%S)"   # outside *.conf: not loaded
  ```
  Apply with `nginx -t && systemctl reload nginx`; if the check fails, copy the `.bak-` file back.
- Status: `systemctl status nginx`, `nginx -t`, the certificate's end date: `openssl x509 -noout -enddate -in "$APP_HOME/ssl/ssl.crt"`.
- nginx is the server's: `stop` and `restart` apply to every site on it, other installations' too; prefer `reload`.
- Remove the site: `mv "$SITE" "$SITE.bak-$(date +%Y%m%dT%H%M%S)" && rm -f "$APP_HOME/etc/nginx.conf" && nginx -t && systemctl reload nginx`. nginx stays installed.
- Logs: `<app>/logs/app_access.log` and `app_error.log` (rotated by `scripts/system/logrotate.conf`).
- Behind another proxy: list its addresses in `app.trusted_proxies` (`app/config.php`).
