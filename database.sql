DROP DATABASE IF EXISTS hndit_portfolio;
CREATE DATABASE hndit_portfolio;
USE hndit_portfolio;

-- 1. Users Table (Staff)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('hod', 'rep') NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) DEFAULT NULL UNIQUE
);

-- 2. Students Table
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    index_number VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    batch_number INT NOT NULL
);

-- 3. Posts Table (Main Post Details)
CREATE TABLE posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    is_featured BOOLEAN DEFAULT 0,
    status ENUM('draft', 'pending', 'published', 'rejected') DEFAULT 'draft',
    rejection_message TEXT,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- 4. Post Media (Multiple Images/Videos per post)
CREATE TABLE post_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    post_id INT NOT NULL,
    media_type ENUM('image', 'video', 'pdf') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE
);

-- 5. Post Tags (Link Students to Posts)
CREATE TABLE post_tags (
    post_id INT,
    student_id INT,
    PRIMARY KEY (post_id, student_id),
    FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- 6. Past Papers (Library)
CREATE TABLE papers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    subject VARCHAR(100) NOT NULL,
    exam_year INT NOT NULL,
    academic_year TINYINT NOT NULL DEFAULT 1,
    semester TINYINT NOT NULL DEFAULT 1,
    file_path VARCHAR(255) NOT NULL,
    uploaded_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- 7. Activity Logs
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action VARCHAR(50) NOT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- 8. Password Resets
CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- SEED DATA
-- Default HOD: username 'admin', password '1234'
-- Default Rep: username 'rep', password '1234'
-- Hash generated with: password_hash('1234', PASSWORD_DEFAULT)
INSERT INTO users (username, password, role, name) VALUES 
('admin', '$2y$10$ufgv75QNrs4fDaUSHZcK1u0KWUtFVLU5OBEs9brTcCwDIUs5KjZyS', 'hod', 'Head of Dept'),
('rep', '$2y$10$ufgv75QNrs4fDaUSHZcK1u0KWUtFVLU5OBEs9brTcCwDIUs5KjZyS', 'rep', 'Batch Rep 2025');

INSERT INTO students (index_number, full_name, batch_number) VALUES 
('kan/it/2021/f/001', 'John Doe', 2324);
