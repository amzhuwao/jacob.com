<?php
require_once "../includes/auth.php";
require_once "../config/database.php";

if ($_SESSION['role'] !== 'buyer') {
    die("Access denied");
}

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $budget = trim($_POST['budget'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $timeline = trim($_POST['timeline'] ?? '');

    // Validation
    if (empty($title)) {
        $errors[] = 'Project title is required';
    } elseif (strlen($title) < 5) {
        $errors[] = 'Project title must be at least 5 characters';
    }

    if (empty($description)) {
        $errors[] = 'Project description is required';
    } elseif (strlen($description) < 20) {
        $errors[] = 'Description must be at least 20 characters';
    }

    if (empty($budget)) {
        $errors[] = 'Budget is required';
    } elseif (!is_numeric($budget) || $budget <= 0) {
        $errors[] = 'Budget must be a positive number';
    }

    if (empty($category)) {
        $errors[] = 'Please select a category';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO projects (buyer_id, title, description, budget, category, timeline, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'open', NOW())"
            );

            $stmt->execute([
                $_SESSION['user_id'],
                $title,
                $description,
                $budget,
                $category,
                $timeline
            ]);

            $success = "Project posted successfully! Redirecting...";
            header("refresh:2;url=buyer.php");
        } catch (Exception $e) {
            $errors[] = "Failed to post project. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Post a Project - Jacob</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            padding: 1.5rem;
        }

        .page-container {
            width: 100%;
            max-width: 600px;
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-header {
            text-align: center;
            margin-bottom: 2rem;
            color: white;
        }

        .form-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .form-header p {
            font-size: 1rem;
            opacity: 0.95;
        }

        .form-card {
            background: white;
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }

        .success-message {
            background: #10b981;
            color: white;
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .error-messages {
            background: #ef4444;
            color: white;
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .error-messages ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .error-messages li {
            padding: 0.5rem 0;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .error-messages li:not(:last-child) {
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .form-label-required {
            color: #ef4444;
            margin-left: 0.25rem;
        }

        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e5e7eb;
            border-radius: 0.75rem;
            font-size: 1rem;
            font-family: inherit;
            transition: all 0.3s ease;
        }

        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-textarea {
            min-height: 150px;
            resize: vertical;
        }

        .input-hint {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .form-card {
                padding: 1.5rem;
            }

            .form-header h1 {
                font-size: 1.75rem;
            }

            .page-container {
                padding: 0;
            }
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }

        .btn {
            flex: 1;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background: #f3f4f6;
            color: #1f2937;
            border: 2px solid #e5e7eb;
        }

        .btn-secondary:hover {
            background: #e5e7eb;
            border-color: #d1d5db;
        }

        .character-count {
            font-size: 0.85rem;
            color: #9ca3af;
            margin-top: 0.5rem;
            text-align: right;
        }

        .budget-hint {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 0.5rem;
        }

        .form-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 1rem;
            margin-top: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #f3f4f6;
        }

        .form-section-title:first-child {
            margin-top: 0;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: white;
            text-decoration: none;
            margin-bottom: 1.5rem;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .back-link:hover {
            gap: 0.75rem;
        }

        @media (max-width: 640px) {
            body {
                padding: 1rem;
            }

            .form-header h1 {
                font-size: 1.5rem;
            }

            .form-header p {
                font-size: 0.95rem;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="page-container">
        <a href="/dashboard/buyer.php" class="back-link">← Back to Dashboard</a>

        <div class="form-header">
            <h1>📋 Post Your Project</h1>
            <p>Describe your project and budget to attract top freelancers</p>
        </div>

        <div class="form-card">
            <?php if (!empty($success)): ?>
                <div class="success-message">
                    <span>✓</span>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="error-messages">
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li>
                                <span>✗</span>
                                <span><?php echo htmlspecialchars($error); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="projectForm">
                <!-- Project Details Section -->
                <div class="form-section-title">Project Details</div>

                <div class="form-group">
                    <label class="form-label">
                        Project Title
                        <span class="form-label-required">*</span>
                    </label>
                    <input
                        type="text"
                        name="title"
                        class="form-input"
                        placeholder="e.g., Build a modern website for my business"
                        maxlength="100"
                        required
                        value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                    <div class="input-hint">Be specific about what you need</div>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Project Description
                        <span class="form-label-required">*</span>
                    </label>
                    <textarea
                        name="description"
                        class="form-textarea"
                        placeholder="Describe your project in detail. What are your requirements? What should the final deliverable look like?"
                        required
                        onkeyup="updateCharCount(this)"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    <div class="character-count">
                        <span id="charCount">0</span> / 5000 characters
                    </div>
                    <div class="input-hint">Provide as much detail as possible to get quality bids</div>
                </div>

                <!-- Budget & Timeline Section -->
                <div class="form-section-title">Budget & Timeline</div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">
                            Budget (USD)
                            <span class="form-label-required">*</span>
                        </label>
                        <input
                            type="number"
                            name="budget"
                            class="form-input"
                            placeholder="0.00"
                            step="0.01"
                            min="0"
                            required
                            value="<?php echo htmlspecialchars($_POST['budget'] ?? ''); ?>">
                        <div class="budget-hint">
                            <span>💡</span>
                            <span>Higher budgets attract more bidders</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">
                            Timeline
                            <span class="form-label-required">*</span>
                        </label>
                        <select name="timeline" class="form-select" required>
                            <option value="">Select timeline</option>
                            <option value="urgent" <?php echo ($_POST['timeline'] ?? '') === 'urgent' ? 'selected' : ''; ?>>Urgent (1-7 days)</option>
                            <option value="short" <?php echo ($_POST['timeline'] ?? '') === 'short' ? 'selected' : ''; ?>>Short (1-4 weeks)</option>
                            <option value="medium" <?php echo ($_POST['timeline'] ?? '') === 'medium' ? 'selected' : ''; ?>>Medium (1-3 months)</option>
                            <option value="flexible" <?php echo ($_POST['timeline'] ?? '') === 'flexible' ? 'selected' : ''; ?>>Flexible (No deadline)</option>
                        </select>
                    </div>
                </div>

                <!-- Category Section -->
                <div class="form-section-title">Category & Skills</div>

                <div class="form-group">
                    <label class="form-label">
                        Project Category
                        <span class="form-label-required">*</span>
                    </label>
                    <select name="category" class="form-select" required>
                        <option value="">Select a category</option>
                        <option value="web-development" <?php echo ($_POST['category'] ?? '') === 'web-development' ? 'selected' : ''; ?>>Web Development</option>
                        <option value="mobile-development" <?php echo ($_POST['category'] ?? '') === 'mobile-development' ? 'selected' : ''; ?>>Mobile Development</option>
                        <option value="ui-ux" <?php echo ($_POST['category'] ?? '') === 'ui-ux' ? 'selected' : ''; ?>>UI/UX Design</option>
                        <option value="graphic-design" <?php echo ($_POST['category'] ?? '') === 'graphic-design' ? 'selected' : ''; ?>>Graphic Design</option>
                        <option value="copywriting" <?php echo ($_POST['category'] ?? '') === 'copywriting' ? 'selected' : ''; ?>>Copywriting</option>
                        <option value="marketing" <?php echo ($_POST['category'] ?? '') === 'marketing' ? 'selected' : ''; ?>>Marketing</option>
                        <option value="data-entry" <?php echo ($_POST['category'] ?? '') === 'data-entry' ? 'selected' : ''; ?>>Data Entry</option>
                        <option value="other" <?php echo ($_POST['category'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>

                <!-- Form Actions -->
                <div class="form-actions">
                    <a href="/dashboard/buyer.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <span>✓</span>
                        <span>Post Project</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function updateCharCount(textarea) {
            const count = textarea.value.length;
            const maxLength = 5000;
            document.getElementById('charCount').textContent = Math.min(count, maxLength);

            if (count > maxLength) {
                textarea.value = textarea.value.substring(0, maxLength);
            }
        }

        // Initialize character count on page load
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.querySelector('.form-textarea');
            if (textarea) {
                updateCharCount(textarea);
            }
        });

        // Form validation on submit
        document.getElementById('projectForm').addEventListener('submit', function(e) {
            const title = document.querySelector('input[name="title"]').value.trim();
            const description = document.querySelector('textarea[name="description"]').value.trim();
            const budget = document.querySelector('input[name="budget"]').value.trim();
            const category = document.querySelector('select[name="category"]').value;

            if (!title || !description || !budget || !category) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return false;
            }

            if (title.length < 5) {
                e.preventDefault();
                alert('Project title must be at least 5 characters');
                return false;
            }

            if (description.length < 20) {
                e.preventDefault();
                alert('Description must be at least 20 characters');
                return false;
            }

            if (isNaN(budget) || parseFloat(budget) <= 0) {
                e.preventDefault();
                alert('Please enter a valid budget amount');
                return false;
            }
        });
    </script>
</body>

</html>