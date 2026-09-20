-- ============================================================
-- SCAN-ID
-- Recovery Network Extension
-- Schema Version 3
-- ============================================================

BEGIN;

-- ============================================================
-- LOST IDS
-- ============================================================

CREATE TABLE IF NOT EXISTS lost_ids (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    owner_user_id UUID
        REFERENCES users(id)
        ON DELETE SET NULL,

    owner_phone VARCHAR(20) NOT NULL,

    id_type VARCHAR(50) NOT NULL DEFAULT 'national_id',

    id_number_hash TEXT NOT NULL,

    id_number_last4 VARCHAR(4),

    last_known_location_general VARCHAR(255),

    lost_at TIMESTAMPTZ,

    status VARCHAR(30) NOT NULL DEFAULT 'lost'
        CHECK (
            status IN (
                'lost',
                'matched',
                'recovery_pending',
                'recovered',
                'cancelled',
                'expired'
            )
        ),

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_lost_ids_number_hash
    ON lost_ids(id_number_hash);

CREATE INDEX IF NOT EXISTS idx_lost_ids_status
    ON lost_ids(status);

CREATE INDEX IF NOT EXISTS idx_lost_ids_owner
    ON lost_ids(owner_user_id);

CREATE INDEX IF NOT EXISTS idx_lost_ids_phone
    ON lost_ids(owner_phone);

DROP TRIGGER IF EXISTS lost_ids_updated_at
ON lost_ids;

CREATE TRIGGER lost_ids_updated_at
BEFORE UPDATE ON lost_ids
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- SECURE RECOVERY TOKENS
-- Used by SMS links and QR codes.
--
-- IMPORTANT:
-- The actual token is never stored.
-- Only its SHA-256 hash is stored.
-- ============================================================

CREATE TABLE IF NOT EXISTS recovery_tokens (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    recovery_request_id UUID NOT NULL
        REFERENCES recovery_requests(id)
        ON DELETE CASCADE,

    token_hash TEXT NOT NULL UNIQUE,

    token_type VARCHAR(30) NOT NULL DEFAULT 'recovery'
        CHECK (
            token_type IN (
                'recovery',
                'handover'
            )
        ),

    expires_at TIMESTAMPTZ NOT NULL,

    used_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_recovery_tokens_request
    ON recovery_tokens(recovery_request_id);

CREATE INDEX IF NOT EXISTS idx_recovery_tokens_expires
    ON recovery_tokens(expires_at);

CREATE INDEX IF NOT EXISTS idx_recovery_tokens_type
    ON recovery_tokens(token_type);

-- ============================================================
-- SCHEMA VERSION
-- ============================================================

INSERT INTO schema_versions (
    version,
    description
)
VALUES (
    3,
    'Add lost ID reports and secure recovery tokens for SMS and QR recovery'
)
ON CONFLICT (version) DO NOTHING;

COMMIT;
