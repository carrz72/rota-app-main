<?php
/**
 * Upload Chat File - Handle file uploads for chat
 * Supports: images, PDFs, Word docs, Excel sheets
 */

session_start();
require_once '../includes/db.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
header('Content-Type: application/json');

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }
    
    $file = $_FILES['file'];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . $file['error']);
    }
    
    // Validate file size (5MB max)
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large. Maximum size is 5MB.');
    }
    
    // Get file extension
    $fileName = $file['name'];
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    
    // Allowed file types
    $allowedTypes = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',  // Images
        'pdf',                                 // PDFs
        'doc', 'docx',                         // Word
        'xls', 'xlsx',                         // Excel
        'txt', 'csv',                          // Text
        'mp3', 'wav', 'm4a'                    // Audio (voice notes)
    ];
    
    if (!in_array($fileExt, $allowedTypes)) {
        throw new Exception('File type not allowed. Allowed types: ' . implode(', ', $allowedTypes));
    }
    
    // Create upload directory structure: uploads/chat/YYYY/MM/
    $uploadBasePath = '../uploads/chat';
    $yearMonth = date('Y/m');
    $uploadPath = "$uploadBasePath/$yearMonth";
    
    if (!file_exists($uploadPath)) {
        if (!mkdir($uploadPath, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }
    
    // Generate unique filename
    $uniqueId = uniqid() . '_' . time();
    $safeFileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($fileName, PATHINFO_FILENAME));
    $newFileName = $uniqueId . '_' . $safeFileName . '.' . $fileExt;
    $targetPath = "$uploadPath/$newFileName";
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Failed to save file');
    }
    
    // Generate thumbnail for images
    $thumbnail = null;
    $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (in_array($fileExt, $imageTypes)) {
        $thumbnailPath = "$uploadPath/thumb_$newFileName";
        
        try {
            // Create thumbnail (200x200)
            $image = null;
            switch ($fileExt) {
                case 'jpg':
                case 'jpeg':
                    $image = imagecreatefromjpeg($targetPath);
                    break;
                case 'png':
                    $image = imagecreatefrompng($targetPath);
                    break;
                case 'gif':
                    $image = imagecreatefromgif($targetPath);
                    break;
                case 'webp':
                    $image = imagecreatefromwebp($targetPath);
                    break;
            }
            
            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);
                
                // Calculate thumbnail dimensions
                $thumbWidth = 200;
                $thumbHeight = 200;
                
                if ($width > $height) {
                    $thumbHeight = floor($height * ($thumbWidth / $width));
                } else {
                    $thumbWidth = floor($width * ($thumbHeight / $height));
                }
                
                // Create thumbnail
                $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
                
                // Preserve transparency for PNG/GIF
                if (in_array($fileExt, ['png', 'gif'])) {
                    imagealphablending($thumb, false);
                    imagesavealpha($thumb, true);
                }
                
                imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
                
                // Save thumbnail
                switch ($fileExt) {
                    case 'jpg':
                    case 'jpeg':
                        imagejpeg($thumb, $thumbnailPath, 85);
                        break;
                    case 'png':
                        imagepng($thumb, $thumbnailPath);
                        break;
                    case 'gif':
                        imagegif($thumb, $thumbnailPath);
                        break;
                    case 'webp':
                        imagewebp($thumb, $thumbnailPath);
                        break;
                }
                
                imagedestroy($image);
                imagedestroy($thumb);
                
                $thumbnail = "/uploads/chat/$yearMonth/thumb_$newFileName";
            }
        } catch (Exception $e) {
            // Thumbnail generation failed, but file is uploaded
            error_log("Thumbnail generation failed: " . $e->getMessage());
        }
    }
    
    // Generate public URL
    $fileUrl = "/uploads/chat/$yearMonth/$newFileName";
    
    // Determine file type for frontend
    $fileType = 'file';
    if (in_array($fileExt, $imageTypes)) {
        $fileType = 'image';
    } elseif ($fileExt === 'pdf') {
        $fileType = 'pdf';
    } elseif (in_array($fileExt, ['doc', 'docx'])) {
        $fileType = 'document';
    } elseif (in_array($fileExt, ['xls', 'xlsx'])) {
        $fileType = 'spreadsheet';
    } elseif (in_array($fileExt, ['mp3', 'wav', 'm4a'])) {
        $fileType = 'audio';
    }
    
    // Return file info
    echo json_encode([
        'success' => true,
        'file' => [
            'url' => $fileUrl,
            'thumbnail' => $thumbnail,
            'name' => $fileName,
            'size' => $file['size'],
            'type' => $fileType,
            'extension' => $fileExt
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
