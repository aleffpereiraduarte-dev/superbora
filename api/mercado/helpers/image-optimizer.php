<?php
/**
 * Image Optimizer Helper
 *
 * Creates optimized variants (thumbnail, medium, webp) from uploaded images.
 * Uses GD library -- already available on this server (PHP 8.4, WebP supported).
 *
 * Usage:
 *   require_once __DIR__ . '/helpers/image-optimizer.php';
 *   $variants = optimizeImage('/var/www/html/uploads/products/photo.jpg', '/var/www/html/uploads/products');
 *   // Returns ['original' => '/uploads/products/photo.jpg', 'thumb' => '/uploads/products/thumb/photo.webp', ...]
 */

/**
 * Generate optimized image variants from a source image.
 *
 * @param string $sourcePath  Absolute path to the source image file
 * @param string $uploadsDir  Absolute path to the uploads subdirectory (e.g. /var/www/html/uploads/products)
 * @return array  Associative array with URL paths: original, thumb, medium, webp (each key may be null on failure)
 */
function optimizeImage(string $sourcePath, string $uploadsDir): array
{
    $result = [
        'original' => null,
        'thumb'    => null,
        'medium'   => null,
        'webp'     => null,
    ];

    if (!file_exists($sourcePath) || !is_file($sourcePath)) {
        error_log("[image-optimizer] Source file not found: $sourcePath");
        return $result;
    }

    // Build the URL-relative base from the absolute uploads dir
    // e.g. /var/www/html/uploads/products -> /uploads/products
    $webRoot = '/var/www/html';
    $relativeDir = str_replace($webRoot, '', $uploadsDir);
    $filename = pathinfo($sourcePath, PATHINFO_FILENAME);
    $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));

    // Original URL
    $result['original'] = $relativeDir . '/' . basename($sourcePath);

    // Load source image via GD
    $srcImage = loadImage($sourcePath);
    if (!$srcImage) {
        error_log("[image-optimizer] Failed to load image: $sourcePath");
        return $result;
    }

    $srcWidth  = imagesx($srcImage);
    $srcHeight = imagesy($srcImage);

    // --- Thumbnail: 300x300 max, saved as WebP ---
    $thumbDir = $uploadsDir . '/thumb';
    ensureDir($thumbDir);
    $thumbFilename = $filename . '.webp';
    $thumbPath = $thumbDir . '/' . $thumbFilename;

    $thumbImage = resizeImage($srcImage, $srcWidth, $srcHeight, 300, 300);
    if ($thumbImage && saveWebP($thumbImage, $thumbPath, 80)) {
        $result['thumb'] = $relativeDir . '/thumb/' . $thumbFilename;
    }
    if ($thumbImage && $thumbImage !== $srcImage) {
        imagedestroy($thumbImage);
    }

    // --- Medium: 800x800 max, saved as WebP ---
    $mediumDir = $uploadsDir . '/medium';
    ensureDir($mediumDir);
    $mediumFilename = $filename . '.webp';
    $mediumPath = $mediumDir . '/' . $mediumFilename;

    $mediumImage = resizeImage($srcImage, $srcWidth, $srcHeight, 800, 800);
    if ($mediumImage && saveWebP($mediumImage, $mediumPath, 85)) {
        $result['medium'] = $relativeDir . '/medium/' . $mediumFilename;
    }
    if ($mediumImage && $mediumImage !== $srcImage) {
        imagedestroy($mediumImage);
    }

    // --- WebP full-size conversion (if original is not already webp) ---
    if ($extension !== 'webp') {
        $webpFilename = $filename . '.webp';
        $webpPath = $uploadsDir . '/' . $webpFilename;

        if (saveWebP($srcImage, $webpPath, 85)) {
            $result['webp'] = $relativeDir . '/' . $webpFilename;
        }
    } else {
        // Already WebP -- just reference the original
        $result['webp'] = $result['original'];
    }

    imagedestroy($srcImage);

    return $result;
}

/**
 * Load an image file into a GD resource, detecting format from content.
 *
 * @param string $path  Absolute file path
 * @return \GdImage|false
 */
function loadImage(string $path): \GdImage|false
{
    $info = @getimagesize($path);
    if ($info === false) {
        return false;
    }

    $type = $info[2]; // IMAGETYPE_* constant

    return match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => @imagecreatefromwebp($path),
        default        => false,
    };
}

/**
 * Resize a GD image resource while maintaining aspect ratio.
 * Returns the original resource (not a copy) if no resize is needed.
 *
 * @param \GdImage $srcImage
 * @param int $srcWidth
 * @param int $srcHeight
 * @param int $maxWidth
 * @param int $maxHeight
 * @return \GdImage|false
 */
function resizeImage(\GdImage $srcImage, int $srcWidth, int $srcHeight, int $maxWidth, int $maxHeight): \GdImage|false
{
    $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);

    if ($ratio >= 1) {
        // Image is already smaller than target -- no resize needed
        return $srcImage;
    }

    $newWidth  = max(1, (int) round($srcWidth * $ratio));
    $newHeight = max(1, (int) round($srcHeight * $ratio));

    $dstImage = imagecreatetruecolor($newWidth, $newHeight);
    if (!$dstImage) {
        return false;
    }

    // Preserve transparency (important for PNG sources converted to WebP)
    imagealphablending($dstImage, false);
    imagesavealpha($dstImage, true);

    imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

    return $dstImage;
}

/**
 * Save a GD image as WebP. Falls back gracefully if imagewebp() is missing.
 *
 * @param \GdImage $image
 * @param string $destPath  Absolute output path
 * @param int $quality  0-100
 * @return bool
 */
function saveWebP(\GdImage $image, string $destPath, int $quality = 85): bool
{
    if (!function_exists('imagewebp')) {
        error_log("[image-optimizer] imagewebp() not available -- skipping WebP conversion");
        return false;
    }

    $saved = @imagewebp($image, $destPath, $quality);
    if ($saved) {
        @chmod($destPath, 0644);
    }

    return $saved;
}

/**
 * Create a directory if it does not exist.
 *
 * @param string $dir  Absolute directory path
 * @return void
 */
function ensureDir(string $dir): void
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        // Match the ownership of the parent directory for www-data compatibility
        $parentOwner = fileowner(dirname($dir));
        $parentGroup = filegroup(dirname($dir));
        if ($parentOwner !== false) {
            @chown($dir, $parentOwner);
        }
        if ($parentGroup !== false) {
            @chgrp($dir, $parentGroup);
        }
    }
}
