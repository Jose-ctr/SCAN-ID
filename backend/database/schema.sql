-- ============================================================
-- SCAN-ID
-- Lost ID Recovery Network Kenya
-- PostgreSQL Database Schema
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- Extensions
-- ------------------------------------------------------------

CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- ------------------------------------------------------------
-- Updated-at trigger function
-- ------------------------------------------------------------

CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ============================================================
-- USERS
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    full_name VARCHAR(150) NOT NULL,

    phone VARCHAR(20) NOT NULL UNIQUE,

    email VARCHAR(255) UNIQUE,

    password_hash TEXT,

    role VARCHAR(30) NOT NULL DEFAULT 'user'
        CHECK (
            role IN (
                'user',
                'admin'
            )
        ),

    phone_verified_at TIMESTAMPTZ,

    is_active BOOLEAN NOT NULL DEFAULT TRUE,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_users_phone
    ON users(phone);

CREATE INDEX IF NOT EXISTS idx_users_email
    ON users(email);

CREATE TRIGGER users_updated_at
BEFORE UPDATE ON users
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- USER SESSIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS user_sessions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    user_id UUID NOT NULL
        REFERENCES users(id)
        ON DELETE CASCADE,

    token_hash TEXT NOT NULL UNIQUE,

    device_name VARCHAR(150),

    ip_address INET,

    user_agent TEXT,

    expires_at TIMESTAMPTZ NOT NULL,

    last_used_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_user_sessions_user_id
    ON user_sessions(user_id);

CREATE INDEX IF NOT EXISTS idx_user_sessions_expires_at
    ON user_sessions(expires_at);

-- ============================================================
-- FOUND IDS
-- ============================================================

CREATE TABLE IF NOT EXISTS found_ids (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    finder_user_id UUID
        REFERENCES users(id)
        ON DELETE SET NULL,

    id_type VARCHAR(50) NOT NULL DEFAULT 'national_id',

    id_number_hash TEXT NOT NULL,

    id_number_last4 VARCHAR(4),

    found_location_general VARCHAR(255),

    found_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    status VARCHAR(30) NOT NULL DEFAULT 'found'
        CHECK (
            status IN (
                'found',
                'owner_notified',
                'recovery_requested',
                'handover_pending',
                'returned',
                'expired',
                'cancelled'
            )
        ),

    finder_consent_to_contact BOOLEAN NOT NULL DEFAULT FALSE,

    finder_consent_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_found_ids_number_hash
    ON found_ids(id_number_hash);

CREATE INDEX IF NOT EXISTS idx_found_ids_status
    ON found_ids(status);

CREATE INDEX IF NOT EXISTS idx_found_ids_finder
    ON found_ids(finder_user_id);

CREATE TRIGGER found_ids_updated_at
BEFORE UPDATE ON found_ids
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- RECOVERY REQUESTS
-- ============================================================

CREATE TABLE IF NOT EXISTS recovery_requests (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    found_id_id UUID NOT NULL
        REFERENCES found_ids(id)
        ON DELETE CASCADE,

    owner_user_id UUID
        REFERENCES users(id)
        ON DELETE SET NULL,

    owner_phone VARCHAR(20) NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'notified',
                'payment_pending',
                'paid',
                'contact_released',
                'completed',
                'cancelled',
                'expired'
            )
        ),

    requested_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    paid_at TIMESTAMPTZ,

    contact_released_at TIMESTAMPTZ,

    completed_at TIMESTAMPTZ,

    expires_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_recovery_requests_found_id
    ON recovery_requests(found_id_id);

CREATE INDEX IF NOT EXISTS idx_recovery_requests_owner
    ON recovery_requests(owner_user_id);

CREATE INDEX IF NOT EXISTS idx_recovery_requests_status
    ON recovery_requests(status);

