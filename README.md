# KNH Blood Donation Management System (BDMS)

A PHP/MySQL web application for Kenyatta National Hospital to manage the full lifecycle of blood donation — donor registration, eligibility screening, blood unit collection, inventory management, transfusion tracking, and donor notifications.

---

## Requirements

- PHP 8.x
- MySQL 8.x
- Apache (via XAMPP or WAMP)
- Composer (for PHPUnit and eris)

---

## Setup (XAMPP / WAMP)

### 1. Clone the repository

```bash
git clone <repository-url>
cd knh-bdms
```

### 2. Place the project in your web root

- **XAMPP**: Copy or symlink the project folder into `C:\xampp\htdocs\` (Windows) or `/opt/lampp/htdocs/` (Linux/macOS).
- **WAMP**: Copy or symlink into `C:\wamp64\www\`.

### 3. Start Apache and MySQL

Launch the XAMPP/WAMP control panel and start both the **Apache** and **MySQL** services.

### 4. Import the database schema

Open a terminal and run:

```bash
mysql -u root -p < database/schema.sql
```

This creates the `knh_bdms_db` database and all six tables with constraints.

For running tests, also create the test database:

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS knh_bdms_test_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 5. Configure environment variables

Copy the example config file and fill in your credentials:

```bash
cp config/db.example.php config/db.php
```

Edit `config/db.php` and set the values for your local environment (see [Environment Variables](#environment-variables) below).

> `config/db.php` is excluded from version control via `.gitignore` — never commit it.

### 6. Install PHP dependencies

```bash
composer install
```

### 7. Access the application

Open your browser and navigate to:

```
http://localhost/knh-bdms/
```

---

## Environment Variables

All required configuration is set in `config/db.php` (copied from `config/db.example.php`).

| Constant  | Description                                      | Example value      |
|-----------|--------------------------------------------------|--------------------|
| `DB_HOST` | MySQL server hostname                            | `localhost`        |
| `DB_USER` | MySQL username                                   | `root`             |
| `DB_PASS` | MySQL password (empty string for default XAMPP)  | `` (empty)         |
| `DB_NAME` | Main database name                               | `knh_bdms_db`      |
| `DB_PORT` | MySQL port                                       | `3306`             |

For the test suite, the same constants are used but the test bootstrap overrides `DB_NAME` to `knh_bdms_test_db`.

---

## Running Tests

```bash
# Run all tests (unit + property-based)
./vendor/bin/phpunit

# Run only unit tests
./vendor/bin/phpunit tests/Unit

# Run only property-based tests
./vendor/bin/phpunit tests/Property
```

Property-based tests use the [eris](https://github.com/giorgiosironi/eris) library and run a minimum of 100 iterations each.

---

## Project Structure

```
knh-bdms/
├── api/                    # RESTful endpoint files
│   ├── donors.php
│   ├── blood_units.php
│   ├── inventory.php
│   ├── transfusions.php
│   └── notifications.php
├── config/
│   ├── db.example.php      # Template — copy to db.php and fill in credentials
│   └── db.php              # Local credentials (gitignored)
├── database/
│   └── schema.sql          # Full database schema (run once to initialise)
├── src/                    # Business logic classes
│   ├── Auth.php
│   ├── EligibilityChecker.php
│   ├── CompatibilityEngine.php
│   ├── InventoryManager.php
│   └── NotificationService.php
├── tests/
│   ├── Unit/               # PHPUnit unit tests
│   └── Property/           # eris property-based tests
├── .gitignore
├── composer.json
└── README.md
```

---

## Security Notes

- `config/db.php` and any `.env` files are excluded from version control via `.gitignore`.
- All database queries use PDO prepared statements to prevent SQL injection.
- Staff passwords are stored as bcrypt hashes (`password_hash` / `password_verify`).
- Sessions expire after 30 minutes of inactivity.
