-- =====================================================
-- PHASE 4: STRIPE WEBHOOKS & STATE LOCKING MIGRATION
-- =====================================================
-- This migration adds proper state management, audit trails,
-- and Stripe integration for escrow transactions
-- 1. ADD STRIPE PAYMENT INTENT TO ESCROW TABLE
-- =====================================================
-- Idempotent column additions
SET @db := DATABASE();
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'stripe_payment_intent_id'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN stripe_payment_intent_id VARCHAR(255) DEFAULT NULL;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'stripe_checkout_session_id'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN stripe_checkout_session_id VARCHAR(255) DEFAULT NULL;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'payment_status'
        ) = 0,
        "ALTER TABLE escrow ADD COLUMN payment_status ENUM('pending','processing','succeeded','failed','canceled') DEFAULT 'pending';",
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'funded_at'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN funded_at DATETIME DEFAULT NULL;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'released_at'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN released_at DATETIME DEFAULT NULL;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'stripe_payout_id'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN stripe_payout_id VARCHAR(255) DEFAULT NULL;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'updated_at'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'stripe_refund_id'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN stripe_refund_id VARCHAR(255) DEFAULT NULL;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Ensure status enum includes refund_requested
SET @has_refund_requested := (
        SELECT CASE
                WHEN COLUMN_TYPE LIKE "%'refund_requested'%" THEN 1
                ELSE 0
            END
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = @db
            AND TABLE_NAME = 'escrow'
            AND COLUMN_NAME = 'status'
    );
SET @sql := IF(
        @has_refund_requested = 1,
        'DO 0;',
        "ALTER TABLE escrow MODIFY COLUMN status ENUM('pending','funded','release_requested','refund_requested','released','refunded','canceled','disputed') NOT NULL DEFAULT 'pending';"
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Idempotent indexes
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND INDEX_NAME = 'idx_stripe_payment_intent'
        ) = 0,
        'ALTER TABLE escrow ADD INDEX idx_stripe_payment_intent (stripe_payment_intent_id);',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND INDEX_NAME = 'idx_payment_status'
        ) = 0,
        'ALTER TABLE escrow ADD INDEX idx_payment_status (payment_status);',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- 2. CREATE PAYMENT TRANSACTIONS AUDIT TABLE
-- =====================================================
CREATE TABLE IF NOT EXISTS payment_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escrow_id INT NOT NULL,
    project_id INT NOT NULL,
    buyer_id INT NOT NULL,
    seller_id INT NOT NULL,
    -- Stripe data
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    stripe_charge_id VARCHAR(255) DEFAULT NULL,
    stripe_event_id VARCHAR(255) UNIQUE DEFAULT NULL,
    -- Transaction details
    amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'usd',
    transaction_type ENUM('charge', 'refund', 'payout', 'fee') NOT NULL,
    status ENUM(
        'pending',
        'processing',
        'succeeded',
        'failed',
        'canceled',
        'refunded'
    ) NOT NULL,
    -- Metadata
    failure_reason TEXT DEFAULT NULL,
    stripe_raw_data JSON DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    -- Indexes
    INDEX idx_escrow_id (escrow_id),
    INDEX idx_project_id (project_id),
    INDEX idx_stripe_payment_intent (stripe_payment_intent_id),
    INDEX idx_stripe_event (stripe_event_id),
    INDEX idx_status (status),
    INDEX idx_transaction_type (transaction_type),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (escrow_id) REFERENCES escrow(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
-- 3. CREATE ESCROW STATE TRANSITIONS LOG
-- =====================================================
CREATE TABLE IF NOT EXISTS escrow_state_transitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    escrow_id INT NOT NULL,
    project_id INT NOT NULL,
    -- State change
    from_status VARCHAR(50) NOT NULL,
    to_status VARCHAR(50) NOT NULL,
    -- Who/What triggered it
    triggered_by ENUM('user', 'webhook', 'admin', 'system') NOT NULL,
    user_id INT DEFAULT NULL,
    -- Context
    reason TEXT DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    -- Timestamp
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    -- Indexes
    INDEX idx_escrow_id (escrow_id),
    INDEX idx_project_id (project_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (escrow_id) REFERENCES escrow(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
-- 4. CREATE WEBHOOK EVENTS LOG
-- =====================================================
CREATE TABLE IF NOT EXISTS stripe_webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Stripe event data
    stripe_event_id VARCHAR(255) UNIQUE NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    -- Processing state machine: pending → processing → processed
    processing BOOLEAN DEFAULT FALSE,
    processed BOOLEAN DEFAULT FALSE,
    processing_attempts INT DEFAULT 0,
    last_error TEXT DEFAULT NULL,
    -- Payload
    payload JSON NOT NULL,
    -- Timestamps
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    -- Indexes
    INDEX idx_stripe_event_id (stripe_event_id),
    INDEX idx_event_type (event_type),
    INDEX idx_processing (processing),
    INDEX idx_processed (processed),
    INDEX idx_created_at (created_at)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
-- Add processing column if not exists (idempotent)
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'stripe_webhook_events'
                AND COLUMN_NAME = 'processing'
        ) = 0,
        'ALTER TABLE stripe_webhook_events ADD COLUMN processing BOOLEAN DEFAULT FALSE;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- 5. ADD ADMIN ACTIONS LOG
-- =====================================================
CREATE TABLE IF NOT EXISTS admin_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    -- Action details
    action_type ENUM(
        'release_escrow',
        'refund_payment',
        'cancel_project',
        'ban_user',
        'resolve_dispute',
        'manual_payout'
    ) NOT NULL,
    entity_type ENUM('escrow', 'project', 'user', 'payment') NOT NULL,
    entity_id INT NOT NULL,
    -- Context
    reason TEXT NOT NULL,
    notes TEXT DEFAULT NULL,
    previous_state VARCHAR(100) DEFAULT NULL,
    new_state VARCHAR(100) DEFAULT NULL,
    -- Timestamp
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    -- Indexes
    INDEX idx_admin_id (admin_id),
    INDEX idx_action_type (action_type),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at),
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
-- 6. UPDATE EXISTING ESCROW RECORDS
-- =====================================================
-- Set payment_status based on current status
UPDATE escrow
SET payment_status = CASE
        WHEN status = 'funded' THEN 'succeeded'
        WHEN status = 'released' THEN 'succeeded'
        WHEN status = 'pending' THEN 'pending'
        ELSE 'pending'
    END;
-- 7. MODIFY ESCROW STATUS TO INCLUDE RELEASE_REQUESTED
-- =====================================================
-- Add release_requested state to escrow.status enum
ALTER TABLE escrow
MODIFY COLUMN status ENUM(
        'pending',
        'funded',
        'release_requested',
        'released',
        'refunded',
        'canceled',
        'disputed'
    ) NOT NULL DEFAULT 'pending';
-- 8. ADD STRIPE_ACCOUNT_ID TO USERS (for seller payouts)
-- =====================================================
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'users'
                AND COLUMN_NAME = 'stripe_account_id'
        ) = 0,
        'ALTER TABLE users ADD COLUMN stripe_account_id VARCHAR(255) DEFAULT NULL;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- 9. ENFORCE UNIQUENESS (PREVENT DOUBLE SPENDING)
