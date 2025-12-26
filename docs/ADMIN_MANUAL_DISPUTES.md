# Admin Manual Dispute Marking - Feature Documentation

**Date:** December 18, 2025  
**Feature:** Admin ability to manually mark escrows as disputed

---

## Overview

Previously, escrows could only reach `disputed` status through **automatic detection of partial refunds** via Stripe webhooks. This limited the dispute system to post-refund scenarios.

Now, admins can **manually mark any funded escrow as disputed**, enabling proactive dispute handling for reported issues.

---

## How Escrows Become Disputed

### 1. Automatic (Existing) - Partial Refund Detection

**Trigger:** Stripe `charge.refunded` webhook  
**Location:** `/var/www/jacob.com/webhooks/stripe.php` (lines 343-357)

**Flow:**

1. Admin initiates refund via admin panel
2. Stripe processes refund
3. Webhook detects `amount_refunded < amount` (partial refund)
4. System automatically transitions escrow to `disputed`
5. Buyer/seller can open formal dispute case

### 2. Manual (NEW) - Admin-Initiated Disputes

**Trigger:** Admin action in Escrow Management  
**Location:** `/var/www/jacob.com/dashboard/admin_mark_disputed.php`

**Flow:**

1. Admin navigates to **Escrow Management** (`/dashboard/admin_escrows.php`)
2. Locates funded escrow with reported issues
3. Clicks **"Mark as Disputed"** button
4. Reviews escrow details and provides admin reason
5. Confirms action
6. System transitions escrow to `disputed` via state machine
7. Buyer/seller can now open formal dispute case

---

## File Changes

### NEW FILE: `/dashboard/admin_mark_disputed.php`

**Purpose:** Admin interface for marking escrows as disputed

**Features:**

- **GET request:** Shows confirmation form with escrow details
- **POST request:** Processes the dispute marking
- Validates escrow status (must be `funded`)
- Uses EscrowStateMachine for proper state transition
- Records admin reason in audit trail
- Transaction-safe with rollback on error

**Security:**

- Admin role check at entry
- Row-level locking via state machine
- Validates current status before transition
- Logs all actions to escrow_state_transitions

---

### UPDATED FILE: `/dashboard/admin_escrows.php`

**Changes:**

1. Added success/error message display
2. Added "Mark as Disputed" button for funded escrows
3. Added status badge with color coding
4. Added dispute panel links for disputed escrows
5. Improved UI with Bootstrap buttons and icons

**New Actions Available:**

- **Funded escrows:** Release | Hold | **Mark as Disputed** (NEW)
- **Disputed escrows:** View Disputes | Admin Disputes Panel (NEW)

---

### UPDATED FILE: `/disputes/open_dispute.php`

**Changes:**

- Updated "About Disputes" section
- Added explanation of two dispute initiation methods
- Clarified automatic vs manual dispute creation

---

## State Machine Integration

**From:** `/var/www/jacob.com/includes/EscrowStateMachine.php`

```php
const VALID_TRANSITIONS = [
    'funded' => ['release_requested', 'refund_requested', 'disputed'],
    //                                                     ^^^^^^^^
    //                                                     Allows funded → disputed
];
```

**Transition Used:**

```php
$stateMachine->transition(
    $escrowId,
    'disputed',           // Target status
    'admin',              // Triggered by admin
    $userId,              // Admin user ID
    "Admin marked as disputed: {$reason}"  // Audit reason
);
```

---

## Use Cases

### When to Use Manual Dispute Marking

1. **Buyer Reports Issue Before Payment Resolution**

   - Buyer contacts admin about incomplete/wrong work
   - Admin investigates and marks escrow as disputed
   - Formal dispute opened with evidence gathering

2. **Seller Reports Non-Cooperation**

   - Seller claims buyer unresponsive after delivery
   - Admin marks as disputed for investigation
   - Both parties provide evidence

3. **Quality Disputes**

   - Delivered work quality contested
   - Admin marks as disputed pending review
   - Escrow held while evidence collected

4. **Contract Violation Claims**

   - One party claims terms not met
   - Admin initiates dispute process
   - Formal resolution via dispute system

5. **Proactive Issue Management**
   - Admin spots potential problem
   - Marks as disputed before escalation
   - Prevents unauthorized release/refund

---

## Security & Validation

### Access Control

- ✅ Only users with `role = 'admin'` can access
- ✅ HTTP 403 returned for non-admin users
- ✅ Session-based authentication required

### State Validation

- ✅ Only `funded` escrows can be marked disputed
- ✅ Already disputed escrows rejected
- ✅ State machine enforces valid transitions
- ✅ Row-level locking prevents race conditions

### Audit Trail

- ✅ All transitions logged to `escrow_state_transitions`
- ✅ Admin user ID recorded
- ✅ Admin reason stored
- ✅ Timestamp captured

---

## User Interface

### Admin Escrows Page (`/dashboard/admin_escrows.php`)

**Before:**

```
Status: funded
[Release] | [Hold]
```

