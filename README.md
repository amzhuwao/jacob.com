# Jacob - Freelance Marketplace Platform

A secure freelance marketplace connecting buyers with sellers through a bidding system with escrow protection.

## 🎯 Overview

Jacob is a web-based platform that allows clients (buyers) to post projects and freelancers (sellers) to bid on them. The system includes secure payment processing through Stripe and admin oversight for fund management.

## ✨ Features

### For Buyers (Clients)

- ✅ Post projects with descriptions and budgets
- ✅ Receive and review bids from sellers
- ✅ Accept the best bid and reject others automatically
- ✅ Secure escrow funding via Stripe
- ✅ Track project status in real-time

### For Sellers (Freelancers)

- ✅ Browse available open projects
- ✅ Submit competitive bids with custom messages
- ✅ View escrow funding status
- ✅ Get paid securely after work completion

### For Admins

- ✅ Monitor all escrow transactions
- ✅ Release funds to sellers upon completion
- ✅ Hold funds in case of disputes

## 🔄 Project Lifecycle

```
┌─────────────┐
│    OPEN     │  Buyer posts project, sellers can bid
└──────┬──────┘
       │ Buyer accepts a bid
       ▼
┌─────────────┐
│ IN PROGRESS │  Escrow created (pending)
└──────┬──────┘
       │ Buyer funds escrow via Stripe
       ▼
┌─────────────┐
│   FUNDED    │  Money held safely in escrow
└──────┬──────┘
       │ Seller completes work
       │ Admin releases funds
       ▼
┌─────────────┐
│  COMPLETED  │  Payment sent to seller
└─────────────┘
```

## 🔐 Escrow System

The escrow system protects both parties:

- **For Buyers**: Money is held securely and only released when work is verified
- **For Sellers**: Guaranteed payment once escrow is funded
- **Powered by Stripe**: Real payment processing in test/sandbox mode
- **Admin Oversight**: Neutral third-party control over fund releases

## 🛠️ Technology Stack

- **Backend**: PHP 8.x with PDO
- **Database**: MySQL
- **Payment Processing**: Stripe API (Checkout Sessions)
- **Authentication**: Session-based with role management
- **Security**: Password hashing (bcrypt), prepared statements

## 📁 Project Structure

```
root/
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
├── config/
│   ├── database.php      # Database connection
│   └── stripe.php        # Stripe API configuration
├── includes/
│   ├── header.php        # Navigation & HTML header
│   ├── footer.php        # Footer template
│   └── auth.php          # Authentication helpers
├── auth/
│   ├── login.php         # User login
│   ├── register.php      # User registration
│   └── logout.php        # Session termination
├── dashboard/
│   ├── buyer.php                  # Buyer dashboard
│   ├── seller.php                 # Seller dashboard
│   ├── admin.php                  # Admin dashboard
│   ├── buyer_post_project.php     # Project creation form
│   ├── project_view.php           # View project & bids
│   ├── accept_bid.php             # Accept seller bid
│   ├── fund_escrow.php            # Stripe checkout redirect
│   ├── fund_escrow_success.php    # Payment confirmation
│   ├── admin_escrows.php          # Escrow management
│   └── admin_escrow_action.php    # Release/hold funds
├── index.php             # Landing page
└── .htaccess             # URL rewriting & security
```

## 🗄️ Database Schema

### Users Table

