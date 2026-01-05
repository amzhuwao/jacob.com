# Statistics Aggregation Cron Job Setup Guide

## Overview

The statistics aggregation system caches user performance metrics in the `user_statistics` table. This improves dashboard performance and provides a single source of truth for seller statistics.

## Files Created

### 1. `/var/www/jacob.com/scripts/update_user_statistics.php`

**Purpose:** Cron job script for periodic statistics aggregation
**Recommended Frequency:** Daily (00:00 UTC)
**What it does:**

- Aggregates metrics for all sellers
- Calculates: completed projects, earnings, ratings, review count, response rate, response time
- Updates `user_statistics` table via INSERT...ON DUPLICATE KEY UPDATE
- Logs output to `/var/log/user_stats_cron.log`

**Metrics Calculated:**

- `total_projects_completed` - Count of completed/released escrows
- `total_earnings` - Sum of released escrow amounts
- `average_rating` - ROUND(AVG(seller_reviews.rating), 2)
- `total_reviews` - COUNT of seller_reviews
- `response_rate` - Percentage of bids responded to (last 30 days)
- `average_response_time_minutes` - AVG(TIMESTAMPDIFF) for responded bids
- `profile_views` - Current profile views count from users table

### 2. `/var/www/jacob.com/dashboard/admin_update_stats.php`

**Purpose:** Admin UI endpoint to manually trigger statistics aggregation
**Access:** Admin-only (checks `$_SESSION['role'] == 'admin'`)
**Response:** JSON with execution time and seller count processed
**Use Case:** Manual refresh after bulk operations or data corrections

## Setup Instructions

### Option A: Cron Job (Automated - Recommended)

1. **Create log directory and file:**

   ```bash
   sudo touch /var/log/user_stats_cron.log
   sudo chown www-data:www-data /var/log/user_stats_cron.log
   sudo chmod 644 /var/log/user_stats_cron.log
   ```

2. **Edit crontab for web server user:**

   ```bash
   sudo crontab -e -u www-data
   ```

3. **Add cron job (runs daily at midnight UTC):**

   ```
   0 0 * * * /usr/bin/php /var/www/jacob.com/scripts/update_user_statistics.php >> /var/log/user_stats_cron.log 2>&1
   ```

4. **Verify cron is scheduled:**

   ```bash
   sudo crontab -l -u www-data | grep update_user_statistics
   ```

5. **Monitor logs:**
   ```bash
   tail -f /var/log/user_stats_cron.log
   ```

### Option B: Systemd Timer (Alternative)

Create `/etc/systemd/system/user-stats.service`:

```ini
[Unit]
Description=Jacob Marketplace User Statistics Update
After=network.target

[Service]
Type=oneshot
User=www-data
ExecStart=/usr/bin/php /var/www/jacob.com/scripts/update_user_statistics.php
StandardOutput=journal
StandardError=journal
```

Create `/etc/systemd/system/user-stats.timer`:

```ini
[Unit]
Description=Run User Statistics Update Daily

[Timer]
OnCalendar=daily
OnCalendar=*-*-* 00:00:00
Persistent=true

[Install]
WantedBy=timers.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable user-stats.timer
sudo systemctl start user-stats.timer
sudo systemctl status user-stats.timer
```

### Option C: Manual Trigger via Web UI

**Via Admin Dashboard:**

- Add button to admin panel that calls `/dashboard/admin_update_stats.php`
- Returns JSON response with execution time and count

**Via Direct PHP Call:**

```php
// From any admin context
require_once '/var/www/jacob.com/scripts/update_user_statistics.php';
```

**Via Command Line:**

```bash
php /var/www/jacob.com/scripts/update_user_statistics.php
```

## Testing

### Test 1: Manual Run

```bash
php /var/www/jacob.com/scripts/update_user_statistics.php
```

Expected output:

```
[2024-01-01 10:00:00] Starting user statistics aggregation...
[2024-01-01 10:00:00] Found 5 sellers to process
[2024-01-01 10:00:01] Successfully updated 5 seller statistics
[2024-01-01 10:00:01] User statistics aggregation completed successfully
```

