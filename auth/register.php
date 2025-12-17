<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once '../includes/auth.php';
require_once '../config/database.php';

$pageTitle = 'Register';
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle registration logic
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'buyer';

    // Validation
    if (empty($name)) {
        $errors[] = 'Name is required';
    }
    if (empty($email)) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }

    if (empty($errors)) {
        // Insert into database
        $conn = getDbConnection();

        // Check if email already exists
        $checkEmail = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $checkEmail->bind_param("s", $email);
        $checkEmail->execute();
        $result = $checkEmail->get_result();

        if ($result->num_rows > 0) {
            $errors[] = 'Email already registered';
        } else {
            // Hash password and insert user
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("ssss", $name, $email, $hashedPassword, $role);

            if ($stmt->execute()) {
                $success = 'Registration successful! You can now login.';
                // Clear form data after success
                $_POST = [];
            } else {
                $errors[] = 'Error: ' . $stmt->error;
            }
            $stmt->close();
        }
        $checkEmail->close();
        $conn->close();
    }
}

include '../includes/header.php';
?>

<div class="register-container">
    <h2>Register</h2>

    <?php if (!empty($errors)): ?>
        <div class="error-messages" style="color: red; background: #ffe6e6; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="success-message" style="color: green; background: #e6ffe6; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="text" name="name" placeholder="Full Name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
        <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        <input type="password" name="password" placeholder="Password" required>
        <select name="role">
            <option value="buyer" <?php echo (($_POST['role'] ?? 'buyer') === 'buyer') ? 'selected' : ''; ?>>Buyer</option>
            <option value="seller" <?php echo (($_POST['role'] ?? '') === 'seller') ? 'selected' : ''; ?>>Seller</option>
        </select>
        <button type="submit">Register</button>
    </form>
    <p>Already have an account? <a href="login.php">Login here</a></p>
</div>

<?php include '../includes/footer.php'; ?>