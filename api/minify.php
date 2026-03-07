<?php
/**
 * Minify CSS on-the-fly with disk cache
 * Usage: /api/minify.php?f=assets/css/style.css
 *
 * JS is served as-is (gzip via .htaccess handles compression already)
 * CSS regex minification is safe; JS minification via regex risks breaking code.
 */

$allowed = [
    'assets/css/style.css',
    'assets/js/main.js',
];

$file = trim($_GET['f'] ?? '');

if (!in_array($file, $allowed, true)) {
    http_response_code(403);
    exit('Forbidden');
}

$root     = dirname(__DIR__);
$fullPath = $root . '/' . $file;

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('Not found');
}

$ext      = pathinfo($file, PATHINFO_EXTENSION);
$cacheDir = $root . '/cache/minify/';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);

$cacheFile = $cacheDir . md5($file) . '.' . $ext;
$srcMtime  = filemtime($fullPath);

if (is_file($cacheFile) && filemtime($cacheFile) >= $srcMtime) {
    $content = file_get_contents($cacheFile);
} else {
    $content = file_get_contents($fullPath);
    // Only minify CSS — JS is served as-is (gzip already compresses it)
    if ($ext === 'css') {
        $content = minifyCSS($content);
        file_put_contents($cacheFile, $content);
    }
}

$mime = ($ext === 'css') ? 'text/css' : 'application/javascript';
header("Content-Type: $mime; charset=utf-8");
header('Cache-Control: public, max-age=2592000');
header('Vary: Accept-Encoding');

$etag = '"' . md5($content) . '"';
header("ETag: $etag");
if (
    isset($_SERVER['HTTP_IF_NONE_MATCH']) &&
    trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag
) {
    http_response_code(304);
    exit;
}

echo $content;

function minifyCSS(string $css): string {
    // Remove /* comments */
    $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
    // Remove whitespace around special characters
    $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $css);
    // Collapse whitespace
    $css = preg_replace('/\s+/', ' ', $css);
    // Remove last semicolons before }
    $css = str_replace(';}', '}', $css);
    return trim($css);
}
