<?php
// CLI backfill script to migrate historical released escrows into wallets
// Usage: php scripts/backfill_wallets.php [--dry-run] [--seller=ID] [--include-approved]

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$sellerIdFilter = null;
$includeApproved = in_array('--include-approved', $argv, true);
foreach ($argv as $arg) {
    if (strpos($arg, '--seller=') === 0) {
        $val = substr($arg, strlen('--seller='));
        if (ctype_digit($val)) {
            $sellerIdFilter = (int)$val;
        }
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/WalletService.php';

$walletService = new WalletService($pdo);

function getEligibleEscrows(PDO $pdo, ?int $sellerIdFilter = null, bool $includeApproved = false): array
{
    $where = "wt.id IS NULL AND (e.status = 'released'" . ($includeApproved ? " OR e.buyer_approved_at IS NOT NULL" : "") . ")";
    $sql = "
        SELECT e.id as escrow_id, e.project_id, e.seller_id, e.amount, e.buyer_approved_at
        FROM escrow e
        LEFT JOIN wallet_transactions wt 
            ON wt.escrow_id = e.id AND wt.type = 'credit'
        WHERE " . $where . ($sellerIdFilter ? " AND e.seller_id = ?" : "") . "
        ORDER BY e.id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $params = [];
    if ($sellerIdFilter) {
        $params[] = $sellerIdFilter;
    }
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$eligible = getEligibleEscrows($pdo, $sellerIdFilter, $includeApproved);

printf(
    "Found %d eligible escrows without wallet credits%s%s.\n",
    count($eligible),
    $sellerIdFilter ? (" for seller #" . $sellerIdFilter) : "",
    $includeApproved ? " (including buyer-approved)" : ""
);
if ($dryRun) {
    echo "Dry-run mode: No changes will be made.\n";
}

$credited = 0;
$skipped = 0;
$errors = 0;

foreach ($eligible as $row) {
    $escrowId = (int)$row['escrow_id'];
    $sellerId = (int)$row['seller_id'];
    $projectId = (int)$row['project_id'];
    $amount = (float)$row['amount'];

    // Double check again for idempotency
    $check = $pdo->prepare("SELECT id FROM wallet_transactions WHERE escrow_id = ? AND type = 'credit' LIMIT 1");
    $check->execute([$escrowId]);
    if ($check->fetch()) {
        $skipped++;
        continue;
    }

    if ($dryRun) {
        printf("[DRY] Would credit seller #%d escrow #%d amount $%.2f\n", $sellerId, $escrowId, $amount);
        $credited++;
        continue;
    }

    try {
        $walletService->creditEarnings($sellerId, $amount, $escrowId, $projectId, 'Backfill: pre-wallet earnings');
        printf("[OK] Credited seller #%d escrow #%d amount $%.2f\n", $sellerId, $escrowId, $amount);
        $credited++;
    } catch (Exception $e) {
        fprintf(STDERR, "[ERR] Escrow #%d: %s\n", $escrowId, $e->getMessage());
        $errors++;
    }
}

printf("\nSummary: Credited=%d, Skipped=%d, Errors=%d\n", $credited, $skipped, $errors);

exit($errors > 0 ? 2 : 0);
