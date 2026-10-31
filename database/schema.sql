CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    username VARCHAR(24) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    email_verified_at TIMESTAMP NULL DEFAULT NULL,
    display_name VARCHAR(80) NOT NULL,
    bio VARCHAR(280) NULL,
    avatar_path VARCHAR(255) NULL,
    age_years TINYINT UNSIGNED NULL,
    location_city VARCHAR(100) NULL,
    location_enabled_at TIMESTAMP NULL DEFAULT NULL,
    location_cell CHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_location_city (location_city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_verification_codes (
    email VARCHAR(254) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    verified_at DATETIME NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    PRIMARY KEY (email),
    KEY idx_email_codes_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_reset_codes (
    email VARCHAR(254) NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NOT NULL,
    PRIMARY KEY (email),
    KEY idx_password_reset_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    session_version INT UNSIGNED NOT NULL,
    device_label VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_devices_token (token_hash),
    KEY idx_user_devices_user (user_id, revoked_at, last_used_at),
    CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_rate_limits (
    bucket_key CHAR(64) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket_key),
    KEY idx_auth_rate_limits_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kind ENUM('direct', 'group', 'local') NOT NULL,
    title VARCHAR(100) NULL,
    city_code VARCHAR(100) NULL,
    age_group VARCHAR(24) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conversations_kind (kind, created_at),
    KEY idx_conversations_local (kind, city_code, age_group),
    UNIQUE KEY uq_local_chat_scope (kind, city_code, age_group),
    CONSTRAINT fk_conversations_creator FOREIGN KEY (created_by)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_members (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role ENUM('owner', 'member') NOT NULL DEFAULT 'member',
    joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_message_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_conversation_members_user (user_id, joined_at),
    CONSTRAINT fk_conversation_members_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_members_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sticker_packs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    cover_sticker_id BIGINT UNSIGNED NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sticker_packs_owner (owner_user_id, updated_at),
    KEY idx_sticker_packs_public (is_public, updated_at),
    CONSTRAINT fk_sticker_packs_owner FOREIGN KEY (owner_user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stickers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pack_id BIGINT UNSIGNED NOT NULL,
    asset_path VARCHAR(512) NOT NULL,
    alt_text VARCHAR(120) NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_stickers_pack_order (pack_id, sort_order, id),
    CONSTRAINT fk_stickers_pack FOREIGN KEY (pack_id)
        REFERENCES sticker_packs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_sticker_packs (
    user_id BIGINT UNSIGNED NOT NULL,
    pack_id BIGINT UNSIGNED NOT NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, pack_id),
    KEY idx_user_sticker_packs_pack (pack_id),
    CONSTRAINT fk_user_sticker_packs_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_sticker_packs_pack FOREIGN KEY (pack_id)
        REFERENCES sticker_packs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message_type ENUM('text', 'sticker', 'gif') NOT NULL DEFAULT 'text',
    body TEXT NULL,
    sticker_id BIGINT UNSIGNED NULL,
    media_url VARCHAR(1024) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_messages_conversation_id (conversation_id, id),
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id)
        REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sticker FOREIGN KEY (sticker_id)
        REFERENCES stickers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
