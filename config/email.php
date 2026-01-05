<?php

/**
 * Email Configuration for SMTP
 * 
 * Configure your SMTP settings here for sending emails via info@leonom.tech
 */

// SMTP Configuration
define('SMTP_HOST', 'mail.leonom.tech');  // Your SMTP server (e.g., smtp.gmail.com, mail.yourdomain.com)
define('SMTP_PORT', 587);                  // 587 for TLS, 465 for SSL, 25 for unencrypted
define('SMTP_ENCRYPTION', 'tls');          // 'tls', 'ssl', or '' for none
define('SMTP_USERNAME', 'info@leonom.tech');
define('SMTP_PASSWORD', '');               // TODO: Add your email password here
define('SMTP_FROM_EMAIL', 'info@leonom.tech');
define('SMTP_FROM_NAME', 'Jacob Marketplace');

/**
 * INSTRUCTIONS:
 * 
 * 1. Update SMTP_HOST with your mail server
 *    - cPanel hosting: Usually 'mail.yourdomain.com'
 *    - Gmail: 'smtp.gmail.com' (requires app password)
 *    - Office365: 'smtp.office365.com'
 * 
 * 2. Update SMTP_PORT based on your server
 *    - 587: TLS (most common, recommended)
 *    - 465: SSL
 *    - 25: Unencrypted (not recommended)
 * 
 * 3. Set SMTP_PASSWORD to your email account password
 *    - For Gmail: Use an App Password, not your regular password
 *    - For security, consider using environment variables instead
 * 
 * 4. Verify SMTP_ENCRYPTION matches your port
 *    - Port 587 → 'tls'
 *    - Port 465 → 'ssl'
 */