-- =====================================================
-- One escrow per project; one active payment mapping per escrow
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND INDEX_NAME = 'uniq_escrow_project'
        ) = 0,
        'ALTER TABLE escrow ADD UNIQUE INDEX uniq_escrow_project (project_id);',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND INDEX_NAME = 'uniq_escrow_payment_intent'
        ) = 0,
        'ALTER TABLE escrow ADD UNIQUE INDEX uniq_escrow_payment_intent (stripe_payment_intent_id);',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND INDEX_NAME = 'uniq_escrow_checkout_session'
        ) = 0,
        'ALTER TABLE escrow ADD UNIQUE INDEX uniq_escrow_checkout_session (stripe_checkout_session_id);',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- =====================================================
-- DISPUTES MANAGEMENT TABLES
-- =====================================================
-- Disputes table
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'disputes'
        ) = 0,
        'CREATE TABLE disputes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            escrow_id INT NOT NULL,
            opened_by INT NOT NULL,
            opened_at DATETIME NOT NULL,
            status ENUM("open", "resolved") DEFAULT "open",
            reason LONGTEXT NOT NULL,
            resolved_by INT,
            resolved_at DATETIME,
            resolution ENUM("refund_buyer", "release_to_seller", "split") DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escrow_id) REFERENCES escrow(id) ON DELETE CASCADE,
            FOREIGN KEY (opened_by) REFERENCES users(id),
            FOREIGN KEY (resolved_by) REFERENCES users(id),
            UNIQUE INDEX uniq_dispute_escrow (escrow_id),
            INDEX idx_status (status),
            INDEX idx_opened_at (opened_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Dispute messages table
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'dispute_messages'
        ) = 0,
        'CREATE TABLE dispute_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispute_id INT NOT NULL,
            user_id INT NOT NULL,
            message LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id),
            INDEX idx_dispute (dispute_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Dispute evidence (file uploads) table
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'dispute_evidence'
        ) = 0,
        'CREATE TABLE dispute_evidence (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispute_id INT NOT NULL,
            uploaded_by INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INT NOT NULL,
            mime_type VARCHAR(100),
            uploaded_at DATETIME NOT NULL,
            FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
            FOREIGN KEY (uploaded_by) REFERENCES users(id),
            INDEX idx_dispute (dispute_id),
            INDEX idx_uploaded_at (uploaded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Dispute resolutions (for split payments) table
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'dispute_resolutions'
        ) = 0,
        'CREATE TABLE dispute_resolutions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            dispute_id INT NOT NULL,
            resolution_type ENUM("refund_buyer", "release_to_seller", "split") NOT NULL,
            buyer_amount DECIMAL(10, 2),
            seller_amount DECIMAL(10, 2),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
            INDEX idx_dispute (dispute_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;',
        'DO 0;'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- =====================================================
-- MIGRATION COMPLETE
-- =====================================================
-- Summary:
-- ✓ Added Stripe payment tracking to escrow table
-- ✓ Created payment_transactions for full audit trail
-- ✓ Created escrow_state_transitions for state change history
-- ✓ Created stripe_webhook_events for idempotent webhook processing
-- ✓ Created admin_actions for accountability
-- ✓ Updated existing records with proper payment_status
-- ✓ Created disputes system (disputes, dispute_messages, dispute_evidence, dispute_resolutions)