# Buyer Approval Workflow - Testing Guide

## Quick Start Test

### Prerequisites

- Two test accounts: one seller, one buyer
- A posted project with an accepted bid
- Escrow funded

### Test Steps

#### Step 1: Seller Marks Work Delivered

1. Login as **seller**
2. Navigate to **Dashboard → Active Orders** or **View Project**
3. Click **"Mark Work as Delivered"** button
4. Confirm the dialog
5. **Expected Result:**
   - Button disappears
   - Status changes to "⏳ Awaiting Buyer Approval" (orange badge)
   - Timestamp displays delivery time

#### Step 2: Buyer Reviews Delivery

1. Login as **buyer**
2. Navigate to **Dashboard → Funded Projects** or **View Project**
3. See **blue badge**: "📦 Waiting for Delivery" has changed to **orange**: "⏳ Awaiting Your Approval"
4. Click **"Approve Work & Release Payment"** button
5. Confirm the dialog
6. **Expected Result:**
   - Button disappears
   - Status changes to "✓ Work Approved" (green badge)
   - Message: "Work approved! Funds are being released to seller..."
   - Browser redirects to buyer dashboard

#### Step 3: Verify Escrow Released

1. Navigate back to **project page**
2. Scroll to **Escrow Status** section
3. **Expected Result:**
   - Escrow status shows: "Released"
   - Both timestamps visible:
     - Work Delivered: [date/time]
     - Buyer Approved: [date/time]

#### Step 4: Verify Project Completed

1. Wait 10-15 seconds (for webhook processing)
2. Refresh project page
3. **Expected Result:**
   - Project status: **Completed** ✓
   - Escrow section hidden (project no longer in progress)
   - Project appears in buyer's "Completed" column

---

## Database Verification

### Check Escrow Delivery Status

```sql
SELECT id, status, work_delivered_at, buyer_approved_at
FROM escrow
WHERE project_id = [TEST_PROJECT_ID];
```

**Expected Output:**

```
id: [escrow_id]
status: released
work_delivered_at: 2025-12-26 17:45:32
buyer_approved_at: 2025-12-26 17:46:15
```

### Check State Transitions Log

```sql
SELECT * FROM escrow_state_transitions
WHERE escrow_id = [TEST_ESCROW_ID]
ORDER BY created_at DESC
LIMIT 5;
```

**Expected Output:**

- Last transition: `triggered_by='buyer_approval'`
- Status change: `from_status='funded'` → `to_status='released'`

### Check Stripe Payout

```sql
SELECT stripe_payout_id, status, updated_at
FROM escrow
WHERE id = [TEST_ESCROW_ID];
```

**Expected Output:**

- `stripe_payout_id` should have value (e.g., `po_xxx`)
- `status` should be `'released'` or `'completed'`

---

## API Testing

### Test Seller Endpoint (mark_work_delivered.php)

**cURL Request:**

```bash
curl -X POST http://localhost/dashboard/mark_work_delivered.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "escrow_id=123" \
  --cookie "PHPSESSID=[SESSION_ID_SELLER]"
```

**Expected Response:**

```json
{
  "status": "success",
  "message": "Work marked as delivered",
  "work_delivered_at": "2025-12-26 17:45:32"
}
```

**Error Scenarios:**

- Not logged in: `403 Forbidden`
- Wrong role (buyer): `403 Forbidden`
- Invalid escrow: `500 Internal Server Error` + "Escrow not found"
- Already delivered: `500 Internal Server Error` + "Already marked"

---

### Test Buyer Endpoint (approve_work.php)

**cURL Request:**

```bash
curl -X POST http://localhost/dashboard/approve_work.php \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "escrow_id=123" \
  --cookie "PHPSESSID=[SESSION_ID_BUYER]"
```

**Expected Response:**

