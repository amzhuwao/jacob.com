<?php

/**
 * Email Service for Jacob Marketplace
 * 
 * Handles all transactional emails for the platform
 * Supports: project events, escrow events, review notifications, disputes
 */

class EmailService
{
    private $from_email = 'info@leonom.tech';
    private $from_name = 'Jacob Marketplace';
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Send project accepted notification to seller
     */
    public function projectAccepted($seller_id, $project_id, $project_title, $buyer_name)
    {
        $seller = $this->getUserEmail($seller_id);
        if (!$seller) return false;

        $subject = "Congratulations! Your bid was accepted for \"$project_title\"";

        $body = $this->getTemplate('project_accepted', [
            'seller_name' => $seller['full_name'],
            'project_title' => $project_title,
            'buyer_name' => $buyer_name,
            'project_id' => $project_id,
            'dashboard_url' => 'https://jacob.com/dashboard/seller.php'
        ]);

        return $this->send($seller['email'], $subject, $body);
    }

    /**
     * Send project accepted confirmation to buyer
     */
    public function projectAcceptedBuyer($buyer_id, $project_id, $project_title, $seller_name, $bid_amount)
    {
        $buyer = $this->getUserEmail($buyer_id);
        if (!$buyer) return false;

        $subject = "You accepted a bid for \"$project_title\"";

        $body = $this->getTemplate('project_accepted_buyer', [
            'buyer_name' => $buyer['full_name'],
            'project_title' => $project_title,
            'seller_name' => $seller_name,
            'bid_amount' => $bid_amount,
            'project_id' => $project_id,
            'dashboard_url' => 'https://jacob.com/dashboard/buyer.php'
        ]);

        return $this->send($buyer['email'], $subject, $body);
    }

    /**
     * Send work delivered notification to buyer
     */
    public function workDelivered($buyer_id, $project_id, $project_title, $seller_name)
    {
        $buyer = $this->getUserEmail($buyer_id);
        if (!$buyer) return false;

        $subject = "Work delivered for \"$project_title\" - Action required";

        $body = $this->getTemplate('work_delivered', [
            'buyer_name' => $buyer['full_name'],
            'project_title' => $project_title,
            'seller_name' => $seller_name,
            'project_id' => $project_id,
            'action_url' => "https://jacob.com/dashboard/project_view.php?id=$project_id"
        ]);

        return $this->send($buyer['email'], $subject, $body);
    }

    /**
     * Send escrow released notification to seller
     */
    public function escrowReleased($seller_id, $project_id, $project_title, $amount)
    {
        $seller = $this->getUserEmail($seller_id);
        if (!$seller) return false;

        $subject = "Payment released! Funds for \"$project_title\" are now available";

        $body = $this->getTemplate('escrow_released', [
            'seller_name' => $seller['full_name'],
            'project_title' => $project_title,
            'amount' => number_format($amount, 2),
            'wallet_url' => 'https://jacob.com/dashboard/seller_wallet.php'
        ]);

        return $this->send($seller['email'], $subject, $body);
    }

    /**
     * Send escrow released notification to buyer
     */
    public function escrowReleasedBuyer($buyer_id, $project_id, $project_title, $seller_name)
    {
        $buyer = $this->getUserEmail($buyer_id);
        if (!$buyer) return false;

        $subject = "Payment completed for \"$project_title\"";

        $body = $this->getTemplate('escrow_released_buyer', [
            'buyer_name' => $buyer['full_name'],
            'project_title' => $project_title,
            'seller_name' => $seller_name,
            'project_id' => $project_id
        ]);

        return $this->send($buyer['email'], $subject, $body);
    }

