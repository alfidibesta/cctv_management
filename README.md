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
- **Four ways to get video into the browser:**
  - `WebRTC` (WHEP) — go2rtc or MediaMTX, sub-second and hardware decoded.
  - `MJPEG` — proxied multipart stream, lowest latency, lowest server CPU.
  - `Snapshot` — JPEG refresh loop, works with almost every camera.
  - `HLS` — RTSP converted by ffmpeg for cameras that only speak RTSP, with
    1–2 s segments so the wall stays a few seconds behind live.
- **Credentials stay on the server.** Camera passwords are AES-256-GCM encrypted
  in MySQL and never reach the browser: every frame is fetched through
  `stream.php` after an access check.
- **Camera status at a glance.** Online/offline dots, latency, and a friendly
  "no signal" frame instead of a broken image. Parallel health probes.
- **Digital zoom & pan, browser-side.** Smooth 1.0x–8.0x canvas zoom centred on
  the cursor, drag panning, a zoom slider, a *Reset 1.0x* button and a corner
  picture-in-picture mini-map of the zoomed region. No camera motor is moved
  and no hardware PTZ is required.
- **Locked 30 FPS compositor.** Every tile is drawn by `requestAnimationFrame`
  with a 33.33 ms delta accumulator, a hardware-accelerated canvas and batched
  HUD writes, so a 4×4 wall keeps a steady 30 FPS with live frame-time, jitter
  and buffer-health telemetry.
- **IR night vision, snapshots and drag-and-drop.** A client-side night-vision
  canvas filter, PNG snapshots of exactly what you see (zoom included) and
  drag-and-drop channel assignment on the TV wall.
- **Person detection & people counting.** Inference runs in a Web Worker at
  5–10 FPS while the 30 FPS canvas draws `PERSON 94%` boxes, centroid dots and
  `#ID-04` tracking ids. Operators draw a virtual tripwire (IN/OUT/NET) or an
  occupancy zone with an alarm threshold; counts batch-sync to PHP every 5 s and
  appear on the tile HUD and the Analytics page, with hourly CSV export.
- **RTSP latency controls.** Global and per-camera transport (UDP/TCP), a
  50–2000 ms jitter-buffer slider with Ultra-Low 100 / Balanced 300 / Stable
  1000 presets, and an optional frame-drop policy that keeps playback live.
- **Credential inspector.** Decrypts the stored password on request, shows the
  plaintext next to the sanitized RTSP URI (`aaa19!` → `aaa19%21`), and logs
  `AUTH_VERIFIED` or `AUTH_ENCODING_ERROR` with the secret masked.
- **WebRTC via go2rtc or MediaMTX.** A PHP signalling proxy keeps the media
  server on loopback and falls back to HLS/MSE, MJPEG or a canvas test pattern.
- **Roles and per-group access** — admin, operator, viewer.
- **Audit trail + error log.** Every action is recorded, and PHP errors or
  warnings go to `storage/logs/app.log` and to the *Activity → Errors* tab.
- **Light mode, compact density and a collapsible sidebar.** Saved per browser,
  so a wall monitor and a laptop can keep different looks.
- **Connection check after every save.** A new camera is probed immediately and
  the result comes with concrete fix suggestions instead of a bare error.
- **Snapshot download** straight from a tile.
- **Health history.** Every probe is sampled into `camera_checks`, so **Health**
  shows uptime, latency and estimated downtime per camera, with CSV export of the
  raw samples or an aggregated summary.
- **ONVIF discovery.** Scan the LAN (WS-Discovery) or type a camera address, read
  its media profiles and add the main or sub-stream straight from the table.
- **CSV exports** for the activity log, the error log and the health history.

## Requirements

| Requirement | Notes |
| --- | --- |
| PHP 8.1 – 8.5 | `pdo_mysql`, `curl`, `openssl`, `mbstring`. PHP 8.5 is supported: the deprecated `PDO::MYSQL_ATTR_*` constant and the no-op `curl_close()` calls are gone |
| MySQL 5.7+ / MariaDB 10.4+ | utf8mb4 |
| ffmpeg | optional, only for cameras in **HLS** mode. Can be dropped into `bin/` — see below |
| go2rtc or MediaMTX | optional, for WebRTC (WHEP) playback and stream provisioning |
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

### FFmpeg without a package manager (Laragon on Windows, locked-down servers)

HLS mode needs an `ffmpeg` executable. You do **not** have to install a package
or edit `PATH`: Argus looks for a binary inside the project first.

