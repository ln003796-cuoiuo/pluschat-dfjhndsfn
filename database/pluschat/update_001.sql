-- Pluschat database migration 001.
-- Adds the channel publishing and subscribed-channel feed foundation.
-- Accounts remain in this database; do not create a second users table.

CREATE TABLE IF NOT EXISTS channels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    slug VARCHAR(48) NOT NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(500) NULL,
    avatar_path VARCHAR(255) NULL,
    visibility ENUM('public', 'private') NOT NULL DEFAULT 'public',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_channels_slug (slug),
    KEY idx_channels_owner (owner_user_id, updated_at),
    KEY idx_channels_discovery (visibility, updated_at),
    CONSTRAINT fk_channels_owner FOREIGN KEY (owner_user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_subscriptions (
    channel_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    notifications_enabled TINYINT(1) NOT NULL DEFAULT 1,
    subscribed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (channel_id, user_id),
    KEY idx_channel_subscriptions_user (user_id, subscribed_at),
    CONSTRAINT fk_channel_subscriptions_channel FOREIGN KEY (channel_id)
        REFERENCES channels (id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_subscriptions_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS channel_posts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    post_type ENUM('text', 'image', 'video', 'file') NOT NULL DEFAULT 'text',
    body TEXT NULL,
    media_path VARCHAR(1024) NULL,
    media_mime VARCHAR(120) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_channel_posts_feed (channel_id, id),
    KEY idx_channel_posts_author (author_user_id, created_at),
    CONSTRAINT fk_channel_posts_channel FOREIGN KEY (channel_id)
        REFERENCES channels (id) ON DELETE CASCADE,
    CONSTRAINT fk_channel_posts_author FOREIGN KEY (author_user_id)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
