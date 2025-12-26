# Phase 4: Stripe Payout-Driven Release Flow

## 🎯 Overview

The admin release flow now requires **Stripe payout confirmation** before marking escrow as `released`. This prevents premature fund release and ensures sellers receive payment before state changes.

---

## 🔄 Correct State Flow

### Before (INCORRECT ❌)

```
funded → released (admin clicks release)
```

**Problem:** Escrow marked as released immediately, but Stripe payout might fail.

### After (CORRECT ✅)

```
funded
  → release_requested (admin clicks release, Stripe payout created)
  → payout.processing (Stripe processing transfer)
  → payout.succeeded (Stripe webhook confirms)
  → released (state updated by webhook)
```

---

## 📋 State Transitions

| From State          | To State            | Trigger                  | Who            | Method                           |
| ------------------- | ------------------- | ------------------------ | -------------- | -------------------------------- |
| `pending`           | `funded`            | payment_intent.succeeded | Stripe Webhook | `handlePaymentIntentSucceeded()` |
| `funded`            | `release_requested` | Admin clicks "Release"   | Admin          | `admin_transactions.php`         |
| `release_requested` | `released`          | transfer.paid            | Stripe Webhook | `handleTransferPaid()`           |
| `funded`            | `refunded`          | Admin clicks "Refund"    | Admin          | `admin_transactions.php`         |
| `release_requested` | `funded`            | transfer.failed          | Stripe Webhook | `handleTransferFailed()`         |

---

## 🔐 Database Schema Changes

### Escrow Table Updates

```sql
-- New status value
ALTER TABLE escrow MODIFY COLUMN status ENUM(
    'pending',
    'funded',
    'release_requested',  -- NEW
    'released',
    'refunded',
    'canceled',
    'disputed'
);

-- New column for payout tracking
ALTER TABLE escrow ADD COLUMN stripe_payout_id VARCHAR(255);
```

### Users Table Updates

```sql
-- For seller connected accounts
ALTER TABLE users ADD COLUMN stripe_account_id VARCHAR(255);
```

---

## 🔧 Implementation Details

### 1. EscrowStateMachine.php

**Valid Transitions Updated:**

```php
private const VALID_TRANSITIONS = [
    'pending' => ['funded', 'canceled'],
    'funded' => ['release_requested', 'refunded', 'disputed'],
    'release_requested' => ['released'],  // Only via webhook
    'released' => [],
    'refunded' => [],
    'canceled' => [],
    'disputed' => ['released', 'refunded'],
];
```

**New Method - createPayout():**

```php
public function createPayout(int $escrowId, int $sellerId): array
```

- Validates escrow can be released
- Gets seller's `stripe_account_id`
- Creates Stripe Transfer to seller's connected account
- Stores `stripe_payout_id` in escrow record
- Returns payout details

---

### 2. admin_transactions.php

**Updated Release Action:**

```php
if ($action === 'release_escrow') {
    // 1. Transition to release_requested
    $stateMachine->transition(
        $escrow_id,
        'release_requested',
        'admin',
        $_SESSION['user_id'],
        $reason
    );

    // 2. Create Stripe payout
    $payout = $stateMachine->createPayout($escrow_id, $escrow['seller_id']);

    // 3. Log admin action
    // 4. If payout fails, rollback to funded
}
```

---

### 3. webhooks/stripe.php

**New Webhook Handlers:**

#### handleTransferPaid()

- Triggered by: `transfer.paid` event
- Action: Transitions `release_requested` → `released`
- Logs transaction with type `'payout'`

#### handleTransferFailed()

- Triggered by: `transfer.failed` event
- Action: Rolls back `release_requested` → `funded`
- Logs failed transaction with failure reason

**Main Handler Updated:**

```php
switch ($eventType) {
    case 'payment_intent.succeeded':
        handlePaymentIntentSucceeded($pdo, $eventData);
        break;
    case 'transfer.paid':
        handleTransferPaid($pdo, $eventData);
        break;
    case 'transfer.failed':
        handleTransferFailed($pdo, $eventData);
        break;
}
```

---

## 🎨 UI Updates

### New Status Badge

```css
.badge-release_requested {
  background: #bee3f8;
  color: #2c5282;
}
```

### Status Display

- Shows "Release Requested" (formatted from `release_requested`)
- Admin sees "Payout created: tr\_..." message after clicking release
- Buttons disabled while in `release_requested` state

---

## 🧪 Testing Flow

### 1. Setup Stripe CLI

```bash
stripe listen --forward-to http://localhost/webhooks/stripe.php
```

### 2. Create Test Scenario

1. **Buyer:** Post project → Accept bid → Fund escrow (PaymentIntent succeeds)
2. **Webhook:** Transitions `pending` → `funded`
3. **Admin:** Login → admin_transactions.php → Click "Release" → Enter reason
4. **System:** Transitions `funded` → `release_requested`, creates Stripe transfer
5. **Stripe CLI:** Trigger `transfer.paid`:
   ```bash
   stripe trigger transfer.paid
   ```
6. **Webhook:** Transitions `release_requested` → `released`
7. **Verify:** Check `escrow.status = 'released'`, `payment_transactions` has payout entry

### 3. Test Failure Scenario

```bash
stripe trigger transfer.failed
```

- Verify escrow rolls back to `funded`
- Admin can retry release

---

## 📊 Audit Trail

### payment_transactions Table

```sql
transaction_type = 'payout'
status = 'succeeded' | 'failed'
stripe_charge_id = transfer ID (tr_...)
```

### admin_actions Table

```sql
action_type = 'release_escrow'
previous_state = 'funded'
new_state = 'release_requested'
```

### escrow_state_transitions Table

```sql
from_status = 'release_requested'
to_status = 'released'
triggered_by = 'stripe_webhook'
```

---

## ⚠️ Important Notes

1. **Seller Connected Account Required:** Sellers must have `stripe_account_id` set in users table before release
2. **Rollback on Failure:** If payout fails, state automatically reverts to `funded`
3. **Idempotency:** Webhook events are logged in `stripe_webhook_events` to prevent duplicate processing
4. **Admin Visibility:** All payout attempts logged in `admin_actions` with reasons

---

## 🔒 Security

- **Webhook Signature Verification:** All Stripe events verified with HMAC-SHA256
- **Row-Level Locking:** `SELECT FOR UPDATE` prevents race conditions
- **State Machine Enforcement:** Only valid transitions allowed
- **Audit Trail:** Every state change logged with timestamp, user, and reason

---

## 📝 Migration Status

✅ **Executed Successfully**

- `escrow.status` now includes `release_requested`
- `escrow.stripe_payout_id` column added
- `users.stripe_account_id` column added
- EscrowStateMachine updated
- Webhook handlers implemented
- Admin UI updated

**Next Steps:**

1. Configure Stripe Connect for seller onboarding
2. Test payout flow with test mode transfers
3. Monitor `payment_transactions` table for payout records
4. Document seller account connection process