1. Download a static Windows build — either
   [gyan.dev → ffmpeg-release-essentials.zip](https://www.gyan.dev/ffmpeg/builds/)
   or [BtbN builds](https://github.com/BtbN/FFmpeg-Builds/releases).
2. Extract the archive and open its `bin` folder. Copy **`ffmpeg.exe`** (the
   other files are optional, but `ffprobe.exe` is handy for debugging).
3. Create a folder named `bin` inside the Argus project (next to `public/`,
   `app/` and `config/`) and paste the file there:
   ```
   argus/
     bin/
       ffmpeg.exe     ← dropped in by hand
     public/
     app/
   ```
4. Open **Settings → Streaming** and leave *ffmpeg path* as `ffmpeg`. The
   *Server diagnostics* panel now shows the detected binary path and
   `ffmpeg (HLS) → Ready`.
5. Reload the wall and pick **HLS** as the stream mode for an RTSP camera.

How the lookup works (first match wins):

| Order | Looking for |
| --- | --- |
| 1 | The path typed in *Settings → Streaming*, when it is not plain `ffmpeg` |
| 2 | `bin/ffmpeg.exe` (Windows) or `bin/ffmpeg` (Linux) inside the project |
| 3 | `ffmpeg` on the system `PATH` |

Linux/macOS equivalent:

```bash
mkdir -p bin
# static build example
tar -xf ffmpeg-release-amd64-static.tar.xz
cp ffmpeg-*-static/ffmpeg bin/ffmpeg
chmod +x bin/ffmpeg
```

If you would rather point at an absolute path such as
`C:\tools\ffmpeg\bin\ffmpeg.exe`, type it into *ffmpeg path* instead — Argus
accepts both. Add `bin/ffmpeg*` to `.gitignore` if you commit this project, so
the ~100 MB binary stays out of the repository.

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
  playlist in `storage/hls/`. Segment length is *Settings → HLS segment length*
  (1–6 s, default 2); `split_by_time` keeps segments short even when the camera
  only emits a keyframe every few seconds, and the player starts two segments
  behind the live edge in low-latency mode. Expect roughly `2 × segment length`
  seconds behind live, so 1 s segments land around 2–3 s.

### Camera health

Each probe stores a sample in `camera_checks` — at most one per minute per camera
while the state is steady, plus one immediately on every online/offline change.
**Health** in the sidebar shows uptime for 24 h, 7 d or 14 d, average latency,
estimated downtime and an hourly strip per camera, and exports the raw samples or
a per-camera summary as CSV. History is kept for 14 days; `php scripts/cleanup.php`
prunes older rows (run it from cron, it also stops idle HLS converters).

Downtime is estimated from samples (one sample ≈ one minute), so a camera that is
never watched shows little history until the wall or the status poll probes it.

### ONVIF discovery

**Discover** sends a WS-Discovery multicast probe to `239.255.255.250:3702` and
lists the cameras that answer. Cameras behind a router, VLAN or Docker bridge
never answer multicast — type their address (`10.0.0.20`) instead and press
*Load profiles*.

For each device Argus reads `GetProfiles` plus the stream and snapshot URI of
every profile, then labels the largest one **Main** and the rest **Sub**. Add the
sub-stream for a large wall (less bandwidth and CPU) and the main stream for the
camera you actually watch. Passwords typed for the lookup are not kept: enter
them again in the add form, where they are encrypted into the camera record.

WS-Discovery needs UDP 3702 outbound and the replies back on an ephemeral port;
Windows Firewall may ask once. Nothing is scanned automatically — the button is
the only thing that sends packets.

### Performance and low latency

The wall starts media only for tiles that are on screen while the browser tab is
visible. Off-screen tiles are released — MJPEG connections dropped, ffmpeg HLS
players destroyed, snapshot refresh paused — and restart when they scroll back
into view, so a large wall does not keep every decoder and camera connection
alive in the background.

For the lowest latency on the same hardware: use **MJPEG** where the camera
offers it (~0.5 s, no transcoding), keep **HLS** segments at 1–2 s with the
camera's I-frame interval at 1–2 s, and give the server enough PHP workers (one
per concurrently streamed MJPEG tile).

The HLS worker is tuned for that: ffmpeg probes for 1 s / 1 MB instead of the
default 2 s / 2 MB, uses `-fflags nobuffer -flags low_delay` for live RTSP/RTMP
inputs (never for file or HTTP sources, where they discard packets while probing
and produce an empty stream), and keeps a three-segment playlist. The browser
starts one segment behind the live edge and may play at up to 2× speed to catch
up, so a 1 s segment setting lands roughly 1.5–2.5 s behind live; 2 s segments
land around 3–4 s.

### Digital zoom, pan and night vision

Zoom and pan happen in the browser, on a canvas that composites the camera
frame. Nothing is sent to the camera, so they work with every camera and every
stream mode, and two operators can look at different regions of the same feed.

| Control | Action |
| --- | --- |
| Mouse wheel | Zoom 1.0x–8.0x at the cursor position |
| Drag (when zoomed) | Pan the visible region |
| Slider / + / − | Zoom without a mouse wheel |
| `Reset 1.0x` or double-click | Back to the full frame |
| PiP mini-map | Shows the visible region inside the full frame |
| Night vision | Grayscale + green screen blend, per tile or whole wall |

The tile HUD reports live latency, jitter, buffer health, measured FPS and
frame time. Snapshots are captured from the canvas, so they include the current
zoom and night-vision filter.

### RTSP latency and buffer

The transport, jitter buffer and frame-drop policy can be set globally in
*Settings → RTSP latency & buffer* and overridden per camera:

- **Transport** — `UDP` for the lowest latency on a clean LAN, `TCP` when packets
  are lost (Wi-Fi, congested switches).
- **Jitter buffer / latency target** — 50–2000 ms. Presets: *Ultra-Low 100 ms*,
  *Balanced 300 ms*, *Stable 1000 ms*. ffmpeg receives the value as
  `-max_delay`, and the analysis/probe window scales with it.
- **Frame drop** — discards corrupt packets and late frames instead of queueing
  them, so a stalled camera resumes at the live edge rather than replaying old
  video.

The wall adds the render cost to the target and shows the result as `ms` in
HLS/WebRTC, with jitter and buffer health next to it.

### Person detection and people counting

Detection is edge-side and non-blocking: a Web Worker receives frames at 5–10
FPS, tracks people, and posts normalised boxes back. The overlay canvas redraws
at the locked 30 FPS, so inference never stalls video.

- **Backend** — drop `tf.min.js` and `coco-ssd.min.js` into
  `public/assets/vendor/` to run COCO-SSD person inference locally. Without
  those files (and for demo cameras) the worker runs deterministic simulated
  pedestrians, so counting, tripwires and telemetry work out of the box with no
  multi-megabyte model in the repository.
- **Per-camera controls** — detection on/off, confidence 30–95 %, bounding-box
  HUD, and a tracking id per person.
- **Tripwire** — click two points on the video; IN/OUT arrows are drawn at the
  line, `Flip IN/OUT` swaps the direction, and `IN` / `OUT` / `NET` update live.
- **Zone occupancy** — drag a box; `INSIDE` counts people in it and an alarm is
  raised when the configurable limit is exceeded.
- **Telemetry** — the browser batches events to `/api/analytics.php` every 5
  seconds (occupancy is also sampled every 30 s), and PHP stores them in
  `person_events`. The *Analytics* page shows IN/OUT/INSIDE/NET, an hourly bar
  chart, daily totals, recent samples and an hourly CSV export.
- **Access** — any signed-in user with camera access may stream counting
  samples; only administrators and operators can persist detection settings.

### Credentials and RTSP encoding

Camera passwords are stored with AES-256-GCM and only decrypted on the server.
When an RTSP URL is built, both the username and password are percent-encoded
with RFC 3986 rules (PHP `rawurlencode`, the same set as JavaScript
`encodeURIComponent`):

```
aaa19!          →  rtsp://operator:aaa19%21@10.0.0.21:554/onvif1
```

*Cameras → Edit → Credential check → Reveal & verify* decrypts the stored
password, shows the plaintext next to the sanitized URI and reports
`AUTH_VERIFIED` or `AUTH_ENCODING_ERROR`. The activity log records the verdict
with the password masked; URIs are redacted before they reach the log.

### WebRTC, go2rtc and MediaMTX

Set the driver in `config/config.php` (the installer writes it, and `none` keeps
MJPEG/HLS working without a media server):

```php
'media' => [
    'driver'       => 'go2rtc',   // none | go2rtc | mediamtx
    'go2rtc_url'   => 'http://127.0.0.1:1984',
    'mediamtx_url' => 'http://127.0.0.1:9997',
    'timeout'      => 3,
],
```

Adding, editing or deleting a camera registers its RTSP source automatically:

- **go2rtc** — `PUT /api/streams?name={cam_id}&src={encoded_rtsp_url}` and
  `DELETE /api/streams?name={cam_id}`.
- **MediaMTX** — `POST /v3/config/paths/add/{cam_id}` (falling back to
  `PATCH /v3/config/paths/patch/{cam_id}`) with `source`, `rtspTransport` and
  `sourceOnDemand`, and `POST /v3/config/paths/delete/{cam_id}`.

Playback goes through `/api/webrtc_proxy.php`, which requires a signed-in
session and camera access, relays the SDP offer to go2rtc's `/api/webrtc?src=`
or MediaMTX's `/{cam_id}/whep`, and returns the answer. The media server port is
never exposed to the browser. If signalling or playback fails, the tile falls
back to HLS/MSE, then MJPEG, and finally an animated canvas test pattern instead
of a frozen black rectangle.

## Roles

| Role | Wall | Digital zoom | Cameras / groups | Users, settings, audit |
| --- | --- | --- | --- | --- |
| Administrator | all | yes | manage | yes |
| Operator | all | yes | manage | no |
| Viewer | assigned groups | yes | read only | no |

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

Need an account without the setup wizard (empty `users` table, forgotten
password, restored database)? Create one — or reset an existing email — from the
command line. The bcrypt hash is generated for you and the password is never
echoed:

```bash
php scripts/create-admin.php                          # fully interactive
php scripts/create-admin.php --email=admin@site.com --password='S3cret-pass'
php scripts/create-admin.php --email=ops@site.com --role=operator --password='...'
```

Running it again with an existing email resets that account's password and role,
so it doubles as a lockout fix. `--help` lists the options; on Windows use
`C:\laragon\bin\php\php-8.x\php.exe scripts\create-admin.php`.


Logs live in `storage/logs/`:

| File | Contents |
| --- | --- |
| `app.log` | JSON lines for every event and error (rotated at 2 MB, 3 kept) |
| `php-error.log` | PHP's own error log, written by `log_errors` |

The same entries are readable in the browser under **Activity → Errors**, and
errors are also mirrored into the activity table.

## Layout

```
app/                 PHP application (controllers, repositories, services, views)
  Repositories/      includes Analytics: counting config and foot-traffic samples
  Http/              request handlers + router wiring
  Repositories/      SQL for cameras, groups, users, layouts, activity
  Services/          stream URLs, credentials, snapshot/MJPEG fetch, HLS, go2rtc/MediaMTX
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
| HLS says ffmpeg is missing | Copy `ffmpeg.exe` into the project `bin/` folder (see *FFmpeg without a package manager*), or set its full path in *Settings → Streaming*. |
| HLS stays "stream unavailable" | Check `storage/hls/camera-<id>/ffmpeg.log`; usually RTSP path or credentials. |
| Preview is blank after login | `storage/` not writable, or the session cookie is blocked. |
| 500 after moving hosts | Delete `config/config.php` and run the installer again. |
| Any 500 | Open **Activity → Errors** or `storage/logs/app.log`; the exact message and file:line are recorded. |
| Camera saves but stays offline | The camera page shows the probe result with numbered fixes after saving. |

## Verified in this build

Exercised against MariaDB 11.4 with PHP 8.4.23 and PHP 8.5.8: installer, sign-in,
throttled failures, camera CRUD and validation, encrypted credential storage,
snapshot proxy, MJPEG proxy (multipart passthrough), ffmpeg HLS playlist +
segment serving, viewer group isolation, CSRF rejection,
layout persistence, the health history and CSV exports, ONVIF profile parsing,
the `create-admin` CLI and 404/403 handling.

On PHP 8.5.8 the whole end-to-end suite runs with `error_reporting=E_ALL` and
writes no deprecation notice to the PHP error log or to `app.log`.

Also verified in a real browser against this build: dark and light themes, the
compact density, sidebar rail mode, collapsible cards (state survives reload),
the post-save camera check with fix suggestions, the *Activity → Errors* tab and
its level filter, and the friendly 500 page produced by the global error handler.

Not verified here: a physical ACTi camera (no hardware on the build machine), and
ffmpeg HLS playback with a real RTSP camera (no binary or camera in the build
environment — only the drop-in lookup and the failure guidance were exercised).