    /**
     * Send review notification to seller
     */
    public function reviewSubmitted($seller_id, $buyer_name, $rating, $review_text)
    {
        $seller = $this->getUserEmail($seller_id);
        if (!$seller) return false;

        $stars = str_repeat('⭐', (int)$rating) . str_repeat('☆', 5 - (int)$rating);
        $subject = "$buyer_name left a " . (int)$rating . " star review";

        $body = $this->getTemplate('review_submitted', [
            'seller_name' => $seller['full_name'],
            'buyer_name' => $buyer_name,
            'stars' => $stars,
            'review_text' => htmlspecialchars($review_text),
            'profile_url' => 'https://jacob.com/dashboard/seller_profile.php'
        ]);

        return $this->send($seller['email'], $subject, $body);
    }

    /**
     * Send dispute opened notification
     */
    public function disputeOpened($user_id, $dispute_id, $project_title, $other_party_name)
    {
        $user = $this->getUserEmail($user_id);
        if (!$user) return false;

        $subject = "Dispute opened for \"$project_title\"";

        $body = $this->getTemplate('dispute_opened', [
            'user_name' => $user['full_name'],
            'project_title' => $project_title,
            'other_party' => $other_party_name,
            'dispute_id' => $dispute_id,
            'dispute_url' => "https://jacob.com/disputes/dispute_view.php?id=$dispute_id"
        ]);

        return $this->send($user['email'], $subject, $body);
    }

    /**
     * Send dispute resolved notification
     */
    public function disputeResolved($user_id, $dispute_id, $project_title, $resolution)
    {
        $user = $this->getUserEmail($user_id);
        if (!$user) return false;

        $subject = "Dispute resolved for \"$project_title\"";

        $body = $this->getTemplate('dispute_resolved', [
            'user_name' => $user['full_name'],
            'project_title' => $project_title,
            'resolution' => $resolution,
            'dispute_url' => "https://jacob.com/disputes/dispute_view.php?id=$dispute_id"
        ]);

        return $this->send($user['email'], $subject, $body);
    }

    /**
     * Get email template with variable substitution
     */
    private function getTemplate($template_name, $variables)
    {
        $templates = [
            'project_accepted' => $this->templateProjectAccepted($variables),
            'project_accepted_buyer' => $this->templateProjectAcceptedBuyer($variables),
            'work_delivered' => $this->templateWorkDelivered($variables),
            'escrow_released' => $this->templateEscrowReleased($variables),
            'escrow_released_buyer' => $this->templateEscrowReleasedBuyer($variables),
            'review_submitted' => $this->templateReviewSubmitted($variables),
            'dispute_opened' => $this->templateDisputeOpened($variables),
            'dispute_resolved' => $this->templateDisputeResolved($variables),
        ];

        return $templates[$template_name] ?? '';
    }

    // ===== EMAIL TEMPLATES =====

