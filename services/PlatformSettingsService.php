<?php

/**
 * PlatformSettingsService
 * Manages platform-wide configuration and settings
 */
class PlatformSettingsService
{
    private $pdo;
    private $cache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get a setting value by key
     */
    public function get(string $key, $default = null)
    {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $stmt = $this->pdo->prepare("SELECT setting_value, data_type FROM platform_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $default;
        }

        $value = $this->parseValue($row['setting_value'], $row['data_type']);
        $this->cache[$key] = $value;
        return $value;
    }

    /**
     * Set a setting value
     */
    public function set(string $key, $value, ?int $updatedBy = null): bool
    {
        try {
            $dataType = $this->detectDataType($value);
            $stringValue = is_array($value) || is_object($value) ? json_encode($value) : (string)$value;

            $stmt = $this->pdo->prepare("
                INSERT INTO platform_settings (setting_key, setting_value, data_type, updated_by)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    data_type = VALUES(data_type),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ");
            $stmt->execute([$key, $stringValue, $dataType, $updatedBy]);

            // Clear cache
            unset($this->cache[$key]);
            return true;
        } catch (Exception $e) {
            error_log("PlatformSettingsService::set failed for key '$key': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all settings
     */
    public function getAll(): array
    {
        $stmt = $this->pdo->query("SELECT setting_key, setting_value, data_type, description, updated_at, u.full_name as updated_by
                                   FROM platform_settings ps
                                   LEFT JOIN users u ON ps.updated_by = u.id
                                   ORDER BY setting_key");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[$row['setting_key']] = [
                'value' => $this->parseValue($row['setting_value'], $row['data_type']),
                'type' => $row['data_type'],
                'description' => $row['description'],
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by']
            ];
        }
        return $result;
    }

    /**
     * Get commission percentage
     */
    public function getCommissionPercentage(): float
    {
        return (float)$this->get('commission_percentage', 10);
    }

    /**
     * Get minimum escrow amount
     */
    public function getMinEscrowAmount(): float
    {
        return (float)$this->get('min_escrow_amount', 50);
    }

    /**
     * Get max transaction amount
     */
    public function getMaxTransactionAmount(): float
    {
        return (float)$this->get('max_transaction_amount', 50000);
    }

    /**
     * Get dispute resolution time limit in days
     */
    public function getDisputeResolutionDays(): int
    {
        return (int)$this->get('dispute_resolution_days', 14);
    }

    /**
     * Is KYC required for sellers
     */
    public function isKycRequiredForSeller(): bool
    {
        $val = $this->get('kyc_required_for_seller', 'true');
        return $val === 'true' || $val === true || $val === 1;
    }

    /**
     * Get Stripe payout threshold
     */
    public function getStripePayoutThreshold(): float
    {
        return (float)$this->get('stripe_payout_threshold', 100);
    }

    /**
     * Get supported currencies
     */
    public function getSupportedCurrencies(): array
    {
        $val = $this->get('supported_currencies', '["USD"]');
        if (is_array($val)) {
            return $val;
        }
        return json_decode($val, true) ?: ['USD'];
    }

    /**
     * Get auto-release days (0 = disabled)
     */
    public function getAutoReleaseDays(): int
    {
        return (int)$this->get('auto_release_days', 0);
    }

    /**
     * Is maintenance mode enabled
     */
    public function isMaintenanceMode(): bool
    {
        $val = $this->get('maintenance_mode', 'false');
        return $val === 'true' || $val === true || $val === 1;
    }

    /**
     * Get refund fee percentage
     */
    public function getRefundFeePercentage(): float
    {
        return (float)$this->get('refund_fee_percentage', 0);
    }

    /**
     * Get ToS text
     */
    public function getTermsOfService(): string
    {
        return (string)$this->get('tos_text', '');
    }

    /**
     * Get privacy policy text
     */
    public function getPrivacyPolicy(): string
    {
        return (string)$this->get('privacy_policy_text', '');
    }

    /**
     * Parse a value based on its data type
     */
    private function parseValue($value, string $dataType)
    {
        switch ($dataType) {
            case 'integer':
                return (int)$value;
            case 'decimal':
                return (float)$value;
            case 'boolean':
                return $value === 'true' || $value === true || $value === 1;
            case 'json':
                return is_string($value) ? json_decode($value, true) : $value;
            case 'string':
            default:
                return $value;
        }
    }

    /**
     * Detect data type of a value
     */
    private function detectDataType($value): string
    {
        if (is_bool($value)) {
            return 'boolean';
        } elseif (is_int($value)) {
            return 'integer';
        } elseif (is_float($value)) {
            return 'decimal';
        } elseif (is_array($value) || is_object($value)) {
            return 'json';
        }
        return 'string';
    }
}
