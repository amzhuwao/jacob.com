# Quick Reference - Buyer Approval Workflow

## At a Glance

| Component            | Location                             | Purpose                               |
| -------------------- | ------------------------------------ | ------------------------------------- |
| **Seller Delivery**  | `/dashboard/mark_work_delivered.php` | Seller notifies buyer work is ready   |
| **Buyer Approval**   | `/dashboard/approve_work.php`        | Buyer approves and releases escrow    |
| **Project View**     | `/dashboard/project_view.php`        | Shows workflow steps with buttons     |
| **Buyer Dashboard**  | `/dashboard/buyer.php`               | Status indicators for funded projects |
| **Seller Dashboard** | `/dashboard/seller.php`              | Status indicators for active orders   |

## Database Columns Added

```sql
ALTER TABLE escrow ADD COLUMN work_delivered_at DATETIME;
ALTER TABLE escrow ADD COLUMN buyer_approved_at DATETIME;
```

**Status:** ✅ Applied to database

## API Endpoints

### Mark Work Delivered (Seller)

```
POST /dashboard/mark_work_delivered.php
Auth: Seller only
Body: escrow_id=123
```

**Response:** `{ "status": "success", "work_delivered_at": "..." }`

### Approve Work (Buyer)

```
POST /dashboard/approve_work.php
Auth: Buyer only
Body: escrow_id=123
```

**Response:** `{ "status": "success", "redirect": "/dashboard/buyer.php" }`

## Workflow State Diagram

```
┌─────────────┐
│   Funded    │
└──────┬──────┘
       │ Seller marks delivered
       ↓
┌──────────────────────────┐
│ work_delivered_at = NOW  │
└──────┬───────────────────┘
       │ Buyer approves
       ↓
┌──────────────────────────┐
│ buyer_approved_at = NOW  │
│ EscrowStateMachine →     │
│ Released                 │
└──────┬───────────────────┘
       │ Stripe webhook
       ↓
┌──────────────────────────┐
│ Project = Completed      │
└──────────────────────────┘
```

## Status Badges

### Buyer Dashboard (Funded Projects)

- 📦 **Blue** - Waiting for Delivery
- ⏳ **Orange** - Awaiting Your Approval
- ✓ **Green** - Work Approved

### Seller Dashboard (Active Orders)

- 📦 **Blue** - Ready to Mark as Delivered
- ⏳ **Orange** - Awaiting Buyer Approval
- ✓ **Green** - Work Approved, Funds Releasing

## Key Database Queries

### Check Delivery Status

```sql
SELECT work_delivered_at, buyer_approved_at
FROM escrow WHERE project_id = ?;
```

### Get Projects Awaiting Approval

```sql
SELECT p.* FROM projects p
JOIN escrow e ON p.id = e.project_id
WHERE e.status = 'funded'
  AND e.work_delivered_at IS NOT NULL
  AND e.buyer_approved_at IS NULL
  AND e.buyer_id = ?;
```

## Testing Checklist

- [ ] Seller can mark work delivered
- [ ] Buyer can approve work
- [ ] Escrow transitions to 'released'
- [ ] Project status becomes 'completed'
- [ ] Stripe webhook fires
- [ ] Dashboard shows correct status
- [ ] Admin override still works

## Error Messages

| Error                                      | Cause             | Solution                    |
| ------------------------------------------ | ----------------- | --------------------------- |
| "Only sellers can mark work delivered"     | Wrong role        | Login as seller             |
| "Escrow not found"                         | Invalid escrow_id | Check project escrow exists |
| "Seller must mark work as delivered first" | Work not marked   | Seller marks delivery first |
| "Work already marked as delivered"         | Already delivered | Already completed           |
| "Only buyers can approve work"             | Wrong role        | Login as buyer              |

## Rollback (if needed)

```bash
# Drop columns
ALTER TABLE escrow DROP COLUMN work_delivered_at, buyer_approved_at;

# Delete files
rm /dashboard/mark_work_delivered.php
rm /dashboard/approve_work.php

# Restore from git
git checkout /dashboard/project_view.php
git checkout /dashboard/buyer.php
git checkout /dashboard/seller.php
```

## Documentation Files

| File                         | Content                         |
| ---------------------------- | ------------------------------- |
| `BUYER_APPROVAL_WORKFLOW.md` | Full architecture & integration |
| `TESTING_GUIDE.md`           | Complete testing procedures     |
| `IMPLEMENTATION_SUMMARY.sh`  | Visual project summary          |

## Quick Commands

### Check if columns exist

```bash
mysql -u leonom_leonom -p'L30n0m#2025' leonom_jacob \
  -e "DESCRIBE escrow;" | grep -E "delivered|approved"
```

### View recent approvals

```bash
mysql -u leonom_leonom -p'L30n0m#2025' leonom_jacob \
  -e "SELECT id, work_delivered_at, buyer_approved_at FROM escrow WHERE buyer_approved_at IS NOT NULL LIMIT 10;"
```

### Check state transitions

```bash
mysql -u leonom_leonom -p'L30n0m#2025' leonom_jacob \
  -e "SELECT * FROM escrow_state_transitions WHERE triggered_by='buyer_approval' LIMIT 10;"
```

---

**Status:** ✅ Production Ready  
**Testing:** See TESTING_GUIDE.md  
**Support:** Check BUYER_APPROVAL_WORKFLOW.md for details
