#!/bin/bash

# Phase 4 Testing Script
# This script helps test the complete payout-driven release flow

echo "=============================================="
echo "Phase 4: Stripe Payout Flow Testing"
echo "=============================================="
echo ""

# Check database schema
echo "1. Verifying Database Schema..."
echo "   - Checking escrow.status enum..."
mysql -u root -p jacob_db -e "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='jacob_db' AND TABLE_NAME='escrow' AND COLUMN_NAME='status';" 2>/dev/null | grep release_requested
if [ $? -eq 0 ]; then
    echo "   ✓ release_requested state exists"
else
    echo "   ✗ release_requested state missing - run migration!"
    exit 1
fi

echo "   - Checking escrow.stripe_payout_id..."
mysql -u root -p jacob_db -e "SHOW COLUMNS FROM escrow LIKE 'stripe_payout_id';" 2>/dev/null | grep -q stripe_payout_id
if [ $? -eq 0 ]; then
    echo "   ✓ stripe_payout_id column exists"
else
    echo "   ✗ stripe_payout_id column missing - run migration!"
    exit 1
fi

echo "   - Checking users.stripe_account_id..."
mysql -u root -p jacob_db -e "SHOW COLUMNS FROM users LIKE 'stripe_account_id';" 2>/dev/null | grep -q stripe_account_id
if [ $? -eq 0 ]; then
    echo "   ✓ stripe_account_id column exists"
else
    echo "   ✗ stripe_account_id column missing - run migration!"
    exit 1
fi

echo ""
echo "2. Testing Stripe CLI..."
which stripe > /dev/null 2>&1
if [ $? -eq 0 ]; then
    echo "   ✓ Stripe CLI installed"
else
    echo "   ✗ Stripe CLI not found - install from https://stripe.com/docs/stripe-cli"
    exit 1
fi

echo ""
echo "=============================================="
echo "Test Flow Checklist"
echo "=============================================="
echo ""
echo "[ ] 1. Start Stripe webhook listener:"
echo "       stripe listen --forward-to http://localhost/webhooks/stripe.php"
echo ""
echo "[ ] 2. Create test escrow:"
echo "       - Login as buyer"
echo "       - Post project"
echo "       - Submit bid as seller"
echo "       - Accept bid"
echo "       - Fund escrow"
echo ""
echo "[ ] 3. Verify payment_intent.succeeded webhook:"
echo "       mysql -u root -p jacob_db -e \"SELECT * FROM stripe_webhook_events WHERE event_type='payment_intent.succeeded' ORDER BY created_at DESC LIMIT 1;\""
echo ""
echo "[ ] 4. Check escrow status changed to 'funded':"
echo "       mysql -u root -p jacob_db -e \"SELECT id, status, payment_status FROM escrow ORDER BY id DESC LIMIT 1;\""
echo ""
echo "[ ] 5. Ensure seller has stripe_account_id (required for payout):"
echo "       mysql -u root -p jacob_db -e \"UPDATE users SET stripe_account_id='acct_test123' WHERE role='seller' LIMIT 1;\""
echo ""
echo "[ ] 6. Admin releases escrow:"
echo "       - Login as admin"
echo "       - Go to admin_transactions.php"
echo "       - Click 'Release' on funded escrow"
echo "       - Enter reason"
echo ""
echo "[ ] 7. Verify state changed to 'release_requested':"
echo "       mysql -u root -p jacob_db -e \"SELECT id, status, stripe_payout_id FROM escrow ORDER BY id DESC LIMIT 1;\""
echo ""
echo "[ ] 8. Trigger transfer.paid webhook (in Stripe CLI terminal):"
echo "       stripe trigger transfer.paid"
echo ""
echo "[ ] 9. Verify final state is 'released':"
echo "       mysql -u root -p jacob_db -e \"SELECT id, status, released_at FROM escrow ORDER BY id DESC LIMIT 1;\""
echo ""
echo "[ ] 10. Check audit trail:"
echo "       mysql -u root -p jacob_db -e \"SELECT * FROM payment_transactions WHERE transaction_type='payout' ORDER BY id DESC LIMIT 1;\""
echo "       mysql -u root -p jacob_db -e \"SELECT * FROM admin_actions ORDER BY id DESC LIMIT 1;\""
echo "       mysql -u root -p jacob_db -e \"SELECT * FROM escrow_state_transitions ORDER BY id DESC LIMIT 5;\""
echo ""
echo "=============================================="
echo "Failure Testing"
echo "=============================================="
echo ""
echo "[ ] 1. Create another funded escrow"
echo "[ ] 2. Admin clicks 'Release'"
echo "[ ] 3. Trigger transfer.failed:"
echo "       stripe trigger transfer.failed"
echo "[ ] 4. Verify escrow rolled back to 'funded':"
echo "       mysql -u root -p jacob_db -e \"SELECT id, status FROM escrow WHERE status='funded';\""
echo ""
echo "=============================================="
echo "Quick Verification Queries"
echo "=============================================="
echo ""
echo "# Count escrows by status:"
echo "mysql -u root -p jacob_db -e \"SELECT status, COUNT(*) as count FROM escrow GROUP BY status;\""
echo ""
echo "# Recent state transitions:"
echo "mysql -u root -p jacob_db -e \"SELECT * FROM escrow_state_transitions ORDER BY created_at DESC LIMIT 10;\""
echo ""
echo "# Recent webhook events:"
echo "mysql -u root -p jacob_db -e \"SELECT event_type, processed, created_at FROM stripe_webhook_events ORDER BY created_at DESC LIMIT 10;\""
echo ""
echo "# Payout transactions:"
echo "mysql -u root -p jacob_db -e \"SELECT id, transaction_type, status, amount FROM payment_transactions WHERE transaction_type='payout';\""
echo ""
