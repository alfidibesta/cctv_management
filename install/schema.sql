-- Argus camera wall - MySQL / MariaDB schema
-- Applied by the installer. Safe to run twice: every object is IF NOT EXISTS.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camera_groups (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120) NOT NULL,
    description VARCHAR(255) NOT NULL DEFAULT '',
    sort_order  INT NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY camera_groups_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cameras (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id        INT UNSIGNED NULL,
    name            VARCHAR(120) NOT NULL,
    location        VARCHAR(160) NOT NULL DEFAULT '',
    driver          VARCHAR(32) NOT NULL DEFAULT 'acti',
    host            VARCHAR(190) NOT NULL DEFAULT '',
    port            SMALLINT UNSIGNED NOT NULL DEFAULT 80,
    rtsp_port       SMALLINT UNSIGNED NOT NULL DEFAULT 554,
    username        VARCHAR(190) NOT NULL DEFAULT '',
    password_enc    TEXT NULL,
    channel         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    stream_mode     ENUM('mjpeg','snapshot','hls') NOT NULL DEFAULT 'mjpeg',
    snapshot_path   VARCHAR(255) NOT NULL DEFAULT '',
    mjpeg_path      VARCHAR(255) NOT NULL DEFAULT '',
    rtsp_path       VARCHAR(255) NOT NULL DEFAULT '',
    ptz_path        VARCHAR(255) NOT NULL DEFAULT '',
    ptz_enabled     TINYINT(1) NOT NULL DEFAULT 0,
    transport       ENUM('tcp','udp') NOT NULL DEFAULT 'tcp',
    is_enabled      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order      INT NOT NULL DEFAULT 0,
    status          ENUM('unknown','online','offline') NOT NULL DEFAULT 'unknown',
    last_seen_at    DATETIME NULL,
    last_latency_ms INT UNSIGNED NULL,
    last_error      VARCHAR(255) NULL,
    created_at      DATETIME NOT NULL,
    updated_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY cameras_group_idx (group_id),
    KEY cameras_status_idx (status),
    KEY cameras_order_idx (sort_order),
    CONSTRAINT cameras_group_fk FOREIGN KEY (group_id) REFERENCES camera_groups (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_groups (
    user_id  INT UNSIGNED NOT NULL,
    group_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, group_id),
    CONSTRAINT user_groups_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT user_groups_group_fk FOREIGN KEY (group_id) REFERENCES camera_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS layouts (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    columns    TINYINT UNSIGNED NOT NULL DEFAULT 9,
    tiles      TEXT NOT NULL,
    mode       ENUM('custom','auto') NOT NULL DEFAULT 'custom',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY layouts_user_unique (user_id),
    CONSTRAINT layouts_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    `key`   VARCHAR(64) NOT NULL,
    `value` TEXT NOT NULL,
    PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activity_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      INT UNSIGNED NULL,
    action       VARCHAR(64) NOT NULL,
    subject_type VARCHAR(32) NULL,
    subject_id   INT UNSIGNED NULL,
    description  VARCHAR(255) NOT NULL DEFAULT '',
    ip           VARCHAR(45) NOT NULL DEFAULT '',
    created_at   DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY activity_user_idx (user_id),
    KEY activity_created_idx (created_at),
    CONSTRAINT activity_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS camera_checks (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    camera_id  INT UNSIGNED NOT NULL,
    status     ENUM('online','offline','unknown') NOT NULL DEFAULT 'unknown',
    latency_ms INT UNSIGNED NULL,
    error      VARCHAR(255) NULL,
    checked_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY camera_checks_camera_time_idx (camera_id, checked_at),
    CONSTRAINT camera_checks_camera_fk FOREIGN KEY (camera_id) REFERENCES cameras (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email      VARCHAR(190) NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    success    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY login_attempts_created_idx (created_at),
    KEY login_attempts_email_idx (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('app_name', 'Argus'),
    ('timezone', 'UTC'),
    ('refresh_interval', '5'),
    ('default_layout', '9'),
    ('snapshot_cache', '3'),
    ('verify_tls', '0'),
    ('ffmpeg_path', 'ffmpeg'),
    ('hls_idle_minutes', '15'),
    ('hls_segment_seconds', '2'),
    ('status_interval', '20'),
    ('wall_title', 'Live wall');
