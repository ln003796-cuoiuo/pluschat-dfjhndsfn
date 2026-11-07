-- Calls database migration 001.
-- This database contains call metadata only, never account credentials.
-- user_id and conversation_id are references to IDs owned by the pluschat database.
-- Cross-database foreign keys are intentionally not used.

CREATE TABLE IF NOT EXISTS call_rooms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_key CHAR(64) NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    call_type ENUM('audio', 'video') NOT NULL,
    status ENUM('ringing', 'active', 'ended', 'missed', 'failed') NOT NULL DEFAULT 'ringing',
    started_at DATETIME NULL,
    ended_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_call_rooms_room_key (room_key),
    KEY idx_call_rooms_conversation (conversation_id, created_at),
    KEY idx_call_rooms_creator (created_by_user_id, created_at),
    KEY idx_call_rooms_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_participants (
    call_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    participant_role ENUM('host', 'participant') NOT NULL DEFAULT 'participant',
    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    joined_at DATETIME NULL,
    left_at DATETIME NULL,
    PRIMARY KEY (call_id, user_id),
    KEY idx_call_participants_user (user_id, invited_at),
    CONSTRAINT fk_call_participants_call FOREIGN KEY (call_id)
        REFERENCES call_rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS call_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    call_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    event_type ENUM(
        'created', 'invited', 'joined', 'left', 'muted', 'unmuted',
        'camera_on', 'camera_off', 'screen_share_started',
        'screen_share_stopped', 'ended', 'failed'
    ) NOT NULL,
    event_data JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_call_events_call (call_id, id),
    KEY idx_call_events_actor (actor_user_id, created_at),
    CONSTRAINT fk_call_events_call FOREIGN KEY (call_id)
        REFERENCES call_rooms (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
