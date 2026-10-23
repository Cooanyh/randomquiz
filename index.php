<?php
// 强制不缓存（反向代理和浏览器）
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// 输出 index.html 内容
$htmlFile = __DIR__ . '/index.html';
if (file_exists($htmlFile)) {
    readfile($htmlFile);
} else {
    http_response_code(500);
    echo 'Internal Server Error';
}