    private function templateProjectAccepted($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>🎉 Bid Accepted!</h1>
    </div>
    <div class="content">
        <p>Hi {$v['seller_name']},</p>
        <p>Great news! Your bid has been accepted by <strong>{$v['buyer_name']}</strong> for the project:</p>
        <h3>"{$v['project_title']}"</h3>
        <p>You can now start working on this project. The buyer is expecting quality work as discussed in your bid.</p>
        <p>
            <a href="{$v['dashboard_url']}" class="button">View Project Details</a>
        </p>
        <p>Best of luck! Please deliver the work on time and communicate with the buyer if you have any questions.</p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function templateProjectAcceptedBuyer($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2196F3; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { background: #2196F3; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>✓ Bid Accepted</h1>
    </div>
    <div class="content">
        <p>Hi {$v['buyer_name']},</p>
        <p>You have accepted a bid from <strong>{$v['seller_name']}</strong> for:</p>
        <h3>"{$v['project_title']}"</h3>
        <p><strong>Bid Amount:</strong> \${$v['bid_amount']}</p>
        <p>The seller will now begin working on your project. An escrow has been created to secure the funds until you approve the completed work.</p>
        <p>
            <a href="{$v['dashboard_url']}" class="button">Track Project</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function templateWorkDelivered($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #FF9800; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { background: #FF9800; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>📦 Work Delivered!</h1>
    </div>
    <div class="content">
        <p>Hi {$v['buyer_name']},</p>
        <p><strong>{$v['seller_name']}</strong> has delivered the work for:</p>
        <h3>"{$v['project_title']}"</h3>
        <p>Please review the delivered work and either approve it or request revisions. You have 7 days to review.</p>
        <p>
            <a href="{$v['action_url']}" class="button">Review Work</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function templateEscrowReleased($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>💰 Payment Released!</h1>
    </div>
    <div class="content">
        <p>Hi {$v['seller_name']},</p>
        <p>Great news! Your payment has been released and is now in your wallet.</p>
        <h3>Amount: \${$v['amount']}</h3>
        <p>You can now withdraw this amount to your bank account or use it for other projects on Jacob Marketplace.</p>
        <p>
            <a href="{$v['wallet_url']}" class="button">View Wallet</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function templateEscrowReleasedBuyer($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>✓ Payment Confirmed</h1>
    </div>
    <div class="content">
        <p>Hi {$v['buyer_name']},</p>
        <p>Your payment to <strong>{$v['seller_name']}</strong> for "{$v['project_title']}" has been processed and released.</p>
        <p>Thank you for using Jacob Marketplace! If you're satisfied with the work, please consider leaving a review.</p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function templateReviewSubmitted($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #FFC107; color: #333; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { background: #FFC107; color: #333; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⭐ New Review!</h1>
    </div>
    <div class="content">
        <p>Hi {$v['seller_name']},</p>
        <p><strong>{$v['buyer_name']}</strong> left you a review!</p>
        <h3>{$v['stars']}</h3>
        <blockquote style="background: #fff; padding: 15px; border-left: 4px solid #FFC107;">
            {$v['review_text']}
        </blockquote>
        <p>
            <a href="{$v['profile_url']}" class="button">View Your Profile</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function templateDisputeOpened($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #F44336; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { background: #F44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⚠️ Dispute Opened</h1>
    </div>
    <div class="content">
        <p>Hi {$v['user_name']},</p>
        <p>A dispute has been opened for the project:</p>
        <h3>"{$v['project_title']}"</h3>
        <p>Other party: <strong>{$v['other_party']}</strong></p>
        <p>Our support team will review the case and work toward a resolution. Please provide any additional evidence or information if needed.</p>
        <p>
            <a href="{$v['dispute_url']}" class="button">View Dispute Details</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function templateDisputeResolved($v)
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Arial, sans-serif; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #4CAF50; color: white; padding: 20px; text-align: center; }
        .content { padding: 20px; background: #f9f9f9; }
        .button { background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        .footer { text-align: center; color: #999; font-size: 12px; margin-top: 20px; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>✓ Dispute Resolved</h1>
    </div>
    <div class="content">
        <p>Hi {$v['user_name']},</p>
        <p>The dispute for "{$v['project_title']}" has been resolved.</p>
        <h3>Resolution: {$v['resolution']}</h3>
        <p>Thank you for working with us to resolve this matter.</p>
        <p>
            <a href="{$v['dispute_url']}" class="button">View Resolution Details</a>
        </p>
    </div>
    <div class="footer">
        <p>&copy; 2026 Jacob Marketplace. All rights reserved.</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    /**
     * Get user email from database
     */
    private function getUserEmail($user_id)
    {
        $stmt = $this->pdo->prepare("SELECT id, email, full_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Send email using PHP mail()
     */
    private function send($to, $subject, $body)
    {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$this->from_name} <{$this->from_email}>\r\n";
        $headers .= "Reply-To: {$this->from_email}\r\n";

        $result = mail($to, $subject, $body, $headers);

        // Log email sends for debugging
        error_log("Email sent to $to: $subject (Result: " . ($result ? 'success' : 'failed') . ")");

        return $result;
    }
}