```sql
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150),
  email VARCHAR(150) UNIQUE,
  password VARCHAR(255),
  role ENUM('buyer','seller','admin'),
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Projects Table

```sql
CREATE TABLE projects (
  id INT AUTO_INCREMENT PRIMARY KEY,
  buyer_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  budget DECIMAL(10,2),
  status ENUM('open','in_progress','funded','completed') DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (buyer_id) REFERENCES users(id)
);
```

### Bids Table

```sql
CREATE TABLE bids (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  seller_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  message TEXT,
  status ENUM('pending','accepted','rejected') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (seller_id) REFERENCES users(id)
);
```

### Escrow Table

```sql
CREATE TABLE escrow (
  id INT AUTO_INCREMENT PRIMARY KEY,
  project_id INT NOT NULL,
  buyer_id INT NOT NULL,
  seller_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('pending','funded','released','held') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (buyer_id) REFERENCES users(id),
  FOREIGN KEY (seller_id) REFERENCES users(id)
);
```

## ⚙️ Installation & Setup

### Prerequisites

- PHP 8.0 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- Stripe test account

### Step 1: Clone or Download

```bash
git clone https://github.com/amzhuwao/jacob.git
cd jacob
```

### Step 2: Database Setup

1. Create a MySQL database:

```sql
CREATE DATABASE jacob_db;
```

2. Import the schema (run the CREATE TABLE statements above)

3. Update database credentials in `config/database.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_password');
define('DB_NAME', 'jacob_db');
```

### Step 3: Stripe Configuration

1. Get your test keys from [Stripe Dashboard](https://dashboard.stripe.com/test/apikeys)
2. Update `config/stripe.php` with your keys:

```php
define('STRIPE_SECRET_KEY', 'sk_test_YOUR_SECRET_KEY');
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_YOUR_PUBLISHABLE_KEY');
```

**OR** set environment variables:

```bash
export STRIPE_SECRET_KEY=sk_test_YOUR_SECRET_KEY
export STRIPE_PUBLISHABLE_KEY=pk_test_YOUR_PUBLISHABLE_KEY
```

### Step 4: Web Server Configuration

- Point your web server's document root to the project folder
- Ensure `.htaccess` is enabled (for Apache)
- Set proper file permissions

### Step 5: Create Admin User

Register a user, then manually update their role in the database:

```sql
UPDATE users SET role = 'admin' WHERE email = 'admin@example.com';
```

## 🧪 Testing

### Test Payment with Stripe

Use Stripe's test card for sandbox transactions:

- **Card Number**: `4242 4242 4242 4242`
- **Expiry**: Any future date
- **CVC**: Any 3 digits
- **ZIP**: Any 5 digits

### User Roles

- **Buyer**: Can post projects, view bids, accept bids, fund escrow
- **Seller**: Can browse projects, submit bids, view escrow status
- **Admin**: Can manage all escrows, release/hold funds

## 🚀 Usage Flow

### Complete Transaction Example

1. **Buyer Registration & Project Posting**

   - Register as a buyer
   - Navigate to "Post Project"
   - Fill in title, description, and budget
   - Submit project

2. **Seller Bidding**

   - Register as a seller
   - Browse available projects
   - Click on a project to view details
   - Submit a bid with your price and message

3. **Bid Acceptance**

   - Buyer reviews all bids
   - Clicks "Accept Bid" on chosen seller
   - System automatically rejects other bids
   - Escrow record created with status "pending"

4. **Escrow Funding**

   - Buyer clicks "Fund Escrow (Prototype)" button
   - Redirected to Stripe Checkout
   - Completes payment with test card
   - Redirected back to project page
   - Escrow status changes to "funded"

5. **Work Completion & Release**
   - Seller completes the work
   - Admin logs in and goes to "Manage Escrows"
   - Reviews the project
   - Clicks "Release" to send funds to seller
   - Project marked as completed

## 🔒 Security Features

- ✅ Password hashing with bcrypt
- ✅ SQL injection prevention (prepared statements)
- ✅ XSS protection (htmlspecialchars on all output)
- ✅ Role-based access control
- ✅ Session-based authentication
- ✅ Stripe secure payment processing

## 📝 Development Phases

### ✅ Phase 1: Project Posting Foundation

- Buyers can post projects
- Projects saved to database
- Sellers can view available projects

### ✅ Phase 2: Bidding System

- Sellers can place bids
- Buyers can view all bids
- Buyers can accept one bid
- Project status management

### ✅ Phase 3: Escrow Integration

- Stripe sandbox integration
- Fund escrow functionality
- Admin release/hold logic
- Payment confirmation flow

## 🐛 Troubleshooting

### Common Issues

**"Access denied for user 'root'@'localhost'"**

- Check database credentials in `config/database.php`
- Ensure MySQL server is running

**"Stripe secret key not configured"**

- Verify Stripe keys are set in `config/stripe.php`
- Check that keys start with `sk_test_` and `pk_test_`

**"Table doesn't exist"**

- Run all CREATE TABLE statements
- Verify database name matches in config

**Checkout redirect fails**

- Ensure your server URL is accessible
- Check Stripe test mode is enabled

## 🤝 Contributing

This is a learning/prototype project. Feel free to fork and enhance!

## 📄 License

This project is open source and available for educational purposes.

## 👤 Author

Built as part of a freelance marketplace development project.

## 🔮 Future Enhancements

- [ ] Stripe webhook integration for reliable payment verification
- [ ] Email notifications (bid accepted, payment received, etc.)
- [ ] File upload for project deliverables
- [ ] Rating & review system
- [ ] Dispute resolution workflow
- [ ] Multi-milestone projects
- [ ] Real-time messaging between buyers and sellers
- [ ] Portfolio/profile pages
- [ ] Search and filtering for projects
- [ ] Dashboard analytics

---

**⚠️ Important**: This is a **test/sandbox environment**. Do NOT use live Stripe keys or real payment information until proper security auditing and production-ready features are implemented.
