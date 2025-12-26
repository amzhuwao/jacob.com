-- Admin Activity Audit Log Table
-- Tracks all admin actions for compliance, debugging, and security
CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    -- e.g., 'suspend_user', 'release_escrow', 'issue_refund', 'update_commission'
    entity_type VARCHAR(32) NOT NULL,
    -- 'user', 'project', 'escrow', 'dispute', 'payment', 'system'
    entity_id INT,
    -- ID of affected entity (user_id, project_id, escrow_id, etc.)
    description TEXT,
    -- Human-readable summary of action
    old_values JSON,
    -- Previous state (e.g., {"status": "open", "balance": 1000})
    new_values JSON,
    -- New state (e.g., {"status": "released", "balance": 0})
    ip_address VARCHAR(45),
    -- IPv4 or IPv6
    user_agent VARCHAR(255),
    -- Browser/API client info
    status ENUM('success', 'failed', 'pending') DEFAULT 'success',
    error_message TEXT,
    -- If status is 'failed'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_admin_user_id (admin_user_id),
    INDEX idx_action (action),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at),
    INDEX idx_status (status)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Platform Settings Table
-- Centralized configuration for commissions, thresholds, policies
CREATE TABLE IF NOT EXISTS platform_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(64) UNIQUE NOT NULL,
    -- e.g., 'commission_percentage', 'min_escrow_amount', 'max_transaction_amount'
    setting_value TEXT NOT NULL,
    data_type ENUM(
        'string',
        'integer',
        'decimal',
        'boolean',
        'json'
    ) DEFAULT 'string',
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE
    SET NULL,
        INDEX idx_key (setting_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
-- Insert default platform settings
INSERT INTO platform_settings (
        setting_key,
        setting_value,
        data_type,
        description
    )
VALUES (
        'commission_percentage',
        '10',
        'decimal',
        'Global platform commission as percentage'
    ),
    (
        'min_escrow_amount',
        '50',
        'decimal',
        'Minimum escrow amount allowed'
    ),
    (
        'max_transaction_amount',
        '50000',
        'decimal',
        'Maximum single transaction amount'
    ),
    (
        'dispute_resolution_days',
        '14',
        'integer',
        'Days allowed to resolve a dispute before auto-close'
    ),
    (
        'kyc_required_for_seller',
        'true',
        'boolean',
        'Require KYC verification for sellers'
    ),
    (
        'stripe_payout_threshold',
        '100',
        'decimal',
        'Minimum balance before auto-payout'
    ),
    (
        'supported_currencies',
        '["USD", "EUR", "GBP"]',
        'json',
        'List of supported currencies'
    ),
    (
        'auto_release_days',
        '0',
        'integer',
        'Auto-release funds to seller after N days (0 = disabled)'
    ),
    (
        'refund_fee_percentage',
        '0',
        'decimal',
        'Platform fee for refunds'
    ),
    (
        'maintenance_mode',
        'false',
        'boolean',
        'Enable to prevent new transactions'
    ),
    (
        'webhook_secret_stripe',
        '',
        'string',
        'Stripe webhook signing secret (masked)'
    ),
    (
        'tos_text',
        '',
        'string',
        'Terms of Service full text'
    ),
    (
        'privacy_policy_text',
        '',
        'string',
        'Privacy Policy full text'
    ) ON DUPLICATE KEY
UPDATE id = id;