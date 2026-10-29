# Argus — IP camera wall

A self-hosted CCTV viewing wall for ACTi and other IP cameras. Plain PHP 8 and
MySQL, no framework, no build step. Designed to sit on a wall monitor or a
browser tab: dark, quiet, and readable from a distance.

![stack](https://img.shields.io/badge/PHP-8.1%2B-777bb4) ![stack](https://img.shields.io/badge/MySQL-5.7%2B%20%2F%20MariaDB-4479a1)

## What it does

- **Live wall with 1, 4 or 9 cameras per page.** Auto mode pages through every
  camera you can see (9 at a time, arrow keys work); custom mode gives each user
  a hand-picked layout that is saved to MySQL and restored on their next visit.
- **Many cameras, many sites.** Cameras are organised in groups; add them one by
  one or paste a whole site at once with bulk import. There is no hard limit on
  the number of cameras.
- **Three ways to get video into the browser:**
  - `MJPEG` — proxied multipart stream, lowest latency, lowest server CPU.
  - `Snapshot` — JPEG refresh loop, works with almost every camera.
  - `HLS` — RTSP converted by ffmpeg for cameras that only speak RTSP.
- **Credentials stay on the server.** Camera passwords are AES-256-GCM encrypted
  in MySQL and never reach the browser: every frame is fetched through
  `stream.php` after an access check.
- **Camera status at a glance.** Online/offline dots, latency, and a friendly
  "no signal" frame instead of a broken image. Parallel health probes.
- **PTZ pad** with configurable HTTP command templates (ACTi CGI, Axis VAPIX,
  most CGI-based cameras), speed control and press-to-move / release-to-stop.
- **Roles and per-group access** — admin, operator, viewer.
- **Audit trail** of sign-ins, camera changes and PTZ commands.
- **Snapshot download** straight from a tile.

## Requirements

| Requirement | Notes |
| --- | --- |
| PHP 8.1+ | `pdo_mysql`, `curl`, `openssl`, `mbstring` |
| MySQL 5.7+ / MariaDB 10.4+ | utf8mb4 |
| ffmpeg | optional, only for cameras in **HLS** mode |
| Web server | Apache with `mod_rewrite`, nginx, or PHP's built-in server |

## Install

1. Copy the project to your server. The web root **must** be the `public/`
   directory — `app/`, `config/` and `storage/` should not be web accessible.
2. Make `storage/` and `config/` writable by the web server user:
   ```bash
   chmod -R 0770 storage config
   ```
3. Open `https://your-host/` — the installer runs automatically.
4. Enter the MySQL host, database, user and password, plus the first
   administrator account. The database is created when the MySQL user has the
   privilege; otherwise create it first:
   ```sql
   CREATE DATABASE argus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
5. Leave **Add demo cameras** ticked to explore the wall with six generated
   feeds, then delete them from *Cameras*.

The installer writes `config/config.php` (mode 0640) with the database
credentials and a freshly generated encryption key. Back that file up: camera
passwords cannot be decrypted without its `app.key`.

### Apache

```apache
<VirtualHost *:443>
    DocumentRoot /var/www/argus/public
    <Directory /var/www/argus/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

`public/.htaccess` already rewrites requests to `index.php`.

### nginx + PHP-FPM

```nginx
server {
    root /var/www/argus/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # Long-lived MJPEG responses
        fastcgi_read_timeout 1h;
        fastcgi_buffering off;
    }
}
```

### Local development server

```bash
PHP_CLI_SERVER_WORKERS=6 php -S 0.0.0.0:8080 -t public public/router.php
```

`PHP_CLI_SERVER_WORKERS` matters: each MJPEG tile holds a request open, so a
single-worker dev server can only show one live tile.

## Camera setup

Pick a driver when adding a camera; the endpoint paths are filled in for you and
can be overridden per camera. Placeholders available in every path:
`{host} {port} {channel} {channel2} {cmd} {preset}`.

| Driver | Snapshot | MJPEG | RTSP |
| --- | --- | --- | --- |
| ACTi (legacy CGI) | `/cgi-bin/encoder?SNAPSHOT` | `/-wvhttp-01-/stream/Mjpeg?framerate=25` | `/onvif1` |
| ACTi / ONVIF profile | `/onvif-http/snapshot?Profile_{channel}` | `/cgi-bin/mjpeg?stream=1` | `/onvif-media/media.amp?profile=profile_{channel}_h264…` |
| Hikvision | `/ISAPI/Streaming/channels/{channel}01/picture` | `/Streaming/channels/{channel}01/httpPreview` | `/Streaming/Channels/{channel}01` |
| Dahua | `/cgi-bin/snapshot.cgi?channel={channel}` | `/cgi-bin/mjpg/video.cgi?channel={channel}&subtype=1` | `/cam/realmonitor?channel={channel}&subtype=0` |
| Axis | `/axis-cgi/jpg/image.cgi` | `/axis-cgi/mjpg/video.cgi` | `/axis-media/media.amp` |
| Generic | `/snapshot.jpg` | `/video.mjpg` | `/stream1` |

Firmware differs between models — if a camera returns `404` or a non-image body,
open the camera's own web UI and copy the exact snapshot path into the form. The
**Test connection** button reports the HTTP result and latency.

Any absolute URL can be used in a path field, e.g. an NVR stream or an existing
HLS source: `http://10.0.0.9:8080/live/ch1.m3u8`.

### Choosing a stream mode

- **MJPEG** — best for ACTi cameras that expose `/-wvhttp-01-/`. Live video with
  ~1s latency, one persistent connection per tile.
- **Snapshot** — simplest and most compatible. Set the refresh interval in
  *Settings* (2–60s). Good for larger walls and slower cameras.
- **HLS** — for RTSP-only cameras. Requires ffmpeg on the server. Each active
  camera runs one ffmpeg process (`-c:v copy`, no transcoding) writing a rolling
  playlist in `storage/hls/`.

### PTZ

PTZ uses a simple GET command URL. `{cmd}` is replaced with `up`, `down`, `left`,
`right`, `zoom_in`, `zoom_out`, `home` or `stop`; `{speed}` with 0.05–1.0.

Example ACTi-style template:

```
/cgi-bin/ptz?move={cmd}&speed={speed}
```

Check your camera's HTTP API documentation — many firmware builds use a
different path or require the ONVIF-SOAP `ContinuousMove` operation instead. In
that case leave PTZ off; the wall works without it.

## Roles

| Role | Wall | PTZ | Cameras / groups | Users, settings, audit |
| --- | --- | --- | --- | --- |
| Administrator | all | yes | manage | yes |
| Operator | all | yes | manage | no |
| Viewer | assigned groups | no | read only | no |

Group access is enforced on pages, on JSON endpoints and on every media request
in `stream.php`.

## Security notes

- Passwords hashed with `password_hash()` (bcrypt/argon as available).
- Camera passwords encrypted with AES-256-GCM using the key in
  `config/config.php`; decrypted only inside server-side cURL calls.
- All queries use prepared statements.
- CSRF tokens on every POST (form field or `X-CSRF-Token` header).
- Session cookies are `HttpOnly`, `SameSite=Lax`, `Secure` under HTTPS.
- Sign-in throttling: 8 failures per email/IP in 15 minutes.
- Camera TLS certificates are accepted by default (IP cameras ship self-signed);
  enable verification in *Settings* if your cameras have valid certificates.
- Keep the web root on `public/`. If you cannot, block `app/`, `config/`,
  `storage/`, `install/` and `tools/` at the server level.

## Maintenance

```cron
*/5 * * * * php /var/www/argus/scripts/cleanup.php >> /var/log/argus-cleanup.log 2>&1
```

`cleanup.php` stops ffmpeg converters nobody is watching (default 15 idle
minutes, configurable in Settings) and prunes snapshots older than a day.

## Layout

```
app/                 PHP application (controllers, repositories, services, views)
  Http/              request handlers + router wiring
  Repositories/      SQL for cameras, groups, users, layouts, activity
  Services/          stream URL builders, snapshot/MJPEG fetch, PTZ, HLS
  Support/           config, database, auth, CSRF, crypto, settings, router
install/schema.sql   MySQL schema (also applied by the installer)
public/              web root: front controller, media gateway, assets
scripts/cleanup.php  cron maintenance
storage/             cache, HLS segments, logs (writable, never web-served)
config/              config.php written by the installer
```

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Tiles show **no signal** | Wrong snapshot path or credentials. Use *Test* on the camera. |
| Camera test returns 401/403 | The camera rejected the credentials; create a dedicated viewing account. |
| MJPEG tile stays black | The MJPEG path is wrong, or the camera limits concurrent connections. Try snapshot mode. |
| HLS says ffmpeg is missing | Install ffmpeg and set its path in *Settings*. |
| HLS stays "stream unavailable" | Check `storage/hls/camera-<id>/ffmpeg.log`; usually RTSP path or credentials. |
| Preview is blank after login | `storage/` not writable, or the session cookie is blocked. |
| 500 after moving hosts | Delete `config/config.php` and run the installer again. |

## Verified in this build

Exercised against MariaDB 11.4 with PHP 8.4: installer, sign-in, throttled
failures, camera CRUD and validation, encrypted credential storage, snapshot
proxy, MJPEG proxy (multipart passthrough), ffmpeg HLS playlist + segment
serving, PTZ error handling, viewer group isolation, CSRF rejection, layout
persistence and 404/403 handling.

Not verified here: a physical ACTi camera (no hardware on the build machine) and
browser rendering (no browser in the build environment). The UI was reviewed as
markup/CSS and all pages return valid HTML.