**After:**

```
Status: [Badge: Funded]
[✓ Release] [⏸ Hold] [⚠ Mark as Disputed]
```

**For Disputed Escrows:**

```
Status: [Badge: Disputed]
[⚖ View Disputes] [🛡 Admin Disputes Panel]
```

### Confirmation Form (`/dashboard/admin_mark_disputed.php?escrow_id=X&action=mark_disputed`)

- Warning alert about consequences
- Full escrow details table
- Admin reason textarea (required)
- Cancel / Confirm buttons
- Bootstrap-styled responsive design

---

## Database Impact

### Table: `escrow`

**Column Updated:** `status`

- `funded` → `disputed`

### Table: `escrow_state_transitions`

**New Row Created:**

```sql
INSERT INTO escrow_state_transitions (
    escrow_id,
    project_id,
    from_status,      -- 'funded'
    to_status,        -- 'disputed'
    triggered_by,     -- 'admin'
    user_id,          -- Admin's user ID
    reason,           -- Admin's reason text
    metadata,         -- NULL
    created_at        -- NOW()
)
```

---

## Testing Scenarios

### Scenario 1: Successful Dispute Marking

1. Login as admin
2. Navigate to Escrow Management
3. Find funded escrow (#123)
4. Click "Mark as Disputed"
5. Enter reason: "Buyer reported incomplete work"
6. Click "Confirm"
7. **Expected:** Redirect with success message
8. **Verify:**
   - Escrow status = `disputed`
   - Transition logged in database
   - Buyer/seller can now open dispute

### Scenario 2: Non-Admin Access Attempt

1. Login as buyer/seller
2. Navigate to `/dashboard/admin_mark_disputed.php?escrow_id=123&action=mark_disputed`
3. **Expected:** HTTP 403 Forbidden

### Scenario 3: Invalid Status

1. Login as admin
2. Try to mark `released` escrow as disputed
3. **Expected:** Error message "Escrow must be in funded status"

### Scenario 4: Already Disputed

1. Login as admin
2. Try to mark `disputed` escrow again
3. **Expected:** Error message "Escrow is already disputed"

---

## Integration with Disputes System

After escrow is marked as disputed:

1. **Escrow appears in user's dispute list** (`/disputes/open_dispute.php`)
2. **Buyer or seller can open formal dispute** with detailed reason
3. **Dispute record created** in `disputes` table
4. **Initial message added** to `dispute_messages` table
5. **Admin reviews in** `/admin/dispute_review.php`
6. **Admin resolves via** refund/release/split actions

---

## Error Handling

### Form Validation

- ✅ Missing escrow ID → Redirect with error
- ✅ Missing reason → Redirect to form with error
- ✅ Invalid escrow ID → "Escrow not found"

### State Transition Errors

- ✅ Database error → Rollback + error log + redirect
- ✅ Concurrent modification → State machine prevents
- ✅ Invalid transition → RuntimeException caught

### User-Friendly Messages

```php
// Success
"Escrow #123 has been marked as disputed. Buyer or seller can now open a formal dispute case."

// Errors
"This escrow is already in disputed status."
"Escrow must be in 'funded' status to mark as disputed."
"Failed to mark as disputed: [error details]"
```

---

## Deployment Notes

### Files to Deploy

1. `/dashboard/admin_mark_disputed.php` (NEW)
2. `/dashboard/admin_escrows.php` (UPDATED)
3. `/disputes/open_dispute.php` (UPDATED)

### No Database Changes Required

- Uses existing state machine transitions
- Uses existing `escrow_state_transitions` table
- No schema migrations needed

### Syntax Validation

```bash
✅ admin_mark_disputed.php - No syntax errors
✅ admin_escrows.php - No syntax errors
✅ open_dispute.php - No syntax errors
```

---

## Monitoring & Metrics

### Recommended Tracking

- Number of admin-initiated disputes per month
- Reason categories for manual disputes
- Time between dispute marking and resolution
- Comparison: manual vs automatic dispute outcomes

### Audit Queries

```sql
-- Admin-initiated disputes
SELECT * FROM escrow_state_transitions
WHERE to_status = 'disputed'
  AND triggered_by = 'admin'
ORDER BY created_at DESC;

-- Count by admin user
SELECT u.username, COUNT(*) as disputes_marked
FROM escrow_state_transitions est
JOIN users u ON est.user_id = u.id
WHERE est.to_status = 'disputed'
  AND est.triggered_by = 'admin'
GROUP BY u.username;
```

---

## Summary

✅ **Feature Complete:**

- Manual dispute marking by admin
- Full state machine integration
- Audit trail maintained
- User-friendly confirmation interface
- Error handling and validation
- Security enforced

✅ **Two Dispute Initiation Paths:**

1. **Automatic:** Partial refunds (existing)
2. **Manual:** Admin action (NEW)

✅ **Ready for Production:** All files validated, security enforced, documentation complete

**Last Updated:** December 18, 2025
