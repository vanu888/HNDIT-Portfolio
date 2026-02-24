# HNDIT Portfolio Registry

A web-based student academic portfolio system for the **Higher National Diploma in Information Technology (HNDIT)** department. It provides a centralized registry to showcase student projects, manage academic resources, and streamline content approval between staff roles.

---

## Features

- **Public Portfolio Search** — Look up any student by index number to view their published projects and media
- **Resource Library** — Browse and filter past exam papers by year, academic year, and semester
- **Role-Based Dashboard** — Separate workflows for Head of Department (HOD) and Batch Representatives (Rep)
- **Content Approval Workflow** — Reps draft/submit posts; HOD approves or rejects with feedback
- **Rich Post Editor** — Quill.js rich text editor with image/video upload and student tagging
- **Media Gallery** — Lightbox viewer with keyboard navigation for images and embedded videos
- **Bulk Student Import** — CSV upload to add students in bulk
- **Activity Audit Log** — Full log of all staff actions (HOD only)
- **Database Backup** — One-click SQL backup download (HOD only)
- **Password Reset** — Token-based email reset flow (1-hour expiry)
- **Dark Mode** — System-aware with manual toggle, persisted in `localStorage`
- **Security** — CSRF protection, prepared statements, clickjacking headers, server-side image resizing

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8+ (single-file MVC pattern) |
| Database | MySQL 5.7+ / MariaDB |
| Frontend | Tailwind CSS (CDN), Font Awesome 6, Quill.js |
| Server | Apache (XAMPP recommended) |
| Image Processing | PHP GD Library |

---

## Project Structure

```
hndit-portfolio/
├── index.php          # Main application — all routes and page rendering
├── db_connect.php     # Database connection, session init, security headers
├── functions.php      # Auth helpers, file upload, image resize, email, CSRF
├── header.php         # HTML head, navigation, modals, dark mode
├── footer.php         # Footer, global JS (lightbox/gallery)
├── database.sql       # Full schema + seed data
├── uploads/           # User-uploaded post images and videos (auto-created)
└── papers/            # Uploaded library PDF files (auto-created)

```

---

## Installation

### Prerequisites
- XAMPP (or any Apache + PHP + MySQL stack)
- PHP 8.0 or higher
- PHP GD extension enabled (for image resizing)

### Steps

**1. Clone the repository**
```bash
git clone https://github.com/vanu888/hndit-portfolio.git
```
Place the folder inside your web root (e.g. `C:/xampp/htdocs/`).

**2. Import the database**

Start MySQL in XAMPP, then run in your terminal:
```bash
C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\hndit-portfolio\database.sql
```
Or import `database.sql` via **phpMyAdmin**.

**3. Configure the database connection**

Copy the example config file and fill in your credentials:
```bash
cp db_connect.example.php db_connect.php
```
Then edit `db_connect.php`:
```php
$servername = "localhost";
$username   = "your_db_user";
$password   = "your_db_password";
$dbname     = "hndit_portfolio";
```

**4. Set folder permissions**

Ensure the `uploads/` and `papers/` directories are writable by the web server:
```bash
chmod 775 uploads/ papers/
```
On Windows with XAMPP this is handled automatically.

**5. Access the application**

Open your browser and go to:
```
http://localhost/hndit-portfolio/
```

---

## Default Login Credentials

| Role | Username | Password |
|---|---|---|
| Head of Department | `admin` | `1234` |
| Batch Representative | `rep` | `1234` |

> **Important:** Change these passwords immediately after first login via the Staff Management panel.

---

## User Roles

### Head of Department (HOD)
- Review and approve/reject posts submitted by Reps
- Manage staff accounts (update email, reset passwords)
- View full system activity log
- Download full database backup

### Batch Representative (Rep)
- Create and manage posts (title, rich description, media, student tags)
- Submit posts to HOD for review
- Manage the student registry (add, edit, delete, CSV import)
- Upload past papers to the library

---

## Pages

| URL | Access | Description |
|---|---|---|
| `?page=home` | Public | Hero section + featured posts + stats |
| `?page=portfolio&index=...` | Public | Student profile with all published posts |
| `?page=library` | Public | Filterable past papers resource library |
| `?page=about` | Public | Department info and contact details |
| `?page=login` | Public | Staff login |
| `?page=forgot_password` | Public | Request password reset email |
| `?page=dashboard` | Auth | Role-based staff dashboard |

---

## CSV Import Format

When bulk-importing students, the CSV must follow this format:

```
Index Number,Full Name,Batch Number
kan/it/2024/f/001,John Doe,2024
kan/it/2024/f/002,Jane Smith,2024
```

---

## Security Notes

- All forms are protected with **CSRF tokens**
- All database queries use **prepared statements**
- File uploads are validated by **MIME type** (not just extension)
- **Clickjacking** is prevented via `X-Frame-Options: DENY` and CSP headers
- Passwords are hashed with **`password_hash()` (bcrypt)**
- Activity logs automatically purge entries older than **1 year**

---

## Configuration Notes

- **Email:** The password reset feature uses PHP's `mail()` function. On localhost, a clickable debug link is shown instead. For production, configure your server's SMTP or replace `sendEmail()` in `functions.php` with a library like PHPMailer.
- **reCAPTCHA:** A `verifyRecaptcha()` function is provided in `functions.php`. Add your Google reCAPTCHA v3 secret key to enable it.
- **File size limit:** Maximum upload size is **50MB** per file. Adjust in `functions.php` and your `php.ini` (`upload_max_filesize`, `post_max_size`).

---

## License

This project is licensed under the [MIT License](LICENSE).  
Copyright (c) 2026 Vihanga Anuththara
