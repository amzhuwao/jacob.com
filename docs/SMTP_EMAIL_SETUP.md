# SMTP Email Configuration Guide

## What Changed

The email system has been upgraded from using PHP's `mail()` function to **SMTP with authentication**. This provides:

- ✅ Reliable email delivery
- ✅ Proper sender authentication (prevents spam filtering)
- ✅ Works with real email accounts (info@leonom.tech)
- ✅ No local mail server required

## Setup Instructions

### Step 1: Get Your SMTP Credentials

You need the following information from your email provider:

**For cPanel Hosting:**

- **SMTP Host:** `mail.leonom.tech` (usually mail.yourdomain.com)
- **SMTP Port:** `587` (TLS) or `465` (SSL)
- **Username:** `info@leonom.tech`
- **Password:** Your email account password

**For Gmail:**

- **SMTP Host:** `smtp.gmail.com`
- **SMTP Port:** `587`
- **Username:** `info@leonom.tech`
- **Password:** App Password (not regular password - must enable 2FA first)
  - Generate at: https://myaccount.google.com/apppasswords

**For Office 365:**

- **SMTP Host:** `smtp.office365.com`
- **SMTP Port:** `587`
- **Username:** `info@leonom.tech`
- **Password:** Your account password

### Step 2: Update Configuration File

Edit `/var/www/jacob.com/config/email.php`:

```php
define('SMTP_HOST', 'mail.leonom.tech');  // Your SMTP server
define('SMTP_PORT', 587);                  // 587 for TLS
define('SMTP_ENCRYPTION', 'tls');          // 'tls' or 'ssl'
define('SMTP_USERNAME', 'info@leonom.tech');
define('SMTP_PASSWORD', 'YOUR_PASSWORD_HERE');  // ← ADD YOUR PASSWORD
define('SMTP_FROM_EMAIL', 'info@leonom.tech');
define('SMTP_FROM_NAME', 'Jacob Marketplace');
```

**IMPORTANT:**

- Never commit passwords to git
- Use strong passwords
- For production, consider using environment variables

### Step 3: Test Email Sending

Run this test script:

```bash
php -r "
require_once '/var/www/jacob.com/config/database.php';
require_once '/var/www/jacob.com/services/EmailService.php';

\$emailService = new EmailService(\$pdo);

// Replace with your actual email for testing
\$result = \$emailService->projectAccepted(1, 1, 'Test Project', 'Test Buyer');

echo 'Email test result: ' . (\$result ? 'SUCCESS' : 'FAILED') . PHP_EOL;
"
```

Check your error logs for details:

```bash
tail -f /var/log/php-error.log  # or wherever PHP logs are
```

### Step 4: Common SMTP Ports

| Port | Encryption | Usage                                   |
| ---- | ---------- | --------------------------------------- |
| 587  | TLS        | **Recommended** - Most widely supported |
| 465  | SSL        | Legacy SSL, still widely used           |
| 25   | None       | Unencrypted, often blocked by ISPs      |
| 2525 | TLS        | Alternative to 587 (some providers)     |

### Step 5: Troubleshooting

**"Failed to connect to SMTP server"**

- Check firewall allows outbound connections on port 587/465
- Verify SMTP_HOST is correct
- Try telnet: `telnet mail.leonom.tech 587`

**"SMTP authentication failed"**

- Verify username and password are correct
- For Gmail: Must use App Password, not regular password
- Check if account has SMTP enabled

**"Connection timeout"**

- Port might be blocked by firewall
- Try alternative port (2525 instead of 587)
- Check with hosting provider

**Emails go to spam**

- Add SPF record to DNS: `v=spf1 a mx ~all`
- Add DKIM signing (optional)
- Verify sender domain matches SMTP server

### Step 6: Security Best Practices

**Option 1: Use environment variables (Recommended)**

```php
// In config/email.php
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
```

Then set in server:

```bash
export SMTP_PASSWORD='your-password'
```

**Option 2: Separate config file**

Create `config/email.local.php` (add to .gitignore):

```php
<?php
define('SMTP_PASSWORD', 'your-password-here');
```

Update `config/email.php`:

```php
// Load local config if exists
if (file_exists(__DIR__ . '/email.local.php')) {
    require_once __DIR__ . '/email.local.php';
}
```

### Step 7: Verify Setup

Check that emails are logged:

```bash
grep "Email sent" /var/log/php-error.log
```

Expected output:

```
[05-Jan-2026 11:30:15] Email sent to buyer@example.com: Bid Accepted! (SMTP Success)
```

## File Changes

- ✅ **config/email.php** - SMTP configuration
- ✅ **services/EmailService.php** - Updated to use SMTP instead of mail()
- ⚠️ **All email integrations** - No changes needed, same API

## Current Status

⚠️ **Action Required:** Add your SMTP password to `config/email.php`

Until configured, emails will fail with:

```
SMTP password not configured. Email to user@example.com not sent.
```

Once configured, all 8 email types will work:

- Project accepted (buyer & seller)
- Work delivered
- Escrow released (buyer & seller)
- Review submitted
- Dispute opened & resolved