```json
{
  "status": "success",
  "message": "Work approved! Funds are being released to seller...",
  "escrow_id": 123,
  "buyer_approved_at": "2025-12-26 17:46:15",
  "redirect": "/dashboard/buyer.php"
}
```

**Error Scenarios:**

- Not logged in: `403 Forbidden`
- Wrong role (seller): `403 Forbidden`
- Work not delivered yet: `500 Internal Server Error` + "Must mark work as delivered first"
- Already approved: Returns `info` status (idempotent)
- Escrow not funded: `500 Internal Server Error` + "Must be in funded status"

---

## Dashboard Testing

### Buyer Dashboard Verification

1. Login as buyer
2. Go to **Dashboard** → **Pipeline** → **Funded Projects** column
3. **For each project:**
   - If `work_delivered_at IS NULL`: Blue badge "📦 Waiting for Delivery"
   - If `work_delivered_at IS NOT NULL AND buyer_approved_at IS NULL`: Orange badge "⏳ Awaiting Your Approval"
   - If `buyer_approved_at IS NOT NULL`: Green badge "✓ Work Approved"

### Seller Dashboard Verification

1. Login as seller
2. Go to **Dashboard** → **Active Orders** section
3. **For each order:**
   - If `work_delivered_at IS NULL`: Blue badge "📦 Ready to Mark as Delivered"
   - If `work_delivered_at IS NOT NULL AND buyer_approved_at IS NULL`: Orange badge "⏳ Awaiting Buyer Approval"
   - If `buyer_approved_at IS NOT NULL`: Green badge "✓ Work Approved - Funds Releasing"

---

## Admin Override Testing

### Test Admin Override in Dispute Resolution

