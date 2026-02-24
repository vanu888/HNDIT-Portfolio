<?php
/**
 * Authentication Helpers
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isHod() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'hod';
}

function isRep() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'rep';
}

/**
 * Security: CSRF Token Verification
 */
function checkCsrf() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('<div style="color:red;padding:20px;text-align:center;font-family:sans-serif;">
                <h2>Security Violation</h2>
                <p>Invalid CSRF Token. Please refresh the page and try again.</p>
             </div>');
    }
}

/**
 * UX: Session-Based Flash Messages
 */
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Performance: Server-Side Image Resizing
 * Resizes images to max 1200px width to save bandwidth.
 */
function resizeImage($file_path, $target_width = 1200) {
    if (!extension_loaded('gd')) return; // Skip if GD library is missing

    list($width, $height, $type) = getimagesize($file_path);
    if ($width <= $target_width) return; // No need to resize if small enough
    
    $ratio = $target_width / $width;
    $target_height = $height * $ratio;
    
    $src = null;
    switch($type) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($file_path); break;
        case IMAGETYPE_PNG: $src = imagecreatefrompng($file_path); break;
        case IMAGETYPE_GIF: $src = imagecreatefromgif($file_path); break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($file_path); break;
    }
    
    if (!$src) return;
    
    $dst = imagecreatetruecolor($target_width, $target_height);
    
    // Maintain transparency for PNG/WEBP
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_WEBP) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 255, 255, 255, 127);
        imagefilledrectangle($dst, 0, 0, $target_width, $target_height, $transparent);
    }
    
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $target_width, $target_height, $width, $height);
    
    // Save over original
    switch($type) {
        case IMAGETYPE_JPEG: imagejpeg($dst, $file_path, 85); break; // 85% Quality
        case IMAGETYPE_PNG: imagepng($dst, $file_path, 8); break;
        case IMAGETYPE_GIF: imagegif($dst, $file_path); break;
        case IMAGETYPE_WEBP: imagewebp($dst, $file_path, 85); break;
    }
    
    imagedestroy($src);
    imagedestroy($dst);
}

/**
 * Text Formatting: Auto-Linker
 * Converts URLs in text to clickable <a> tags
 */
function makeLinksClickable($text) {
    // Regex to find URLs
    $pattern = '/\b((https?:\/\/|www\.)[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|\/)))/';
    
    return preg_replace_callback($pattern, function($matches) {
        $url = $matches[0];
        $display = $url;
        
        // Ensure protocol exists for href
        if (strpos($url, 'http') !== 0) {
            $url = 'http://' . $url;
        }
        
        // Shorten long URLs for display
        if (strlen($display) > 35) {
            $display = substr($display, 0, 30) . '...';
        }

        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="text-blue-600 hover:underline font-medium" title="Open Link">' . $display . '</a>';
    }, htmlspecialchars($text)); // Run htmlspecialchars first to prevent XSS
}

/**
 * Email Sender (SMTP / Mail Wrapper)
 */
function sendEmail($to, $subject, $message) {
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: HNDIT Portfolio <no-reply@hndit-portfolio.com>' . "\r\n";
    
    $body = "
    <html>
    <head>
        <style>
            .container { font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 5px; }
            .btn { display: inline-block; padding: 10px 20px; background-color: #1e3a8a; color: white; text-decoration: none; border-radius: 4px; }
            .footer { margin-top: 20px; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='container'>
            <h2>HNDIT Portfolio Notification</h2>
            <p>$message</p>
            <div class='footer'>This is an automated message. Please do not reply.</div>
        </div>
    </body>
    </html>";

    return mail($to, $subject, $body, $headers);
}

/**
 * Security: Google reCAPTCHA v3 Verification
 */
function verifyRecaptcha($responseToken) {
    $secretKey = "YOUR_SECRET_KEY_HERE"; 
    if ($secretKey === "YOUR_SECRET_KEY_HERE") return true; 

    $url = "https://www.google.com/recaptcha/api/siteverify";
    $data = [
        'secret' => $secretKey,
        'response' => $responseToken,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    
    $context  = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    $json = json_decode($result);
    
    return $json->success && $json->score >= 0.5;
}

/**
 * Robust File Upload Function
 */
function uploadFile($file, $target_dir = "uploads/", $category = 'all') {
    if (!file_exists($target_dir)) {
        if (!mkdir($target_dir, 0777, true)) {
            return ['error' => 'Failed to create upload directory.'];
        }
    }
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Unknown upload error';
        switch ($file['error']) {
            case UPLOAD_ERR_INI_SIZE: 
            case UPLOAD_ERR_FORM_SIZE: 
                $msg = 'File exceeds server size limit.'; break;
            case UPLOAD_ERR_PARTIAL: $msg = 'File only partially uploaded.'; break;
            case UPLOAD_ERR_NO_FILE: $msg = 'No file was uploaded.'; break;
        }
        return ['error' => $msg];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    
    $images = ['image/jpeg' => 'image', 'image/png' => 'image', 'image/gif' => 'image', 'image/webp' => 'image'];
    $videos = ['video/mp4' => 'video', 'video/webm' => 'video', 'video/quicktime' => 'video', 'video/x-matroska' => 'video'];
    $docs = ['application/pdf' => 'pdf'];

    $allowedMimes = [];
    if ($category === 'post_media') {
        $allowedMimes = array_merge($images, $videos); 
    } else {
        $allowedMimes = array_merge($images, $videos, $docs);
    }
    
    if (!array_key_exists($mime, $allowedMimes)) {
        if ($category === 'post_media' && $mime === 'application/pdf') {
            return ['error' => 'PDFs are not allowed in Posts. Please upload them to the Library.'];
        }
        return ['error' => "Invalid file type ($mime)."];
    }
    
    if ($file['size'] > 50000000) {
        return ['error' => 'File too large (Max 50MB).'];
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = time() . "_" . uniqid() . "." . $ext;
    $target_file = $target_dir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        // Auto-resize if it's an image
        if (array_key_exists($mime, $images)) {
            resizeImage($target_file);
        }
        return ['path' => $target_file, 'type' => $allowedMimes[$mime]];
    }
    
    return ['error' => 'Failed to move uploaded file.'];
}
?>