CREATE TRIGGER recovery_requests_updated_at
BEFORE UPDATE ON recovery_requests
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- SMS NOTIFICATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS sms_notifications (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    recovery_request_id UUID
        REFERENCES recovery_requests(id)
        ON DELETE SET NULL,

    recipient_phone VARCHAR(20) NOT NULL,

    message TEXT NOT NULL,

    provider VARCHAR(50) NOT NULL DEFAULT 'africastalking',

    provider_message_id VARCHAR(255),

    status VARCHAR(30) NOT NULL DEFAULT 'queued'
        CHECK (
            status IN (
                'queued',
                'sent',
                'delivered',
                'failed'
            )
        ),

    sent_at TIMESTAMPTZ,

    delivered_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_sms_notifications_request
    ON sms_notifications(recovery_request_id);

CREATE INDEX IF NOT EXISTS idx_sms_notifications_status
    ON sms_notifications(status);

CREATE INDEX IF NOT EXISTS idx_sms_notifications_provider_id
    ON sms_notifications(provider_message_id);

-- ============================================================
-- RECOVERY PAYMENTS
-- ============================================================

CREATE TABLE IF NOT EXISTS recovery_payments (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    recovery_request_id UUID NOT NULL
        REFERENCES recovery_requests(id)
        ON DELETE CASCADE,

    amount_kes INTEGER NOT NULL
        CHECK (amount_kes > 0),

    provider VARCHAR(50) NOT NULL DEFAULT 'mpesa',

    checkout_request_id VARCHAR(255) UNIQUE,

    merchant_request_id VARCHAR(255),

    mpesa_receipt_number VARCHAR(100) UNIQUE,

    phone VARCHAR(20) NOT NULL,

    status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'completed',
                'failed',
                'cancelled'
            )
        ),

    result_code VARCHAR(20),

    result_description TEXT,

    paid_at TIMESTAMPTZ,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_recovery_payments_request
    ON recovery_payments(recovery_request_id);

CREATE INDEX IF NOT EXISTS idx_recovery_payments_status
    ON recovery_payments(status);

CREATE INDEX IF NOT EXISTS idx_recovery_payments_checkout
    ON recovery_payments(checkout_request_id);

CREATE TRIGGER recovery_payments_updated_at
BEFORE UPDATE ON recovery_payments
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- HANDOVERS
-- ============================================================

CREATE TABLE IF NOT EXISTS handovers (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    recovery_request_id UUID NOT NULL UNIQUE
        REFERENCES recovery_requests(id)
        ON DELETE CASCADE,

    finder_consent BOOLEAN NOT NULL DEFAULT FALSE,

    safe_location VARCHAR(255),

    scheduled_at TIMESTAMPTZ,

    completed_at TIMESTAMPTZ,

    status VARCHAR(30) NOT NULL DEFAULT 'pending'
        CHECK (
            status IN (
                'pending',
                'scheduled',
                'completed',
                'cancelled'
            )
        ),

    notes TEXT,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_handovers_status
    ON handovers(status);

CREATE TRIGGER handovers_updated_at
BEFORE UPDATE ON handovers
FOR EACH ROW
EXECUTE FUNCTION set_updated_at();

-- ============================================================
-- AUDIT LOG
-- ============================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    user_id UUID
        REFERENCES users(id)
        ON DELETE SET NULL,

    action VARCHAR(100) NOT NULL,

    entity_type VARCHAR(100),

    entity_id UUID,

    ip_address INET,

    metadata JSONB,

    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_logs_user
    ON audit_logs(user_id);

CREATE INDEX IF NOT EXISTS idx_audit_logs_entity
    ON audit_logs(entity_type, entity_id);

CREATE INDEX IF NOT EXISTS idx_audit_logs_action
    ON audit_logs(action);

CREATE INDEX IF NOT EXISTS idx_audit_logs_created_at
    ON audit_logs(created_at);

-- ============================================================
-- SCHEMA VERSION
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_versions (
    version INTEGER PRIMARY KEY,

    description TEXT NOT NULL,

    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO schema_versions (
    version,
    description
)
VALUES (
    1,
    'Initial SCAN-ID identity recovery, notification, payment, handover and audit schema'
)
ON CONFLICT (version) DO NOTHING;

COMMIT;