### Test 2: Verify Database Updates

```sql
SELECT user_id, total_projects_completed, total_earnings, average_rating,
       response_rate, last_updated
FROM user_statistics
ORDER BY last_updated DESC
LIMIT 5;
```

### Test 3: Cron Execution

```bash
grep "update_user_statistics" /var/log/user_stats_cron.log
```

## Performance Considerations

**Execution Time:**

- ~1-2 seconds for 5 sellers
- ~5-10 seconds for 50 sellers
- ~30-60 seconds for 500+ sellers

**Database Load:**

- Minimal: Uses efficient aggregation queries with indexes
- No locks on production tables
- Reads only, single atomic write per seller

**Optimization Tips:**

- Run during low-traffic hours (recommend 00:00-02:00)
- Can adjust frequency based on seller activity
- For 1000+ sellers, consider running more frequently (every 6-12 hours)

## Integration Points

### Dashboard Usage

The following files can now use cached statistics from `user_statistics`:

1. **seller.php** - Load dashboard stats from user_statistics instead of calculating on page load
2. **view_seller.php** - Display cached profile metrics
3. **admin.php** - Show seller statistics overview in admin panel
4. **buyer.php** - Display cached ratings and review counts

### Example Updated Query:

```php
// OLD: Calculated on page load
$avgRating = $pdo->prepare("SELECT AVG(rating) FROM seller_reviews WHERE seller_id = ?");

// NEW: Use cached stat
$stats = $pdo->prepare("SELECT average_rating FROM user_statistics WHERE user_id = ?");
```

## Troubleshooting

### Cron Not Running

1. Verify crontab: `sudo crontab -l -u www-data`
2. Check web server logs: `/var/log/apache2/error.log` or `/var/log/nginx/error.log`
3. Verify PHP path: `which php`
4. Test directly: `php /var/www/jacob.com/scripts/update_user_statistics.php`

### Statistics Not Updating

1. Check log file: `tail /var/log/user_stats_cron.log`
2. Verify database connection in config/database.php
3. Check user permissions: `ls -l /var/www/jacob.com/scripts/`
4. Run manually to see errors: `php -d display_errors=1 /var/www/jacob.com/scripts/update_user_statistics.php`

### Log File Growing Too Large

Implement log rotation:

```
/var/log/user_stats_cron.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    create 644 www-data www-data
}
```

Add to `/etc/logrotate.d/apache2` (or nginx equivalent)

## Monitoring

### Create a Monitoring Script

```php
// Check last update and alert if stale
$statsStmt = $pdo->prepare(
    "SELECT MAX(last_updated) as latest FROM user_statistics"
);
$statsStmt->execute();
$last_update = $statsStmt->fetch()['latest'];

$minutes_stale = (time() - strtotime($last_update)) / 60;
if ($minutes_stale > 1500) { // More than 25 hours
    error_log("WARNING: User statistics are $minutes_stale minutes stale");
    // Send alert email or notification
}
```

## Future Enhancements

1. **Incremental Updates:** Update only changed sellers (check MAX(last_modified))
2. **Batch Processing:** Process sellers in configurable batch sizes
3. **Database Archival:** Archive historical statistics for trend analysis
4. **Real-time Updates:** Trigger stats update on key events (escrow release, new review)
5. **Performance Metrics:** Track aggregation execution time for optimization
6. **Email Notifications:** Alert admins of aggregation failures or anomalies

## Security Notes

- ✅ Script reads database credentials from config/database.php
- ✅ Cron runs as www-data user (reduced privileges)
- ✅ Log file contains only aggregation messages, no sensitive data
- ✅ Manual trigger endpoint checks admin role before execution
- ⚠️ Ensure log files are not readable from web
- ⚠️ Restrict access to scripts directory via web server config

## Success Criteria

Once implemented, the system should:

- ✅ Update all user_statistics rows daily
- ✅ Reflect accurate metrics within 24 hours of data changes
- ✅ Complete execution in under 60 seconds
- ✅ Produce daily log entries confirming execution
- ✅ Be callable manually via admin endpoint
- ✅ Handle errors gracefully without stopping other sellers' processing
