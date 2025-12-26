<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
require_once '../includes/auth.php';
require_once '../config/database.php';

$pageTitle = 'Login';
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle login logic
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    // Validation
    if (empty($email)) {
        $errors[] = 'Email is required';
    }
    if (empty($password)) {
        $errors[] = 'Password is required';
    }

    if (empty($errors)) {
        // Check user in database
        $conn = getDbConnection();

        $stmt = $conn->prepare("SELECT id, full_name, password, role, status FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            // Verify password
            if (password_verify($password, $user['password'])) {
                // Check account status
                if ($user['status'] === 'suspended') {
                    $errors[] = 'Your account has been suspended. Please contact support.';
                } elseif ($user['status'] === 'banned') {
                    $errors[] = 'Your account has been banned and cannot access the system.';
                } elseif ($user['status'] === 'active') {
                    // Password is correct and account is active, set session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['full_name'];
                    $_SESSION['email'] = $email;
                    $_SESSION['role'] = $user['role'];

                    // Redirect to appropriate dashboard
                    if ($user['role'] === 'admin') {
                        header('Location: ../dashboard/admin_dashboard.php');
                    } else {
                        header('Location: ../dashboard/' . $user['role'] . '.php');
                    }
                    exit();
                } else {
                    $errors[] = 'Account status is unknown. Please contact support.';
                }
            } else {
                $errors[] = 'Invalid email or password';
            }
        } else {
            $errors[] = 'Invalid email or password';
        }

        $stmt->close();
        $conn->close();
    }
}

include '../includes/header.php';
?>

<div class="login-container">
    <h2>Login</h2>

    <?php if (!empty($errors)): ?>
        <div class="error-messages" style="color: red; background: #ffe6e6; padding: 10px; margin-bottom: 15px; border-radius: 5px;">
            <ul style="margin: 0; padding-left: 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit">Login</button>
    </form>
    <p>Don't have an account? <a href="register.php">Register here</a></p>
</div>

<?php include '../includes/footer.php'; ?>