1. Create a test project and fund escrow (but don't mark as delivered)
2. Login as seller
3. Navigate to project
4. Click "Report Dispute"
5. Fill dispute form
6. Login as admin
7. Go to **Admin Panel → Disputes**
8. Find dispute
9. Click **"Resolve Dispute"**
10. Should see button: **"Mark Complete & Release"**
11. Click button
12. **Expected Result:**
    - Dispute status: closed
    - Escrow released (regardless of delivery/approval state)
    - Project marked complete

---

## Edge Cases & Error Handling

### Test: Seller marks delivered twice

1. Seller marks work delivered
2. Seller tries to mark again
3. **Expected:** Error message "Work already marked as delivered"

### Test: Buyer approves without delivery

1. Escrow funded but work NOT marked delivered
2. Buyer tries to approve
3. **Expected:** Error message "Seller must mark work as delivered first"

### Test: Buyer approves twice

1. Buyer approves work (first time)
2. Buyer tries to approve again
3. **Expected:** Idempotent response (info status with same timestamp)

### Test: Non-owner can't approve

1. Project between Seller A and Buyer A
2. Buyer B (different user) tries to approve
3. **Expected:** Error "You are not the buyer of this escrow"

### Test: Concurrent requests

1. Two browser windows, both logged in as buyer
2. Both click "Approve Work" button simultaneously
3. **Expected:** First succeeds, second gets idempotent success response

### Test: Transaction rollback

1. Start approval (buyer clicks button)
2. Disconnect database midway
3. **Expected:**
   - Error message returned
   - `buyer_approved_at` NOT set
   - Escrow still in 'funded' status

---

## Performance Testing

### Load Test: Multiple Projects

1. Create 100 funded projects
2. Run script to mark 50 as delivered
3. Run script to approve 30
4. **Expected:**
   - Dashboard loads in <2 seconds
   - Indexes properly used (check EXPLAIN)
   - No N+1 queries

```sql
-- Check index usage
EXPLAIN SELECT p.* FROM projects p
JOIN escrow e ON p.id = e.project_id
WHERE e.work_delivered_at IS NOT NULL
  AND e.buyer_approved_at IS NULL;
-- Should use idx_work_delivered index
```

---

## Browser Testing

### Test AJAX Handlers

1. Browser DevTools → Network tab
2. Perform actions in project view
3. **Expected:**
   - "Mark Work Delivered" sends POST to `/mark_work_delivered.php`
   - "Approve Work" sends POST to `/approve_work.php`
   - Loading spinner shown while request pending
   - Success/error message displayed
   - Page reloads or redirects on success

### Test Mobile Responsiveness

1. Open project_view.php on mobile
2. Test buttons and badges
3. **Expected:**
   - Buttons stack properly
   - Text readable
   - Colors visible
   - Status badges wrap correctly

---

## Stripe Integration Testing

### Monitor Stripe Webhook

1. Go to **Stripe Dashboard → Developers → Webhooks**
2. Test webhook endpoint: `/webhooks/stripe_webhook.php`
3. Create/approve test escrow
4. **Expected:**
   - Webhook log shows `transfer.paid` event
   - Event logged at correct time
   - Project status updated to 'completed'

### Check Transfer Details

1. Go to **Stripe Dashboard → Payouts**
2. Look for payout matching escrow amount
3. **Expected:**
   - Status: "in_transit" or "paid"
   - Amount matches escrow
   - Timestamp matches approval time

---

## Regression Testing

### Verify Existing Features Still Work

- [ ] Admin can still release escrow manually (not via approval)
- [ ] Dispute workflow unchanged
- [ ] Refund workflow unchanged
- [ ] Stripe integration still works
- [ ] Payment funding works
- [ ] Project status transitions work
- [ ] Dashboard displays work correctly
- [ ] Email notifications still sent (if implemented)

---

## Security Testing

### Test Authorization

- [ ] Seller can't approve work
- [ ] Buyer can't mark as delivered
- [ ] Unauthenticated users get 403
- [ ] CSRF tokens validated (if used)
- [ ] Session hijacking prevented

### Test Data Integrity

- [ ] Timestamps can't be backdated
- [ ] No SQL injection possible
- [ ] Transaction rollback works on errors
- [ ] No race conditions between concurrent requests

---

## Browser Console Testing

### Check for JavaScript Errors

1. Open browser DevTools → Console
2. Perform all workflow steps
3. **Expected:** No errors, warnings acceptable

### Check Network Requests

1. DevTools → Network tab
2. Click approval buttons
3. **Expected:**
   - POST requests to endpoints
   - 200 OK responses
   - JSON responses valid
   - No failed requests

---

## Final Checklist

- [ ] Database columns exist and indexed
- [ ] Both endpoints created and functional
- [ ] Project view shows delivery/approval UI
- [ ] Buyer dashboard shows status indicators
- [ ] Seller dashboard shows status indicators
- [ ] Workflow can complete end-to-end
- [ ] Escrow transitions to 'released' state
- [ ] Project status changes to 'completed'
- [ ] Stripe webhook fires and processes
- [ ] Admin override still works
- [ ] No regression in existing features
- [ ] Security validations in place
- [ ] Error handling works correctly
- [ ] Mobile responsive
- [ ] Documentation complete

---

## Rollback Plan

If issues found during testing, rollback is simple:

```bash
# 1. Drop the new columns
mysql -h localhost -u leonom_leonom -p'L30n0m#2025' leonom_jacob \
  -e "ALTER TABLE escrow DROP COLUMN work_delivered_at, DROP COLUMN buyer_approved_at;"

# 2. Remove new endpoint files
rm /var/www/jacob.com/dashboard/mark_work_delivered.php
rm /var/www/jacob.com/dashboard/approve_work.php

# 3. Restore project_view.php from git
git checkout /var/www/jacob.com/dashboard/project_view.php

# 4. Restore buyer.php from git
git checkout /var/www/jacob.com/dashboard/buyer.php

# 5. Restore seller.php from git
git checkout /var/www/jacob.com/dashboard/seller.php
```

---

**Testing Status:** Ready to execute
**Expected Duration:** 2-3 hours for complete testing
**Deployment:** Ready after successful testing
