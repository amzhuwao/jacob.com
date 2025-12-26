-- =====================================================
-- BUYER APPROVAL WORKFLOW MIGRATION
-- =====================================================
-- Add columns to track delivery and buyer approval
SET @db := DATABASE();
-- Add delivery and approval tracking columns to escrow table
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND COLUMN_NAME = 'work_delivered_at'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN work_delivered_at DATETIME DEFAULT NULL COMMENT "When seller marked work as delivered"',
        'SELECT "Column work_delivered_at already exists"'
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
                AND COLUMN_NAME = 'buyer_approved_at'
        ) = 0,
        'ALTER TABLE escrow ADD COLUMN buyer_approved_at DATETIME DEFAULT NULL COMMENT "When buyer approved work and triggered release"',
        'SELECT "Column buyer_approved_at already exists"'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- Add indexes for efficient querying
SET @sql := IF(
        (
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = @db
                AND TABLE_NAME = 'escrow'
                AND INDEX_NAME = 'idx_work_delivered'
        ) = 0,
        'ALTER TABLE escrow ADD INDEX idx_work_delivered (work_delivered_at)',
        'SELECT "Index idx_work_delivered already exists"'
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
                AND INDEX_NAME = 'idx_buyer_approved'
        ) = 0,
        'ALTER TABLE escrow ADD INDEX idx_buyer_approved (buyer_approved_at)',
        'SELECT "Index idx_buyer_approved already exists"'
    );
PREPARE stmt
FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
-- =====================================================
-- MIGRATION COMPLETE
-- =====================================================
SELECT '✓ Buyer approval workflow columns added to escrow table' as migration_status;