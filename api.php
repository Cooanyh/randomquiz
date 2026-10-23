<?php
// =================================================================
// 引入安全配置
// =================================================================
chdir(__DIR__);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/content_reviewer.php';
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
// =================================================================

// 发送验证码邮件 (SMTP 配置由部署者提供)
function sendVerificationEmail($toEmail, $code)
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        $mail->addAddress($toEmail);
        $mail->addReplyTo(MAIL_FROM, MAIL_FROM_NAME);

        $mailDomain = substr(strrchr(MAIL_FROM, '@') ?: '', 1) ?: 'example.invalid';
        $mail->MessageID = '<' . bin2hex(random_bytes(16)) . '@' . $mailDomain . '>';

        $mail->isHTML(true);
        $mail->Subject = 'RandomQuiz.Pro 注册验证码';
        $mail->Body    = <<<HTML
<div style="font-family: 'Segoe UI', Arial, sans-serif; max-width: 480px; margin: 0 auto; padding: 24px; border: 1px solid #e5e7eb; border-radius: 20px; background: linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);">
    <div style="text-align: center; margin-bottom: 24px;">
        <div style="display: inline-flex; align-items: center; gap: 10px; padding: 10px 18px; border: 1px solid #dbeafe; border-radius: 999px; background: rgba(255,255,255,0.92);">
            <span style="font-size: 24px; font-weight: 700; color: #3b82f6; line-height: 1;">RandomQuiz.Pro</span>
        </div>
        <div style="color: #6b7280; font-size: 14px; margin-top: 10px;">邮箱验证</div>
    </div>
    <p style="color: #4b5563; font-size: 14px; line-height: 1.7; margin: 0 0 10px;">您好，</p>
    <p style="color: #4b5563; font-size: 14px; line-height: 1.7; margin: 0 0 16px;">您正在进行 RandomQuiz.Pro 注册，验证码为：</p>
    <div style="text-align: center; margin: 24px 0;">
        <div style="display: inline-block; background: #eff6ff; padding: 16px 32px; border-radius: 14px; font-size: 32px; font-weight: 700; letter-spacing: 8px; color: #3b82f6; border: 1px solid #bfdbfe;">{$code}</div>
    </div>
    <p style="color: #4b5563; font-size: 14px; line-height: 1.7; margin: 0 0 10px;">验证码有效期为 10 分钟，请尽快完成注册。</p>
    <p style="color: #9ca3af; font-size: 12px; line-height: 1.6; margin: 0;">如果不是您本人的操作，请忽略此邮件。</p>
    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0 16px;" />
    <p style="text-align: center; color: #9ca3af; font-size: 12px; margin: 0;">RandomQuiz.Pro · GPL-3.0</p>
</div>
HTML;

        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => '邮件发送失败: ' . $mail->ErrorInfo];
    }
}

// 清理过期验证码
function cleanExpiredVerificationCodes()
{
    $codesFile = 'data/verification_codes.json';
    if (!file_exists($codesFile)) return;
    $codes = json_decode(file_get_contents($codesFile), true) ?: [];
    $now = time();
    $changed = false;
    foreach ($codes as $email => $data) {
        if ($data['expires_at'] < $now) {
            unset($codes[$email]);
            $changed = true;
        }
    }
    if ($changed) {
        file_put_contents($codesFile, json_encode($codes, JSON_PRETTY_PRINT));
    }
}

// 用户验证辅助函数
function validateUser($username, $password, $usersFile)
{
    if (empty($username) || empty($password)) {
        return ['valid' => false, 'error' => '用户名和密码不能为空'];
    }

    $users = [];
    if (file_exists($usersFile)) {
        $users = json_decode(file_get_contents($usersFile), true) ?: [];
    }

    foreach ($users as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password_hash'])) {
            return [
                'valid' => true,
                'username' => $username,
                'role' => 'user',
                'api_key' => $user['api_key'] ?? '',
                'siliconflow_api_key' => $user['siliconflow_api_key'] ?? ''
            ];
        }
    }

    return ['valid' => false, 'error' => '用户名或密码错误'];
}

// 安全清理题库名称
function sanitizeQuizName($name)
{
    $name = str_replace(['/', '\\', '..', ':', '*', '?', '"', '<', '>', '|'], '', $name);
    $name = trim($name);
    $name = mb_substr($name, 0, 100);
    return $name;
}

// 安全清理用户名
function sanitizeUsername($username)
{
    return preg_replace('/[^a-zA-Z0-9_\x{4e00}-\x{9fa5}]/u', '', $username);
}

function buildOwnedQuizName($quizName, $sanitizedUsername)
{
    $baseName = sanitizeQuizName($quizName);
    if (empty($sanitizedUsername)) {
        return $baseName;
    }

    $suffix = '（' . $sanitizedUsername . '）';
    if (mb_substr($baseName, -mb_strlen($suffix)) === $suffix) {
        return $baseName;
    }

    return sanitizeQuizName($baseName . $suffix);
}

function extractQuizOwnerFromPath($filePath)
{
    if (preg_match('#users/([^/]+)/#', str_replace('\\', '/', $filePath), $matches)) {
        return $matches[1] ?? '';
    }
    return '';
}

function validateQuizUserRequest($data, $dataFilePath, $sessionFilePath)
{
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    $apiKey = $data['apiKey'] ?? '';
    $sessionToken = $data['sessionToken'] ?? '';

    $result = [
        'valid' => false,
        'role' => 'user',
        'username' => sanitizeUsername($username),
        'authMode' => ''
    ];

    $users = loadJsonFile($dataFilePath, []);

    if (!empty($username) && !empty($sessionToken) && validateLoginSession($username, $sessionToken, $sessionFilePath)) {
        foreach ($users as $user) {
            if (($user['username'] ?? '') === $username) {
                return [
                    'valid' => true,
                    'role' => 'user',
                    'username' => sanitizeUsername($username),
                    'authMode' => 'session',
                    'user' => $user
                ];
            }
        }
    }

    $apiUser = validateUserByApiKey($username, $apiKey, $dataFilePath);
    if ($apiUser) {
        return [
            'valid' => true,
            'role' => 'user',
            'username' => sanitizeUsername($username),
            'authMode' => 'apiKey',
            'user' => $apiUser
        ];
    }

    if (!empty($username) && !empty($password)) {
        $userValidation = validateUser($username, $password, $dataFilePath);
        if ($userValidation['valid']) {
            $result = array_merge($userValidation, [
                'username' => sanitizeUsername($username),
                'authMode' => 'password'
            ]);
            return $result;
        }
    }

    return $result;
}

// =================================================================
// 允许跨域请求 (CORS)
$allowedOrigins = defined('CORS_ALLOWED_ORIGINS') && is_array(CORS_ALLOWED_ORIGINS)
    ? CORS_ALLOWED_ORIGINS
    : [];
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (!empty($requestOrigin) && in_array($requestOrigin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$requestOrigin}");
}
header('Vary: Origin');
header("Access-Control-Allow-Methods: POST, GET, OPTIONS"); // 允许POST, GET和OPTIONS方法
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // 允许Content-Type和Authorization头
header("Content-Type: application/json; charset=UTF-8"); // 设置响应内容类型为JSON

// 处理 OPTIONS 请求 (CORS 预检请求)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!empty($requestOrigin) && !in_array($requestOrigin, $allowedOrigins, true)) {
        http_response_code(403);
        exit();
    }
    http_response_code(200);
    exit(); // 预检请求成功，直接退出
}

// 确保 data 目录存在
if (!is_dir('data')) {
    mkdir('data', 0755, true);
}
if (!is_dir('data/notifications')) {
    mkdir('data/notifications', 0755, true);
}

// 辅助函数：获取文件路径并自动迁移
function getFilePath($filename)
{
    if (strpos($filename, 'data/') === 0) {
        $filename = substr($filename, 5); // 移除 data/ 前缀（如已传入）
    }
    $targetPath = 'data/' . $filename;

    // 如果 data 下已存在，直接返回
    if (file_exists($targetPath)) {
        return $targetPath;
    }

    // 如果 data 下不存在但根目录存在
    if (file_exists($filename)) {
        // 尝试迁移到 data 目录
        if (@rename($filename, $targetPath)) {
            return $targetPath;
        }
        // 迁移失败（可能是权限问题），返回根目录路径以确保功能正常
        return $filename;
    }

    // 都不存在，返回 data 目录路径（将在后续创建）
    return $targetPath;
}

// 模拟数据存储文件路径
$dataFilePath = getFilePath('users.json');
$cloudQuizDir = 'data/cloud_quizzes/';
$sessionFilePath = getFilePath('login_sessions.json');
$oldCloudQuizDir = 'cloud_quizzes/';
// 自动迁移云题库目录
if (!is_dir($cloudQuizDir) && is_dir($oldCloudQuizDir)) {
    @rename($oldCloudQuizDir, $cloudQuizDir);
}
if (!is_dir($cloudQuizDir)) {
    if (!mkdir($cloudQuizDir, 0755, true)) {
        // 尝试创建失败，可能权限问题，但这通常由 mkdir 自身处理
    }
}

// 速率限制配置
$rateLimitFile = getFilePath('rate_limits.json');
$rateLimitPeriod = defined('REQUEST_RATE_LIMIT_WINDOW_SECONDS') ? max(1, (int)REQUEST_RATE_LIMIT_WINDOW_SECONDS) : 60;
$maxRequests = defined('REQUEST_RATE_LIMIT_MAX_REQUESTS') ? max(1, (int)REQUEST_RATE_LIMIT_MAX_REQUESTS) : 5;

// 辅助函数：发送JSON响应
function sendResponse($success, $message, $data = [], $statusCode = 200)
{
    http_response_code($statusCode);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit();
}

function loadJsonFile($filePath, $default = [])
{
    if (!file_exists($filePath)) {
        return $default;
    }
    $content = file_get_contents($filePath);
    if ($content === false || $content === '') {
        return $default;
    }
    $decoded = json_decode($content, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function saveJsonFile($filePath, $data)
{
    return file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function cleanupExpiredLoginSessions($sessionFilePath)
{
    $sessions = loadJsonFile($sessionFilePath, []);
    $currentTime = time();
    $changed = false;
    foreach ($sessions as $token => $session) {
        if (($session['expires_at'] ?? 0) < $currentTime) {
            unset($sessions[$token]);
            $changed = true;
        }
    }
    if ($changed) {
        saveJsonFile($sessionFilePath, $sessions);
    }
    return $sessions;
}

function createLoginSession($username, $sessionFilePath, $ttlSeconds = 7 * 24 * 60 * 60)
{
    $sessions = cleanupExpiredLoginSessions($sessionFilePath);
    $token = bin2hex(random_bytes(32));
    $sessions[$token] = [
        'username' => $username,
        'created_at' => time(),
        'expires_at' => time() + max(300, (int)$ttlSeconds)
    ];
    if (!saveJsonFile($sessionFilePath, $sessions)) {
        return false;
    }
    return $token;
}

function validateLoginSession($username, $sessionToken, $sessionFilePath)
{
    if (empty($username) || empty($sessionToken)) {
        return false;
    }
    $sessions = cleanupExpiredLoginSessions($sessionFilePath);
    if (!isset($sessions[$sessionToken])) {
        return false;
    }
    return ($sessions[$sessionToken]['username'] ?? '') === $username;
}

function revokeLoginSession($sessionToken, $sessionFilePath)
{
    if (empty($sessionToken)) {
        return;
    }
    $sessions = loadJsonFile($sessionFilePath, []);
    if (isset($sessions[$sessionToken])) {
        unset($sessions[$sessionToken]);
        saveJsonFile($sessionFilePath, $sessions);
    }
}

function validateUserByApiKey($username, $apiKey, $usersFile)
{
    if (empty($username) || empty($apiKey)) {
        return null;
    }
    $users = loadJsonFile($usersFile, []);
    foreach ($users as $user) {
        if (($user['username'] ?? '') === $username && ($user['api_key'] ?? '') === $apiKey) {
            return $user;
        }
    }
    return null;
}

function getQuizAccessMode($content)
{
    $mode = $content['metadata']['accessMode'] ?? 'public';
    return $mode === 'login_required' ? 'login_required' : 'public';
}

function getQuizReviewStatus($content)
{
    $status = $content['metadata']['status'] ?? 'active';
    return is_string($status) && $status !== '' ? $status : 'active';
}

function isCloudQuizShareable($content, $filePath = '')
{
    if (!is_array($content) || empty($content)) {
        return false;
    }

    if (!empty($filePath)) {
        $normalizedPath = str_replace('\\', '/', $filePath);
        $isPrivateQuiz = strpos($normalizedPath, '/users/') !== false;
        $isPublicQuiz = strpos($normalizedPath, '/public/') !== false
            || preg_match('#data/cloud_quizzes/[^/]+\.json$#', $normalizedPath);
        if (!$isPrivateQuiz && !$isPublicQuiz) {
            return false;
        }
    }

    return getQuizReviewStatus($content) === 'active';
}

// 获取请求体数据
$input = file_get_contents('php://input');

// Check if input is empty, which might happen if post_max_size is exceeded
if (empty($input) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
    if ($contentLength > 0) {
        sendResponse(false, '上传数据过大，超过了服务器限制 (post_max_size)。建议压缩图片或减少单次上传数量。', [], 413);
    } else {
        sendResponse(false, '请求体为空。', [], 400);
    }
}

$data = json_decode($input, true);

// 检查是否成功解码JSON数据
if (json_last_error() !== JSON_ERROR_NONE) {
    sendResponse(false, '无效的JSON数据。', [], 400); // Bad Request
}

$action = $data['action'] ?? ''; // 获取前端请求的操作类型

// ... (速率限制和IP获取函数保持不变) ...
function checkRateLimit($ip, $actionType, $rateLimitFile, $rateLimitPeriod, $maxRequests)
{
    $limits = [];
    if (file_exists($rateLimitFile)) {
        $limits = json_decode(file_get_contents($rateLimitFile), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $limits = [];
        }
    }
    $currentTime = time();
    $ipKey = str_replace('.', '_', $ip);
    if (isset($limits[$ipKey][$actionType])) {
        $limits[$ipKey][$actionType] = array_filter($limits[$ipKey][$actionType], function ($timestamp) use ($currentTime, $rateLimitPeriod) {
            return ($currentTime - $timestamp) < $rateLimitPeriod;
        });
    }
    if (isset($limits[$ipKey][$actionType]) && count($limits[$ipKey][$actionType]) >= $maxRequests) {
        return false;
    }
    $limits[$ipKey][$actionType][] = $currentTime;
    if (!file_put_contents($rateLimitFile, json_encode($limits, JSON_PRETTY_PRINT), LOCK_EX)) {
        error_log("Failed to write to data/rate_limits.json: " . $rateLimitFile);
    }
    return true;
}

function getClientIp()
{
    // 未配置可信代理链时，不采信客户端可伪造的 X-Forwarded-For/Client-IP。
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
}
$clientIp = getClientIp();

// ========== 读取系统设置 ==========
switch ($action) {
    // ===================== 获取验证码题目 (防刷) =====================
    case 'get_captcha':
        // 生成简单加法题
        $a = random_int(10, 99);
        $b = random_int(10, 99);
        $answer = $a + $b;
        $token = bin2hex(random_bytes(16));
        
        $captchaFile = 'data/captcha.json';
        $captchas = [];
        if (file_exists($captchaFile)) {
            $captchas = json_decode(file_get_contents($captchaFile), true) ?: [];
        }
        // 清理过期的 (5分钟)
        $now = time();
        foreach ($captchas as $t => $d) {
            if ($d['expires_at'] < $now) unset($captchas[$t]);
        }
        $captchas[$token] = [
            'answer' => $answer,
            'expires_at' => $now + 300 // 5分钟过期
        ];
        file_put_contents($captchaFile, json_encode($captchas, JSON_PRETTY_PRINT));
        
        sendResponse(true, '', [
            'captcha_question' => "{$a} + {$b} = ?",
            'captcha_token' => $token
        ]);
        break;
    // ===================== 发送邮箱验证码 =====================
    case 'send_verification_code':
        if (!checkRateLimit($clientIp, 'send_code', $rateLimitFile, $rateLimitPeriod, $maxRequests)) {
            sendResponse(false, '请求过于频繁，请稍后再试。', [], 429);
        }
        $email = trim($data['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, '请输入有效的邮箱地址。', [], 400);
        }
        // 验证图片验证码 (防刷)
        $captchaToken = $data['captcha_token'] ?? '';
        $captchaAnswer = $data['captcha_answer'] ?? '';
        if (empty($captchaToken) || $captchaAnswer === '') {
            sendResponse(false, '请先完成验证。', [], 400);
        }
        $captchaFile = 'data/captcha.json';
        $captchas = [];
        if (file_exists($captchaFile)) {
            $captchas = json_decode(file_get_contents($captchaFile), true) ?: [];
        }
        // 清理过期
        $now = time();
        foreach ($captchas as $t => $d) {
            if ($d['expires_at'] < $now) unset($captchas[$t]);
        }
        if (!isset($captchas[$captchaToken])) {
            sendResponse(false, '验证已过期，请重新验证。', [], 400);
        }
        if ((int)$captchaAnswer !== $captchas[$captchaToken]['answer']) {
            // 无论对错都删除此token，防止暴力破解
            unset($captchas[$captchaToken]);
            file_put_contents($captchaFile, json_encode($captchas, JSON_PRETTY_PRINT));
            sendResponse(false, '验证答案错误，请重新验证。', [], 400);
        }
        // 验证通过，清除已使用的 token
        unset($captchas[$captchaToken]);
        file_put_contents($captchaFile, json_encode($captchas, JSON_PRETTY_PRINT));
        // 检查该邮箱是否已注册
        $users = [];
        if (file_exists($dataFilePath)) {
            $users = json_decode(file_get_contents($dataFilePath), true) ?: [];
        }
        foreach ($users as $user) {
            if (isset($user['email']) && $user['email'] === $email) {
                sendResponse(false, '该邮箱已被注册，请直接登录。', [], 409);
            }
        }
        // 清理过期验证码
        cleanExpiredVerificationCodes();
        // 检查是否在60秒内已发送过
        $codesFile = 'data/verification_codes.json';
        $codes = [];
        if (file_exists($codesFile)) {
            $codes = json_decode(file_get_contents($codesFile), true) ?: [];
        }
        if (isset($codes[$email])) {
            $lastSent = $codes[$email]['sent_at'] ?? 0;
            if (time() - $lastSent < 60) {
                $remaining = 60 - (time() - $lastSent);
                sendResponse(false, "发送过于频繁，请 {$remaining} 秒后再试。", [], 429);
            }
        }
        // 生成验证码
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codes[$email] = [
            'code' => $code,
            'expires_at' => time() + VERIFICATION_CODE_EXPIRY,
            'sent_at' => time()
        ];
        file_put_contents($codesFile, json_encode($codes, JSON_PRETTY_PRINT));
        // 发送邮件
        $result = sendVerificationEmail($email, $code);
        if ($result['success']) {
            sendResponse(true, '验证码已发送到您的邮箱，请查收。');
        } else {
            sendResponse(false, $result['error'], [], 500);
        }
        break;

    case 'verify_registration_code':
        $email = trim($data['email'] ?? '');
        $verificationCode = trim($data['verificationCode'] ?? '');

        if (empty($email) || empty($verificationCode)) {
            sendResponse(false, '邮箱和验证码不能为空。', [], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, '邮箱格式不正确。', [], 400);
        }

        cleanExpiredVerificationCodes();
        $codesFile = 'data/verification_codes.json';
        $codes = [];
        if (file_exists($codesFile)) {
            $codes = json_decode(file_get_contents($codesFile), true) ?: [];
        }

        if (!isset($codes[$email]) || $codes[$email]['code'] !== $verificationCode || $codes[$email]['expires_at'] < time()) {
            sendResponse(false, '验证码错误或已过期，请重新获取。', [], 400);
        }

        sendResponse(true, '邮箱验证成功。');
        break;

    // ===================== 注册（邮箱 + 验证码） =====================
    case 'register':
        if (!checkRateLimit($clientIp, 'register', $rateLimitFile, $rateLimitPeriod, $maxRequests)) {
            sendResponse(false, '注册请求过于频繁，请稍后再试。', [], 429);
        }
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $verificationCode = trim($data['verificationCode'] ?? '');
        $nickname = trim($data['nickname'] ?? '');
        $apiKey = $data['apiKey'] ?? '';
        $siliconflowApiKey = $data['siliconflowApiKey'] ?? '';

        if (empty($email) || empty($password) || empty($verificationCode)) {
            sendResponse(false, '邮箱、密码和验证码不能为空。', [], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, '邮箱格式不正确。', [], 400);
        }
        if (strlen($password) < 6) {
            sendResponse(false, '密码长度不能少于6位。', [], 400);
        }

        // 验证验证码
        cleanExpiredVerificationCodes();
        $codesFile = 'data/verification_codes.json';
        $codes = [];
        if (file_exists($codesFile)) {
            $codes = json_decode(file_get_contents($codesFile), true) ?: [];
        }
        if (!isset($codes[$email]) || $codes[$email]['code'] !== $verificationCode || $codes[$email]['expires_at'] < time()) {
            sendResponse(false, '验证码错误或已过期，请重新获取。', [], 400);
        }

        // 检查邮箱是否已注册 (二次确认)
        $users = [];
        if (file_exists($dataFilePath)) {
            $users = json_decode(file_get_contents($dataFilePath), true) ?: [];
        }
        $finalUsername = sanitizeUsername(!empty($nickname) ? $nickname : explode('@', $email)[0]);
        if (empty($finalUsername)) {
            sendResponse(false, '昵称不能为空或包含非法字符。', [], 400);
        }

        foreach ($users as $user) {
            if (isset($user['email']) && $user['email'] === $email) {
                sendResponse(false, '该邮箱已被注册。', [], 409);
            }
            if (($user['username'] ?? '') === $finalUsername || (!empty($nickname) && ($user['nickname'] ?? '') === $nickname)) {
                sendResponse(false, '该昵称已被使用，请更换一个昵称。', [], 409);
            }
        }

        // 创建用户
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $newUser = [
            'email' => $email,
            'password_hash' => $passwordHash,
            'username' => $finalUsername,
            'nickname' => $nickname,
            'role' => 'user',
            'created_at' => date('c')
        ];
        if (!empty($apiKey)) {
            $newUser['api_key'] = $apiKey;
        }
        if (!empty($siliconflowApiKey)) {
            $newUser['siliconflow_api_key'] = $siliconflowApiKey;
        }
        $users[] = $newUser;

        if (file_put_contents($dataFilePath, json_encode($users, JSON_PRETTY_PRINT))) {
            // 清除已使用的验证码
            unset($codes[$email]);
            file_put_contents($codesFile, json_encode($codes, JSON_PRETTY_PRINT));
            sendResponse(true, '注册成功！');
        } else {
            sendResponse(false, '注册失败：无法写入数据文件。请检查文件权限。', [], 500);
        }
        break;

    // ===================== 登录（支持邮箱和用户名） =====================
    case 'login':
        if (!checkRateLimit($clientIp, 'login', $rateLimitFile, 300, 10)) {
            sendResponse(false, '登录尝试过于频繁，请 5 分钟后再试。', [], 429);
        }
        $loginId = $data['username'] ?? $data['email'] ?? '';
        $password = $data['password'] ?? '';
        if (empty($loginId) || empty($password)) {
            sendResponse(false, '邮箱/用户名和密码不能为空。', [], 400);
        }
        $users = [];
        if (file_exists($dataFilePath)) {
            $users = json_decode(file_get_contents($dataFilePath), true) ?: [];
        }
        $foundUser = null;
        $isEmail = filter_var($loginId, FILTER_VALIDATE_EMAIL) !== false;
        foreach ($users as $user) {
            if ($isEmail) {
                if (isset($user['email']) && $user['email'] === $loginId) {
                    $foundUser = $user;
                    break;
                }
            } else {
                if (isset($user['username']) && $user['username'] === $loginId) {
                    $foundUser = $user;
                    break;
                }
            }
        }
        if ($foundUser && password_verify($password, $foundUser['password_hash'])) {
            $sessionToken = createLoginSession($foundUser['username'] ?? ($foundUser['email'] ?? ''), $sessionFilePath);
            if ($sessionToken === false) {
                sendResponse(false, '登录成功，但会话创建失败，请稍后重试。', [], 500);
            }
            sendResponse(true, '登录成功。', [
                'username' => $foundUser['username'] ?? ($foundUser['email'] ?? ''),
                'email' => $foundUser['email'] ?? '',
                'apiKey' => $foundUser['api_key'] ?? '',
                'siliconflowApiKey' => $foundUser['siliconflow_api_key'] ?? '',
                'sessionToken' => $sessionToken
            ]);
        } else {
            sendResponse(false, '邮箱/用户名或密码错误。', [], 401);
        }
        break;

    case 'logout':
        $sessionToken = $data['sessionToken'] ?? '';
        revokeLoginSession($sessionToken, $sessionFilePath);
        sendResponse(true, '登出成功。');
        break;
    case 'get_quiz':
        // Sanitize filename
        $quizName = $data['quizName'] ?? '';
        $quizName = str_replace(array('/', '\\', ':', '*', '?', '"', '<', '>', '|'), '', $quizName);
        $quizName = trim($quizName);

        // 获取用户信息以支持私有题库与需登录题库访问
        $username = $data['username'] ?? '';
        $apiKey = $data['apiKey'] ?? '';
        $sessionToken = $data['sessionToken'] ?? '';

        if (empty($quizName)) {
            sendResponse(false, '题库名称不能为空。', [], 400);
        }

        $authenticatedUser = null;
        if (!empty($username) && !empty($sessionToken) && validateLoginSession($username, $sessionToken, $sessionFilePath)) {
            $authenticatedUser = ['username' => $username];
        } elseif (!empty($username) && !empty($apiKey)) {
            $authenticatedUser = validateUserByApiKey($username, $apiKey, $dataFilePath);
        }

        // 依次在多个目录中查找题库
        $possiblePaths = [
            $cloudQuizDir . 'public/' . $quizName . '.json',  // 新版公开目录
            $cloudQuizDir . $quizName . '.json',               // 旧版根目录（兼容）
        ];

        // 登录用户可访问自己的私有目录
        $sanitizedUsername = sanitizeUsername($authenticatedUser['username'] ?? '');
        if (!empty($sanitizedUsername)) {
            array_unshift($possiblePaths, $cloudQuizDir . "users/{$sanitizedUsername}/{$quizName}.json");
        }

        $quizFileName = null;
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $quizFileName = $path;
                break;
            }
        }

        if ($quizFileName) {
            $fileContent = json_decode(file_get_contents($quizFileName), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                sendResponse(false, '云题库文件损坏。', [], 500);
            }

            $isPrivateQuiz = strpos($quizFileName, '/users/') !== false && strpos($quizFileName, '/public/') === false;
            $accessMode = getQuizAccessMode($fileContent);

            if ($isPrivateQuiz && !$authenticatedUser) {
                sendResponse(false, '请先登录后再访问此题库。', [], 401);
            }

            if (!$isPrivateQuiz && $accessMode === 'login_required' && !$authenticatedUser) {
                sendResponse(false, '该题库需要登录后访问。', ['requiresLogin' => true], 401);
            }

            // Compatibility check: New format has 'quizData' key, old format is just the array
            if (isset($fileContent['quizData'])) {
                sendResponse(true, '云题库加载成功。', array_merge($fileContent, [
                    'accessMode' => $accessMode
                ]));
            } else {
                sendResponse(true, '云题库加载成功。', [
                    'quizData' => $fileContent,
                    'images' => [],
                    'accessMode' => $accessMode
                ]);
            }
        } else {
            sendResponse(false, '指定的云题库不存在或为空。', [], 404);
        }
        break;
    case 'list_quizzes':
        $quizFiles = [];
        $quizEntries = [];

        // 扫描公开目录 (新版)
        $publicDir = $cloudQuizDir . 'public/';
        if (is_dir($publicDir)) {
            $files = scandir($publicDir);
            foreach ($files as $file) {
                if (is_file($publicDir . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                    $name = pathinfo($file, PATHINFO_FILENAME);
                    $filePath = $publicDir . $file;
                    $content = loadJsonFile($filePath, []);
                    $accessMode = getQuizAccessMode($content);
                    if (!in_array($name, $quizFiles)) {
                        $quizFiles[] = $name;
                        $quizEntries[] = [
                            'name' => $name,
                            'path' => 'public/' . $file,
                            'accessMode' => $accessMode
                        ];
                    }
                }
            }
        }

        // 扫描根目录 (兼容旧版)
        if (is_dir($cloudQuizDir)) {
            $files = scandir($cloudQuizDir);
            foreach ($files as $file) {
                if (is_file($cloudQuizDir . $file) && pathinfo($file, PATHINFO_EXTENSION) === 'json') {
                    $name = pathinfo($file, PATHINFO_FILENAME);
                    $filePath = $cloudQuizDir . $file;
                    $content = loadJsonFile($filePath, []);
                    $accessMode = getQuizAccessMode($content);
                    if (!in_array($name, $quizFiles)) {
                        $quizFiles[] = $name;
                        $quizEntries[] = [
                            'name' => $name,
                            'path' => $file,
                            'accessMode' => $accessMode
                        ];
                    }
                }
            }
        }

        sort($quizFiles);
        sendResponse(true, '云题库列表加载成功。', ['quizList' => $quizFiles, 'quizEntries' => $quizEntries]);
        break;
    case 'get_quiz_announcement':
        $quizName = $data['quizName'] ?? '';
        if (empty($quizName)) {
            sendResponse(false, '题库名称不能为空。', [], 400);
            exit;
        }

        $announcementsFile = getFilePath('quiz_announcements.json');
        $announcements = [];
        if (file_exists($announcementsFile)) {
            $announcements = json_decode(file_get_contents($announcementsFile), true) ?: [];
        }

        if (isset($announcements[$quizName]) && $announcements[$quizName]['enabled']) {
            sendResponse(true, '获取公告成功。', [
                'announcement' => $announcements[$quizName]
            ]);
        } else {
            sendResponse(true, '无公告。', ['announcement' => null]);
        }
        break;

    case 'get_glm_explanation':
        $prompt = $data['prompt'] ?? '';
        if (empty($prompt)) {
            sendResponse(false, '请求内容不能为空。', [], 400);
        }

        $apiUrl = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
        $payload = [
            'model' => 'glm-4.5-flash',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ]
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GLM_API_KEY
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30秒超时

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            sendResponse(false, '服务器代理请求失败: ' . $error, [], 500);
        }

        if ($httpCode >= 400) {
            $errorBody = json_decode($response, true);
            $errorMessage = $errorBody['error']['message'] ?? '未知API错误';
            sendResponse(false, 'GLM API 返回错误 (' . $httpCode . '): ' . $errorMessage, [], $httpCode);
        }

        // 成功，直接将GLM的响应转发给前端
        http_response_code(200);
        echo $response;
        exit();

    // =================================================================
    // 新增: 更新用户设置 (SiliconFlow API Key)
    // =================================================================
    case 'update_user_settings':
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $siliconflowApiKey = $data['siliconflowApiKey'] ?? '';

        if (empty($username) || empty($password)) {
            sendResponse(false, '用户名和密码不能为空。', [], 400);
        }

        $users = [];
        if (file_exists($dataFilePath)) {
            $users = json_decode(file_get_contents($dataFilePath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $users = [];
            }
        }

        $userIndex = -1;
        foreach ($users as $index => $user) {
            if ($user['username'] === $username) {
                $userIndex = $index;
                break;
            }
        }

        if ($userIndex === -1) {
            sendResponse(false, '用户不存在。', [], 404);
        }

        if (!password_verify($password, $users[$userIndex]['password_hash'])) {
            sendResponse(false, '密码验证失败。', [], 401);
        }

        // 更新 SiliconFlow API Key
        $users[$userIndex]['siliconflow_api_key'] = $siliconflowApiKey;

        if (file_put_contents($dataFilePath, json_encode($users, JSON_PRETTY_PRINT))) {
            sendResponse(true, 'SiliconFlow API Key 更新成功。', [
                'siliconflowApiKey' => $siliconflowApiKey
            ]);
        } else {
            sendResponse(false, '更新失败：无法写入数据文件。', [], 500);
        }
        break;

    // =================================================================
    // 新增: 带视觉的聊天 (使用 SiliconFlow GLM-4.6V)
    // =================================================================
    case 'chat_with_vision':
        $prompt = $data['prompt'] ?? '';
        $imageData = $data['image'] ?? null; // base64 格式 (data:image/...;base64,...)
        $siliconflowApiKey = $data['siliconflowApiKey'] ?? '';

        if (empty($prompt)) {
            sendResponse(false, '请求内容不能为空。', [], 400);
        }

        if (empty($siliconflowApiKey)) {
            sendResponse(false, '需要提供 SiliconFlow API Key 才能使用图片识别功能。', [], 400);
        }

        // 构建 OpenAI Vision 兼容格式的消息
        $content = [];
        $content[] = ['type' => 'text', 'text' => $prompt];

        if (!empty($imageData)) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageData // base64 data URL
                ]
            ];
        }

        $apiUrl = 'https://api.siliconflow.cn/v1/chat/completions';
        $payload = [
            'model' => 'zai-org/GLM-4.6V',
            'messages' => [
                ['role' => 'user', 'content' => $content]
            ],
            'max_tokens' => 2048
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $siliconflowApiKey
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60秒超时 (视觉模型可能需要更长时间)

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            sendResponse(false, 'SiliconFlow 请求失败: ' . $error, [], 500);
        }

        if ($httpCode >= 400) {
            $errorBody = json_decode($response, true);
            $errorMessage = $errorBody['error']['message'] ?? $errorBody['message'] ?? '未知API错误';
            sendResponse(false, 'SiliconFlow API 返回错误 (' . $httpCode . '): ' . $errorMessage, [], $httpCode);
        }

        // 成功，直接将响应转发给前端
        http_response_code(200);
        echo $response;
        exit();

    // =================================================================
    // 默认 AI 模型解释 - 流式版本 (SSE)
    // =================================================================
    case 'get_free_explanation_stream':
        $prompt = $data['prompt'] ?? '';
        $images = $data['images'] ?? [];
        $stream = $data['stream'] ?? true;

        if (empty($prompt)) {
            sendResponse(false, '请求内容不能为空。', [], 400);
        }

        // 调用次数限制检查（同 get_free_explanation）
        $guestCallsFile = getFilePath('guest_api_calls.json');
        $guestCalls = [];
        if (file_exists($guestCallsFile)) {
            $guestCalls = json_decode(file_get_contents($guestCallsFile), true) ?: [];
        }

        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $currentCalls = $guestCalls[$clientIp]['calls'][$today] ?? 0;
        $dailyLimit = max(0, (int)FREE_MODEL_DAILY_LIMIT);

        if ($currentCalls >= $dailyLimit) {
            sendResponse(false, "今日调用次数已用完（{$dailyLimit}次）。", ['limitReached' => true], 429);
        }

        // 先记录调用次数（在请求开始前，避免流式传输中断导致计数丢失）
        if (!isset($guestCalls[$clientIp])) {
            $guestCalls[$clientIp] = ['firstVisit' => $now, 'lastVisit' => $now, 'calls' => []];
        }
        $guestCalls[$clientIp]['lastVisit'] = $now;
        $guestCalls[$clientIp]['calls'][$today] = ($guestCalls[$clientIp]['calls'][$today] ?? 0) + 1;
        file_put_contents($guestCallsFile, json_encode($guestCalls, JSON_PRETTY_PRINT));

        // 选择模型
        if (!USE_SILICONFLOW_FREE_MODEL || empty(SILICONFLOW_FREE_API_KEY)) {
            sendResponse(false, '流式模式仅支持 SiliconFlow，请启用硅基流动。', [], 500);
        }

        $apiUrl = 'https://api.siliconflow.cn/v1/chat/completions';

        // 读取双模型设置
        $useDualModel = defined('USE_DUAL_MODEL') && USE_DUAL_MODEL;
        $textModel = defined('SILICONFLOW_FREE_TEXT_MODEL') ? SILICONFLOW_FREE_TEXT_MODEL : '';
        $visionModel = defined('SILICONFLOW_FREE_VISION_MODEL') ? SILICONFLOW_FREE_VISION_MODEL : '';

        $hasImages = !empty($images) && count(array_filter($images)) > 0;

        if ($useDualModel && !empty($textModel) && !empty($visionModel)) {
            $selectedModel = $hasImages ? $visionModel : $textModel;
        } else {
            $selectedModel = SILICONFLOW_FREE_MODEL;
        }

        // 构建消息
        $content = [['type' => 'text', 'text' => $prompt]];
        if ($hasImages) {
            foreach ($images as $imgData) {
                if (!empty($imgData)) {
                    $content[] = ['type' => 'image_url', 'image_url' => ['url' => $imgData]];
                }
            }
        }

        $payload = json_encode([
            'model' => $selectedModel,
            'messages' => [['role' => 'user', 'content' => $content]],
            'max_tokens' => 2048,
            'stream' => true
        ]);

        // 设置 SSE 响应头
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        // 使用 cURL 流式读取
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . SILICONFLOW_FREE_API_KEY
        ]);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) {
            echo $data;
            if (ob_get_level() > 0)
                ob_flush();
            flush();
            return strlen($data);
        });
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        curl_exec($ch);
        curl_close($ch);

        exit();

    // =================================================================
    // 默认 AI 模型解释 (使用站长配置的 Qwen2.5-VL 或 GLM)
    // =================================================================
    case 'get_free_explanation':
        $prompt = $data['prompt'] ?? '';
        $images = $data['images'] ?? []; // 图片数组 (base64 格式)

        if (empty($prompt)) {
            sendResponse(false, '请求内容不能为空。', [], 400);
        }

        // ========== 服务器端默认 AI 模型调用次数限制 ==========
        $guestCallsFile = getFilePath('guest_api_calls.json');
        $guestCalls = [];
        if (file_exists($guestCallsFile)) {
            $guestCalls = json_decode(file_get_contents($guestCallsFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $guestCalls = [];
            }
        }

        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $currentCalls = $guestCalls[$clientIp]['calls'][$today] ?? 0;
        $dailyLimit = max(0, (int)FREE_MODEL_DAILY_LIMIT);
        if ($currentCalls >= $dailyLimit) {
            sendResponse(false, "今日 AI 请求次数已用完（{$dailyLimit}次），请明日再试。", ['limitReached' => true], 429);
        }
        // ================================================

        // 根据配置决定使用哪个模型
        if (USE_SILICONFLOW_FREE_MODEL && !empty(SILICONFLOW_FREE_API_KEY)) {
            // 使用硅基流动模型
            $apiUrl = 'https://api.siliconflow.cn/v1/chat/completions';

            // 读取双模型设置
            $useDualModel = defined('USE_DUAL_MODEL') && USE_DUAL_MODEL;
        $textModel = defined('SILICONFLOW_FREE_TEXT_MODEL') ? SILICONFLOW_FREE_TEXT_MODEL : '';
        $visionModel = defined('SILICONFLOW_FREE_VISION_MODEL') ? SILICONFLOW_FREE_VISION_MODEL : '';

            // 判断是否有图片
            $hasImages = !empty($images) && count(array_filter($images)) > 0;

            // 选择合适的模型
            if ($useDualModel && !empty($textModel) && !empty($visionModel)) {
                // 双模型模式：根据是否有图片选择
                $selectedModel = $hasImages ? $visionModel : $textModel;
            } else {
                // 单模型模式：使用默认模型
                $selectedModel = SILICONFLOW_FREE_MODEL;
            }

            // 构建消息内容
            $content = [];
            $content[] = ['type' => 'text', 'text' => $prompt];

            // 添加图片（如果有且使用视觉模型）
            if ($hasImages) {
                foreach ($images as $imageData) {
                    if (!empty($imageData)) {
                        $content[] = [
                            'type' => 'image_url',
                            'image_url' => ['url' => $imageData]
                        ];
                    }
                }
            }

            $payload = [
                'model' => $selectedModel,
                'messages' => [
                    ['role' => 'user', 'content' => $content]
                ],
                'max_tokens' => 2048
            ];

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . SILICONFLOW_FREE_API_KEY
            ];
        } else {
            // 使用 GLM 模型（备用）
            $apiUrl = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
            $payload = [
                'model' => 'glm-4.5-flash',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ];

            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GLM_API_KEY
            ];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            sendResponse(false, '默认 AI 模型请求失败: ' . $error, [], 500);
        }

        if ($httpCode >= 400) {
            $errorBody = json_decode($response, true);
            $errorMessage = $errorBody['error']['message'] ?? '未知API错误';
            sendResponse(false, '默认 AI 模型 API 返回错误 (' . $httpCode . '): ' . $errorMessage, [], $httpCode);
        }

        // 解析响应并添加模型信息
        $responseData = json_decode($response, true);
        $responseData['model_used'] = USE_SILICONFLOW_FREE_MODEL ? 'Qwen2.5-VL-7B' : 'GLM-4.5-flash';

        // 记录游客调用次数
        $guestCallsFile = getFilePath('guest_api_calls.json');
        $guestCalls = [];
        if (file_exists($guestCallsFile)) {
            $guestCalls = json_decode(file_get_contents($guestCallsFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $guestCalls = [];
            }
        }

        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        if (!isset($guestCalls[$clientIp])) {
            $guestCalls[$clientIp] = [
                'firstVisit' => $now,
                'lastVisit' => $now,
                'calls' => []
            ];
        }

        $guestCalls[$clientIp]['lastVisit'] = $now;
        if (!isset($guestCalls[$clientIp]['calls'][$today])) {
            $guestCalls[$clientIp]['calls'][$today] = 0;
        }
        $guestCalls[$clientIp]['calls'][$today]++;

        file_put_contents($guestCallsFile, json_encode($guestCalls, JSON_PRETTY_PRINT));

        http_response_code(200);
        echo json_encode($responseData);
        exit();

    // =================================================================
    // 新增: 保存用户 Token 统计 (登录用户)
    // =================================================================
    case 'save_token_stats':
        $username = $data['username'] ?? '';
        $stats = $data['stats'] ?? null;

        if (empty($username) || empty($stats)) {
            sendResponse(false, '缺少必要参数。', [], 400);
        }

        $statsFilePath = getFilePath('user_token_stats.json');
        $allStats = [];

        if (file_exists($statsFilePath)) {
            $allStats = json_decode(file_get_contents($statsFilePath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $allStats = [];
            }
        }

        $allStats[$username] = $stats;
        $allStats[$username]['lastUpdated'] = date('c');

        if (file_put_contents($statsFilePath, json_encode($allStats, JSON_PRETTY_PRINT))) {
            sendResponse(true, 'Token 统计已保存。');
        } else {
            sendResponse(false, '保存 Token 统计失败。', [], 500);
        }
        break;

    // =================================================================
    // 新增: 获取用户 Token 统计 (登录用户)
    // =================================================================
    case 'get_token_stats':
        $username = $data['username'] ?? '';

        if (empty($username)) {
            sendResponse(false, '缺少用户名。', [], 400);
        }

        $statsFilePath = getFilePath('user_token_stats.json');
        $allStats = [];

        if (file_exists($statsFilePath)) {
            $allStats = json_decode(file_get_contents($statsFilePath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $allStats = [];
            }
        }

        if (isset($allStats[$username])) {
            sendResponse(true, 'Token 统计获取成功。', ['stats' => $allStats[$username]]);
        } else {
            sendResponse(true, '用户暂无 Token 统计数据。', ['stats' => null]);
        }
        break;

    // =================================================================
    // 获取默认 AI 模型信息 (供前端显示)
    // =================================================================
    case 'get_free_model_info':
        sendResponse(true, '默认 AI 模型信息获取成功。', [
            'freeModel' => [
                'useSiliconflow' => USE_SILICONFLOW_FREE_MODEL,
                'visionModel' => FREE_VISION_MODEL,
                'textModel' => FREE_TEXT_MODEL,
                'supportsVision' => USE_SILICONFLOW_FREE_MODEL
            ],
            'registeredModel' => [
                'visionModel' => CHAT_VISION_MODEL,
                'textModel' => REGISTERED_TEXT_MODEL
            ]
        ]);
        break;

    // =================================================================
    // 新增: 默认 AI 模型简答题评分 (未登录用户)
    // =================================================================
    case 'get_system_info':
        $guestCalls = loadJsonFile(getFilePath('guest_api_calls.json'), []);
        $today = date('Y-m-d');
        sendResponse(true, '获取系统信息成功', [
            'systemInfo' => [
                'globalBaseLimit' => max(0, (int)FREE_MODEL_DAILY_LIMIT),
                'guestCallsToday' => $guestCalls[$clientIp]['calls'][$today] ?? 0
            ]
        ]);
        break;

    case 'grade_short_answer_free':
        $question = $data['question'] ?? '';
        $correctAnswer = $data['correctAnswer'] ?? '';
        $userAnswer = $data['userAnswer'] ?? '';
        $images = $data['images'] ?? [];  // 图片数组 (base64格式)
        $hasUserImages = $data['hasUserImages'] ?? false;  // 是否包含用户上传的图片

        if (empty($question)) {
            sendResponse(false, '题目不能为空。', [], 400);
        }

        // ========== 服务器端默认 AI 模型调用次数限制 ==========
        $guestCallsFile = getFilePath('guest_api_calls.json');
        $guestCalls = [];
        if (file_exists($guestCallsFile)) {
            $guestCalls = json_decode(file_get_contents($guestCallsFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $guestCalls = [];
            }
        }

        $today = date('Y-m-d');
        $currentCalls = 0;
        if (isset($guestCalls[$clientIp]['calls'][$today])) {
            $currentCalls = $guestCalls[$clientIp]['calls'][$today];
        }

        $dailyLimit = max(0, (int)FREE_MODEL_DAILY_LIMIT);

        if ($currentCalls >= $dailyLimit) {
            sendResponse(false, "今日 AI 请求次数已用完（{$dailyLimit}次）。", ['limitReached' => true], 429);
        }

        // 记录调用次数
        $now = date('Y-m-d H:i:s');
        if (!isset($guestCalls[$clientIp])) {
            $guestCalls[$clientIp] = ['firstVisit' => $now, 'lastVisit' => $now, 'calls' => []];
        }
        $guestCalls[$clientIp]['lastVisit'] = $now;
        if (!isset($guestCalls[$clientIp]['calls'][$today])) {
            $guestCalls[$clientIp]['calls'][$today] = 0;
        }
        $guestCalls[$clientIp]['calls'][$today]++;
        file_put_contents($guestCallsFile, json_encode($guestCalls, JSON_PRETTY_PRINT));
        // ================================================

        // 根据是否有图片调整提示语
        $userImageNote = $hasUserImages ? "\n用户在回答中上传了图片，请结合用户上传的图片内容进行评分。" : "";

        $promptText = "请根据以下简答题的题目和参考答案，对用户的回答进行评分和反馈。
题目中的 {{图片X}} 表示下方的图片，请结合图片内容进行评分。{$userImageNote}
请给出1-5分的评分（1分最低，5分最高），并提供简要的评价和改进建议。
请严格按照以下JSON格式输出，不要包含任何额外文字：
{\"score\": <number>, \"evaluation\": \"<string>\", \"suggestion\": \"<string>\"}

题目: {$question}
参考答案: {$correctAnswer}
用户回答: " . ($userAnswer ?: '用户未作答');

        // 使用AI 模型
        if (USE_SILICONFLOW_FREE_MODEL && !empty(SILICONFLOW_FREE_API_KEY)) {
            $apiUrl = 'https://api.siliconflow.cn/v1/chat/completions';

            // 构建消息内容（支持图片）
            $content = [];
            $content[] = ['type' => 'text', 'text' => $promptText];

            // 添加图片
            foreach ($images as $imageData) {
                if (!empty($imageData)) {
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $imageData]
                    ];
                }
            }

            $payload = [
                'model' => SILICONFLOW_FREE_MODEL,
                'messages' => [
                    ['role' => 'user', 'content' => $content]
                ],
                'max_tokens' => 1024
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . SILICONFLOW_FREE_API_KEY
            ];
        } else {
            // GLM 模型不支持图片，使用纯文本
            $apiUrl = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
            $payload = [
                'model' => 'glm-4.5-flash',
                'messages' => [
                    ['role' => 'user', 'content' => $promptText]
                ]
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GLM_API_KEY
            ];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            sendResponse(false, '默认 AI 模型请求失败: ' . $error, [], 500);
        }

        if ($httpCode >= 400) {
            $errorBody = json_decode($response, true);
            $errorMessage = $errorBody['error']['message'] ?? '未知API错误';
            sendResponse(false, '默认 AI 模型 API 返回错误 (' . $httpCode . '): ' . $errorMessage, [], $httpCode);
        }

        $responseData = json_decode($response, true);
        $responseData['model_used'] = USE_SILICONFLOW_FREE_MODEL ? 'Qwen2.5-VL-7B' : 'GLM-4.5-flash';

        http_response_code(200);
        echo json_encode($responseData);
        exit();

    // =================================================================
    // 默认 AI 模型格式纠正 (未登录用户)
    // =================================================================
    case 'correct_format_free':
        $rawText = $data['rawText'] ?? '';

        if (empty($rawText)) {
            sendResponse(false, '待纠正文本不能为空。', [], 400);
        }

        // ========== 服务器端默认 AI 模型调用次数限制 ==========
        $guestCallsFile = getFilePath('guest_api_calls.json');
        $guestCalls = [];
        if (file_exists($guestCallsFile)) {
            $guestCalls = json_decode(file_get_contents($guestCallsFile), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $guestCalls = [];
            }
        }

        $today = date('Y-m-d');
        $currentCalls = 0;
        if (isset($guestCalls[$clientIp]['calls'][$today])) {
            $currentCalls = $guestCalls[$clientIp]['calls'][$today];
        }

        $dailyLimit = max(0, (int)FREE_MODEL_DAILY_LIMIT);

        if ($currentCalls >= $dailyLimit) {
            sendResponse(false, "今日 AI 请求次数已用完（{$dailyLimit}次）。", ['limitReached' => true], 429);
        }

        // 记录调用次数
        $now = date('Y-m-d H:i:s');
        if (!isset($guestCalls[$clientIp])) {
            $guestCalls[$clientIp] = ['firstVisit' => $now, 'lastVisit' => $now, 'calls' => []];
        }
        $guestCalls[$clientIp]['lastVisit'] = $now;
        if (!isset($guestCalls[$clientIp]['calls'][$today])) {
            $guestCalls[$clientIp]['calls'][$today] = 0;
        }
        $guestCalls[$clientIp]['calls'][$today]++;
        file_put_contents($guestCallsFile, json_encode($guestCalls, JSON_PRETTY_PRINT));
        // ================================================

        $prompt = <<<PROMPT
你的任务是作为一个严格的格式转换器。请将以下提供的文本严格转换为我指定的题目格式。

【核心要求 - 必须严格遵守】
⚠️ 绝对禁止省略、删除或更改任何题目内容！必须保留所有原始题目的完整文字！
⚠️ 绝对禁止省略、删除或更改任何答案内容！必须保留所有原始答案的完整内容！
⚠️ 唯一允许的修改是：格式调整和去除答案中的序号标记（如(1)(2)①②等）

【格式规则】
1. 每道题必须以题型标签开头：[单选]、[多选]、[判断]、[填空]、[词库填空]、[15选10]、[阅读题]、[简答]
2. 除单选、多选、判断、填空、选词填空、15选10、阅读题外的题目一律归类为 [简答]
3. 选择题选项必须以大写字母和点开头：A. B. C. D.
4. 答案行必须以"答案:"或"答案："开头
5. 填空题、词库填空、15选10 的多个答案统一用英文分号";"分隔
6. 每道题之间用"---"分隔
7. 题目和答案可以包含多行内容
8. 词库填空必须写成：
[词库填空]
题目说明
词库:
- A 单词1
- B 单词2
1. 句子一 ____。
2. 句子二 ____。
答案:单词1; 单词2
9. 15选10必须写成：
[15选10]
题目说明
词库:
- A 单词1
- B 单词2
篇章或长段落，其中空格写成 1) ____、2) ____ 这种形式
答案:单词1; 单词2; ...
10. 阅读题必须输出为“一个阅读大题 + 多个子题”的格式，不要把同一篇文章拆成多个独立阅读单题。推荐格式如下：
[阅读题]
篇章标题:
这里是篇章标题（没有可省略）
篇章:
这里是阅读材料全文
[单选]这里是第1小题
A.选项A
B.选项B
答案:A
[多选]这里是第2小题
A.选项A
B.选项B
C.选项C
答案:A,C
[判断]这里是第3小题
答案:正确
11. 阅读题的子题只能使用 [单选]、[多选]、[判断] 三种标签，不要使用 [阅读单选]、[阅读多选]、[阅读判断] 这种旧格式
12. 如果选词填空、15选10原文答案写的是字母（如 A、B、C），请保留字母或转换为对应单词，二者都可以，但必须保证答案顺序正确且不要丢题。

【输出要求】
- 只输出转换后的题目格式，不要任何解释、说明、前言或后语
- 不要输出 markdown 代码块标记
- 如果无法识别或转换，只输出"无法纠正"四个字

需要处理的文本如下：
---
{$rawText}
---
请开始转换。
PROMPT;

        // 使用默认 AI 模型
        if (USE_SILICONFLOW_FREE_MODEL && !empty(SILICONFLOW_FREE_API_KEY)) {
            $apiUrl = 'https://api.siliconflow.cn/v1/chat/completions';
            $payload = [
                'model' => SILICONFLOW_FREE_MODEL,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
                'max_tokens' => 4096
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . SILICONFLOW_FREE_API_KEY
            ];
        } else {
            $apiUrl = 'https://open.bigmodel.cn/api/paas/v4/chat/completions';
            $payload = [
                'model' => 'glm-4.5-flash',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ]
            ];
            $headers = [
                'Content-Type: application/json',
                'Authorization: Bearer ' . GLM_API_KEY
            ];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            sendResponse(false, '默认 AI 模型请求失败: ' . $error, [], 500);
        }

        if ($httpCode >= 400) {
            $errorBody = json_decode($response, true);
            $errorMessage = $errorBody['error']['message'] ?? '未知API错误';
            sendResponse(false, '默认 AI 模型 API 返回错误 (' . $httpCode . '): ' . $errorMessage, [], $httpCode);
        }

        $responseData = json_decode($response, true);
        $responseData['model_used'] = USE_SILICONFLOW_FREE_MODEL ? 'Qwen2.5-VL-7B' : 'GLM-4.5-flash';

        http_response_code(200);
        echo json_encode($responseData);
        exit();
    // =================================================================
    // 新增: 保存用户完整数据 (刷题数、答题记录、Token统计)
    // =================================================================
    case 'save_user_data':
        $username = $data['username'] ?? '';
        $apiKey = $data['apiKey'] ?? '';
        $userData = $data['userData'] ?? null;

        if (empty($username) || empty($apiKey)) {
            sendResponse(false, '用户名和API Key不能为空。', [], 400);
        }

        if (empty($userData)) {
            sendResponse(false, '用户数据不能为空。', [], 400);
        }

        // 验证用户API Key
        $users = [];
        if (file_exists($dataFilePath)) {
            $users = json_decode(file_get_contents($dataFilePath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $users = [];
            }
        }

        $foundUser = null;
        foreach ($users as $user) {
            if ($user['username'] === $username) {
                $foundUser = $user;
                break;
            }
        }

        if (!$foundUser || $foundUser['api_key'] !== $apiKey) {
            sendResponse(false, '用户验证失败。', [], 401);
        }

        // 保存用户数据
        $userDataFilePath = getFilePath('user_sync_data.json');
        $allUserData = [];

        if (file_exists($userDataFilePath)) {
            $allUserData = json_decode(file_get_contents($userDataFilePath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $allUserData = [];
            }
        }

        $existingUserData = $allUserData[$username] ?? [];
        $allUserData[$username] = array_merge($existingUserData, [
            'todayCount' => $userData['todayCount'] ?? null,
            'dailyGoal' => $userData['dailyGoal'] ?? 50,
            'totalQuestions' => $userData['totalQuestions'] ?? 0,
            'quizHistory' => $userData['quizHistory'] ?? [],
            'tokenStats' => $userData['tokenStats'] ?? null,
            'lastSync' => date('c')
        ]);

        if (file_put_contents($userDataFilePath, json_encode($allUserData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            sendResponse(true, '用户数据同步成功。');
        } else {
            $error = error_get_last();
            error_log("Failed to save user sync data: " . ($error['message'] ?? 'unknown error'));
            sendResponse(false, '保存用户数据失败，请稍后重试。', [], 500);
        }
        break;

    // =================================================================
    // 新增: 获取用户完整数据
    // =================================================================
    case 'get_user_data':
        $username = $data['username'] ?? '';
        $apiKey = $data['apiKey'] ?? '';

        if (empty($username) || empty($apiKey)) {
            sendResponse(false, '用户名和API Key不能为空。', [], 400);
        }

        // 验证用户API Key
        $users = [];
        if (file_exists($dataFilePath)) {
            $users = json_decode(file_get_contents($dataFilePath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $users = [];
            }
        }

        $foundUser = null;
        foreach ($users as $user) {
            if ($user['username'] === $username) {
                $foundUser = $user;
                break;
            }
        }

        if (!$foundUser || $foundUser['api_key'] !== $apiKey) {
            sendResponse(false, '用户验证失败。', [], 401);
        }

        // 获取用户数据
        $userDataFilePath = getFilePath('user_sync_data.json');
        $allUserData = [];

        if (file_exists($userDataFilePath)) {
            $allUserData = json_decode(file_get_contents($userDataFilePath), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $allUserData = [];
            }
        }

        if (isset($allUserData[$username])) {
            sendResponse(true, '用户数据获取成功。', ['userData' => $allUserData[$username]]);
        } else {
            sendResponse(true, '用户暂无同步数据。', ['userData' => null]);
        }
        break;

    // =================================================================
    // 登录
    // =================================================================
    case 'get_announcement':
        // 公开接口，不需要密码，供前端获取公告信息
        $settingsFile = getFilePath('system_settings.json');
        $announcement = ['enabled' => false, 'message' => '', 'type' => 'info'];

        if (file_exists($settingsFile)) {
            $settings = json_decode(file_get_contents($settingsFile), true);
            if ($settings && isset($settings['announcement'])) {
                $announcement = $settings['announcement'];
            }
        }

        sendResponse(true, '', ['announcement' => $announcement]);
        break;

    case 'get_tips':
        $tipsFile = getFilePath('tips_config.json');
        $tipsConfig = [
            'enabled' => false,
            'mode' => 'cycle',
            'interval' => 5000,
            'tips' => []
        ];

        if (file_exists($tipsFile)) {
            $loaded = json_decode(file_get_contents($tipsFile), true);
            if ($loaded) {
                $tipsConfig = array_merge($tipsConfig, $loaded);
            }
        }

        // 只返回启用的提示
        $enabledTips = array_filter($tipsConfig['tips'], function ($tip) {
            return $tip['enabled'] ?? true;
        });

        sendResponse(true, '获取提示成功', [
            'enabled' => $tipsConfig['enabled'],
            'mode' => $tipsConfig['mode'],
            'interval' => $tipsConfig['interval'],
            'tips' => array_values($enabledTips)
        ]);
        break;

    // 用户可读取提示配置
    case 'user_upload_quiz':
        $quizName = $data['quizName'] ?? '';
        $quizData = $data['quizData'] ?? null;
        $images = $data['images'] ?? [];
        $userValidation = validateQuizUserRequest($data, $dataFilePath, $sessionFilePath);
        if (!$userValidation['valid']) {
            sendResponse(false, '验证失败', [], 401);
        }

        $sanitizedUsername = $userValidation['username'];
        $baseQuizName = sanitizeQuizName($quizName);
        $storedQuizName = buildOwnedQuizName($baseQuizName, $sanitizedUsername);
        if ($baseQuizName === '' || !is_array($quizData) || $quizData === []) {
            sendResponse(false, '题库名称或题库数据无效', [], 400);
        }
        $maxQuestions = defined('USER_MAX_QUESTIONS_PER_QUIZ') ? max(1, (int)USER_MAX_QUESTIONS_PER_QUIZ) : 500;
        $maxImages = defined('USER_MAX_IMAGES_PER_QUIZ') ? max(0, (int)USER_MAX_IMAGES_PER_QUIZ) : 20;
        if (count($quizData) > $maxQuestions || !is_array($images) || count($images) > $maxImages) {
            sendResponse(false, "每个题库最多允许 {$maxQuestions} 道题和 {$maxImages} 张图片。", [], 400);
        }

        $targetDir = "data/cloud_quizzes/users/{$sanitizedUsername}/";
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            sendResponse(false, '无法创建题库目录', [], 500);
        }
        $filePath = $targetDir . $storedQuizName . '.json';
        $relativeFilePath = str_replace('data/cloud_quizzes/', '', $filePath);
        $isOverwrite = file_exists($filePath);

        try {
            $db = Database::getInstance();
            $quota = $db->getUserQuota($sanitizedUsername);
            $maxQuizzes = defined('USER_MAX_CLOUD_QUIZZES') ? max(1, (int)USER_MAX_CLOUD_QUIZZES) : (int)($quota['max_quizzes'] ?? 10);
            if (!$isOverwrite && (int)($quota['quiz_count'] ?? 0) >= $maxQuizzes) {
                sendResponse(false, "已达到题库上传限制（最多 {$maxQuizzes} 个）", [], 429);
            }
        } catch (Exception $e) {
            error_log('Could not read quiz quota: ' . $e->getMessage());
        }

        $reviewResult = ['passed' => true, 'reason' => 'AI review is disabled'];
        if (defined('ENABLE_QUIZ_AI_REVIEW') && ENABLE_QUIZ_AI_REVIEW) {
            $reviewResult = ContentReviewer::reviewQuiz($quizData, $storedQuizName);
            if (empty($reviewResult['passed'])) {
                sendResponse(false, '题库未通过 AI 内容审核：' . ($reviewResult['reason'] ?? '审核失败'), [
                    'reviewFailed' => true,
                    'reason' => $reviewResult['reason'] ?? '审核失败'
                ], 403);
            }
        }

        $quizContent = [
            'quizData' => $quizData,
            'images' => $images,
            'metadata' => [
                'owner' => $sanitizedUsername,
                'baseName' => $baseQuizName,
                'displayName' => $storedQuizName,
                'createdAt' => date('c'),
                'questionCount' => count($quizData),
                'imageCount' => count($images),
                'status' => 'active'
            ]
        ];

        if (file_put_contents($filePath, json_encode($quizContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            sendResponse(false, '题库保存失败', [], 500);
        }

        $quizId = null;
        try {
            $db = Database::getInstance();
            if (!$isOverwrite) {
                $quizId = $db->createQuiz($storedQuizName, $sanitizedUsername, 'private', 'active', count($quizData), count($images), $relativeFilePath);
                $quota = $db->getUserQuota($sanitizedUsername);
                $db->updateUserQuota($sanitizedUsername, ($quota['quiz_count'] ?? 0) + 1, ($quota['total_questions'] ?? 0) + count($quizData), ($quota['total_images'] ?? 0) + count($images));
            }
        } catch (Exception $e) {
            error_log('Could not update quiz metadata: ' . $e->getMessage());
        }

        sendResponse(true, $isOverwrite ? '题库更新成功' : '题库上传成功', [
            'quizId' => $quizId,
            'quizName' => $storedQuizName,
            'baseQuizName' => $baseQuizName,
            'quizPath' => $relativeFilePath,
            'visibility' => 'private',
            'reviewPassed' => true,
            'isOverwrite' => $isOverwrite
        ]);
        break;

    case 'user_list_quizzes':
        $publicQuizzes = [];
        $userQuizzes = [];
        $publicDir = 'data/cloud_quizzes/public/';
        $legacyDir = 'data/cloud_quizzes/';
        foreach (array_merge(glob($publicDir . '*.json') ?: [], glob($legacyDir . '*.json') ?: []) as $file) {
            if (dirname($file) === rtrim($legacyDir, '/')) {
                $name = pathinfo($file, PATHINFO_FILENAME);
            } else {
                $name = pathinfo($file, PATHINFO_FILENAME);
            }
            $content = loadJsonFile($file, []);
            $quizData = $content['quizData'] ?? $content;
            $publicQuizzes[] = [
                'name' => $name,
                'visibility' => 'public',
                'path' => str_starts_with($file, $publicDir) ? 'public/' . basename($file) : basename($file),
                'accessMode' => getQuizAccessMode($content),
                'questionCount' => is_array($quizData) ? count($quizData) : 0,
                'editable' => false,
                'shareable' => isCloudQuizShareable($content, $file)
            ];
        }

        $userValidation = validateQuizUserRequest($data, $dataFilePath, $sessionFilePath);
        if ($userValidation['valid']) {
            $username = $userValidation['username'];
            $userDir = "data/cloud_quizzes/users/{$username}/";
            foreach (glob($userDir . '*.json') ?: [] as $file) {
                if (basename($file) === '__meta.json') continue;
                $content = loadJsonFile($file, []);
                $quizData = $content['quizData'] ?? $content;
                $userQuizzes[] = [
                    'name' => pathinfo($file, PATHINFO_FILENAME),
                    'baseName' => $content['metadata']['baseName'] ?? pathinfo($file, PATHINFO_FILENAME),
                    'visibility' => 'private',
                    'status' => $content['metadata']['status'] ?? 'active',
                    'path' => "users/{$username}/" . basename($file),
                    'questionCount' => is_array($quizData) ? count($quizData) : 0,
                    'editable' => true,
                    'shareable' => isCloudQuizShareable($content, $file)
                ];
            }
        }

        sendResponse(true, '获取题库列表成功', [
            'publicQuizzes' => $publicQuizzes,
            'userQuizzes' => $userQuizzes
        ]);
        break;

    case 'user_get_quiz':
        $quizName = sanitizeQuizName($data['quizName'] ?? '');
        $quizPath = trim((string)($data['quizPath'] ?? ''));
        $userValidation = validateQuizUserRequest($data, $dataFilePath, $sessionFilePath);
        $filePath = null;

        if ($quizPath !== '') {
            $cleanPath = ltrim(str_replace('\\', '/', $quizPath), '/');
            if (strpos($cleanPath, '..') !== false) sendResponse(false, '题库路径无效', [], 400);
            $filePath = 'data/cloud_quizzes/' . $cleanPath;
        } elseif ($quizName !== '') {
            $possiblePaths = [
                'data/cloud_quizzes/public/' . $quizName . '.json',
                'data/cloud_quizzes/' . $quizName . '.json'
            ];
            if ($userValidation['valid']) {
                array_unshift($possiblePaths, 'data/cloud_quizzes/users/' . $userValidation['username'] . '/' . $quizName . '.json');
            }
            foreach ($possiblePaths as $path) {
                if (file_exists($path)) { $filePath = $path; break; }
            }
        }
        if (!$filePath || !is_file($filePath)) sendResponse(false, '题库不存在', [], 404);

        $normalizedPath = str_replace('\\', '/', $filePath);
        if (strpos($normalizedPath, '/users/') !== false) {
            $owner = extractQuizOwnerFromPath($normalizedPath);
            if (!$userValidation['valid']) sendResponse(false, '需要登录才能访问此题库', [], 401);
            if ($userValidation['username'] !== $owner) sendResponse(false, '无权访问此题库', [], 403);
        }

        $content = loadJsonFile($filePath, []);
        if (!$content) sendResponse(false, '题库数据损坏', [], 500);
        $accessMode = getQuizAccessMode($content);
        if (strpos($normalizedPath, '/users/') === false && $accessMode === 'login_required' && !$userValidation['valid']) {
            sendResponse(false, '该题库需要登录后访问。', ['requiresLogin' => true], 401);
        }
        if (isset($content['quizData'])) {
            sendResponse(true, '加载成功', array_merge($content, ['accessMode' => $accessMode]));
        }
        sendResponse(true, '加载成功', ['quizData' => $content, 'images' => [], 'accessMode' => $accessMode]);
        break;

    case 'user_delete_quiz':
        $userValidation = validateQuizUserRequest($data, $dataFilePath, $sessionFilePath);
        if (!$userValidation['valid']) sendResponse(false, '验证失败', [], 401);
        $quizPath = trim((string)($data['quizPath'] ?? ''));
        if ($quizPath === '') {
            $quizName = sanitizeQuizName($data['quizName'] ?? '');
            $quizPath = 'users/' . $userValidation['username'] . '/' . $quizName . '.json';
        }
        $cleanPath = ltrim(str_replace('\\', '/', $quizPath), '/');
        if (strpos($cleanPath, '..') !== false || strpos($cleanPath, 'users/' . $userValidation['username'] . '/') !== 0) {
            sendResponse(false, '只能删除自己上传的云题库', [], 403);
        }
        $filePath = 'data/cloud_quizzes/' . $cleanPath;
        if (!is_file($filePath)) sendResponse(false, '题库不存在', [], 404);
        $content = loadJsonFile($filePath, []);
        $quizData = $content['quizData'] ?? $content;
        $deletedQuestions = is_array($quizData) ? count($quizData) : 0;
        $deletedImages = is_array($content['images'] ?? null) ? count($content['images']) : 0;
        if (!unlink($filePath)) sendResponse(false, '删除失败', [], 500);
        try {
            $db = Database::getInstance();
            $quota = $db->getUserQuota($userValidation['username']);
            $db->updateUserQuota(
                $userValidation['username'],
                max(0, (int)($quota['quiz_count'] ?? 0) - 1),
                max(0, (int)($quota['total_questions'] ?? 0) - $deletedQuestions),
                max(0, (int)($quota['total_images'] ?? 0) - $deletedImages)
            );
        } catch (Exception $e) {
            error_log('Could not update quiz quota after deletion: ' . $e->getMessage());
        }
        sendResponse(true, '题库删除成功');
        break;

    case 'user_update_quiz':
        $userValidation = validateQuizUserRequest($data, $dataFilePath, $sessionFilePath);
        if (!$userValidation['valid']) sendResponse(false, '验证失败', [], 401);
        $quizPath = trim((string)($data['quizPath'] ?? ''));
        $quizData = $data['quizData'] ?? null;
        $images = $data['images'] ?? [];
        if ($quizPath === '' || !is_array($quizData) || $quizData === [] || !is_array($images)) {
            sendResponse(false, '题库路径或题库数据无效', [], 400);
        }
        $cleanPath = ltrim(str_replace('\\', '/', $quizPath), '/');
        if (strpos($cleanPath, '..') !== false || strpos($cleanPath, 'users/' . $userValidation['username'] . '/') !== 0) {
            sendResponse(false, '只能修改自己上传的云题库', [], 403);
        }
        $maxQuestions = defined('USER_MAX_QUESTIONS_PER_QUIZ') ? max(1, (int)USER_MAX_QUESTIONS_PER_QUIZ) : 500;
        $maxImages = defined('USER_MAX_IMAGES_PER_QUIZ') ? max(0, (int)USER_MAX_IMAGES_PER_QUIZ) : 20;
        if (count($quizData) > $maxQuestions || count($images) > $maxImages) {
            sendResponse(false, "每个题库最多允许 {$maxQuestions} 道题和 {$maxImages} 张图片。", [], 400);
        }

        $filePath = 'data/cloud_quizzes/' . $cleanPath;
        if (!is_file($filePath)) sendResponse(false, '题库不存在', [], 404);
        $currentContent = loadJsonFile($filePath, []);
        $storedQuizName = pathinfo($filePath, PATHINFO_FILENAME);
        $baseQuizName = $currentContent['metadata']['baseName'] ?? $storedQuizName;
        if (defined('ENABLE_QUIZ_AI_REVIEW') && ENABLE_QUIZ_AI_REVIEW) {
            $reviewResult = ContentReviewer::reviewQuiz($quizData, $storedQuizName);
            if (empty($reviewResult['passed'])) {
                sendResponse(false, '题库未通过 AI 内容审核：' . ($reviewResult['reason'] ?? '审核失败'), [
                    'reviewFailed' => true,
                    'reason' => $reviewResult['reason'] ?? '审核失败'
                ], 403);
            }
        }

        $updatedContent = [
            'quizData' => $quizData,
            'images' => $images,
            'metadata' => array_merge($currentContent['metadata'] ?? [], [
                'owner' => $userValidation['username'],
                'baseName' => $baseQuizName,
                'displayName' => $storedQuizName,
                'updatedAt' => date('c'),
                'questionCount' => count($quizData),
                'imageCount' => count($images),
                'status' => 'active'
            ])
        ];
        if (file_put_contents($filePath, json_encode($updatedContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            sendResponse(false, '题库更新失败', [], 500);
        }
        try {
            $db = Database::getInstance();
            $quota = $db->getUserQuota($userValidation['username']);
            $oldQuizData = $currentContent['quizData'] ?? $currentContent;
            $oldQuestionCount = is_array($oldQuizData) ? count($oldQuizData) : 0;
            $oldImageCount = is_array($currentContent['images'] ?? null) ? count($currentContent['images']) : 0;
            $db->updateUserQuota(
                $userValidation['username'],
                (int)($quota['quiz_count'] ?? 0),
                max(0, (int)($quota['total_questions'] ?? 0) - $oldQuestionCount + count($quizData)),
                max(0, (int)($quota['total_images'] ?? 0) - $oldImageCount + count($images))
            );
        } catch (Exception $e) {
            error_log('Could not update quiz quota after edit: ' . $e->getMessage());
        }
        sendResponse(true, '题库更新成功', [
            'quizName' => $storedQuizName,
            'baseQuizName' => $baseQuizName,
            'quizPath' => $cleanPath,
            'reviewPassed' => true
        ]);
        break;

    case 'user_rename_quiz':
        $userValidation = validateQuizUserRequest($data, $dataFilePath, $sessionFilePath);
        if (!$userValidation['valid']) sendResponse(false, '验证失败', [], 401);
        $quizPath = trim((string)($data['quizPath'] ?? ''));
        $newName = sanitizeQuizName($data['newName'] ?? '');
        if ($quizPath === '' || $newName === '') sendResponse(false, '题库路径或新名称无效', [], 400);
        $cleanPath = ltrim(str_replace('\\', '/', $quizPath), '/');
        if (strpos($cleanPath, '..') !== false || strpos($cleanPath, 'users/' . $userValidation['username'] . '/') !== 0) {
            sendResponse(false, '只能重命名自己上传的云题库', [], 403);
        }
        $filePath = 'data/cloud_quizzes/' . $cleanPath;
        if (!is_file($filePath)) sendResponse(false, '题库不存在', [], 404);
        $targetName = buildOwnedQuizName($newName, $userValidation['username']);
        $targetPath = dirname($filePath) . '/' . $targetName . '.json';
        if ($targetPath !== $filePath && file_exists($targetPath)) sendResponse(false, '目标题库名称已存在', [], 409);
        $content = loadJsonFile($filePath, []);
        if (!empty($content['metadata']) && is_array($content['metadata'])) {
            $content['metadata']['baseName'] = $newName;
            $content['metadata']['displayName'] = $targetName;
        }
        if (file_put_contents($filePath, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
            sendResponse(false, '题库重命名前保存失败', [], 500);
        }
        if ($targetPath !== $filePath && !rename($filePath, $targetPath)) sendResponse(false, '题库重命名失败', [], 500);
        sendResponse(true, '题库重命名成功', [
            'quizName' => $targetName,
            'baseQuizName' => $newName,
            'quizPath' => str_replace('data/cloud_quizzes/', '', $targetPath),
            'isPublic' => false
        ]);
        break;

    case 'user_get_quota':
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        $userValidation = validateUser($username, $password, $dataFilePath);
        if (!$userValidation['valid']) {
            sendResponse(false, $userValidation['error'], [], 401);
        }

        try {
            $db = Database::getInstance();
            $quota = $db->getUserQuota(sanitizeUsername($username));
            sendResponse(true, '获取配额成功', ['quota' => $quota]);
        } catch (Exception $e) {
            sendResponse(true, '获取配额成功', [
                'quota' => [
                    'quiz_count' => 0,
                    'max_quizzes' => defined('USER_MAX_CLOUD_QUIZZES') ? max(1, (int)USER_MAX_CLOUD_QUIZZES) : 10,
                    'total_questions' => 0,
                    'total_images' => 0
                ]
            ]);
        }
        break;

    // ===================== 链接分享功能 =====================

    // 创建分享链接
    case 'create_share_link':
        $userValidation = validateQuizUserRequest($data, $dataFilePath, $sessionFilePath);
        if (!$userValidation['valid']) sendResponse(false, '验证失败', [], 401);
        $quizName = sanitizeQuizName($data['quizName'] ?? '');
        $quizPathInput = trim((string)($data['quizPath'] ?? ''));
        $cleanPath = $quizPathInput !== '' ? ltrim(str_replace('\\', '/', $quizPathInput), '/') : 'users/' . $userValidation['username'] . '/' . $quizName . '.json';
        if (strpos($cleanPath, '..') !== false || strpos($cleanPath, 'users/' . $userValidation['username'] . '/') !== 0) {
            sendResponse(false, '只能分享自己上传的云题库', [], 403);
        }
        $quizPath = 'data/cloud_quizzes/' . $cleanPath;
        if (!is_file($quizPath)) sendResponse(false, '题库不存在', [], 404);
        $quizContent = loadJsonFile($quizPath, []);
        if (!$quizContent || !isCloudQuizShareable($quizContent, $quizPath)) {
            sendResponse(false, '该题库当前不可通过分享链接访问', [], 403);
        }
        $expiresIn = min(365, max(1, (int)($data['expiresIn'] ?? 7)));
        $shareToken = bin2hex(random_bytes(16));
        $expiresAt = date('c', strtotime("+{$expiresIn} days"));
        $sharesFile = 'data/quiz_shares.json';
        $shares = loadJsonFile($sharesFile, []);
        $shares[$shareToken] = [
            'quizPath' => $quizPath,
            'quizName' => pathinfo($quizPath, PATHINFO_FILENAME),
            'owner' => $userValidation['username'],
            'createdAt' => date('c'),
            'expiresAt' => $expiresAt,
            'accessCount' => 0
        ];
        if (!saveJsonFile($sharesFile, $shares)) sendResponse(false, '分享链接创建失败', [], 500);
        sendResponse(true, '分享链接创建成功', ['shareToken' => $shareToken, 'expiresAt' => $expiresAt]);
        break;

    case 'get_shared_quiz':
        $shareToken = $data['shareToken'] ?? '';

        if (empty($shareToken)) {
            sendResponse(false, '无效的分享链接', [], 400);
        }

        $sharesFile = 'data/quiz_shares.json';
        if (!file_exists($sharesFile)) {
            sendResponse(false, '分享链接不存在', [], 404);
        }

        $shares = json_decode(file_get_contents($sharesFile), true) ?: [];

        if (!isset($shares[$shareToken])) {
            sendResponse(false, '分享链接不存在或已失效', [], 404);
        }

        $shareInfo = $shares[$shareToken];

        // 检查过期
        if (strtotime($shareInfo['expiresAt']) < time()) {
            unset($shares[$shareToken]);
            file_put_contents($sharesFile, json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            sendResponse(false, '分享链接已过期', [], 410);
        }

        // 读取题库
        if (!file_exists($shareInfo['quizPath'])) {
            sendResponse(false, '题库已被删除', [], 404);
        }

        $content = loadJsonFile($shareInfo['quizPath'], []);
        if (empty($content)) {
            sendResponse(false, '题库数据损坏', [], 500);
        }
        if (!isCloudQuizShareable($content, $shareInfo['quizPath'])) {
            unset($shares[$shareToken]);
            file_put_contents($sharesFile, json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            sendResponse(false, '该题库当前不可通过分享链接访问', [], 410);
        }

        // 更新访问计数
        $shares[$shareToken]['accessCount']++;
        $shares[$shareToken]['lastAccessAt'] = date('c');
        file_put_contents($sharesFile, json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        sendResponse(true, '获取分享题库成功', [
            'quizName' => $shareInfo['quizName'],
            'owner' => $shareInfo['owner'],
            'quizData' => $content['quizData'] ?? $content,
            'images' => $content['images'] ?? []
        ]);
        break;

    // 获取用户的分享链接列表
    case 'list_share_links':
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';

        $userValidation = validateUser($username, $password, $dataFilePath);
        if (!$userValidation['valid']) {
            sendResponse(false, $userValidation['error'], [], 401);
        }

        $sanitizedUsername = sanitizeUsername($username);

        $sharesFile = 'data/quiz_shares.json';
        $shares = [];
        if (file_exists($sharesFile)) {
            $shares = json_decode(file_get_contents($sharesFile), true) ?: [];
        }

        $userShares = [];
        foreach ($shares as $token => $info) {
            if ($info['owner'] === $sanitizedUsername) {
                $userShares[] = [
                    'token' => $token,
                    'quizName' => $info['quizName'],
                    'createdAt' => $info['createdAt'],
                    'expiresAt' => $info['expiresAt'],
                    'accessCount' => $info['accessCount'] ?? 0,
                    'expired' => strtotime($info['expiresAt']) < time()
                ];
            }
        }

        sendResponse(true, '获取分享列表成功', ['shares' => $userShares]);
        break;

    // 删除分享链接
    case 'delete_share_link':
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $shareToken = $data['shareToken'] ?? '';

        $userValidation = validateUser($username, $password, $dataFilePath);
        if (!$userValidation['valid']) {
            sendResponse(false, $userValidation['error'], [], 401);
        }

        $sanitizedUsername = sanitizeUsername($username);

        $sharesFile = 'data/quiz_shares.json';
        if (!file_exists($sharesFile)) {
            sendResponse(false, '分享链接不存在', [], 404);
        }

        $shares = json_decode(file_get_contents($sharesFile), true) ?: [];

        if (!isset($shares[$shareToken]) || $shares[$shareToken]['owner'] !== $sanitizedUsername) {
            sendResponse(false, '分享链接不存在或无权删除', [], 404);
        }

        unset($shares[$shareToken]);
        file_put_contents($sharesFile, json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        sendResponse(true, '分享链接已删除');
        break;

    // 获取用户通知（审核通过的题库）
    case 'get_user_notifications':
        $username = $data['username'] ?? '';
        $apiKey = $data['apiKey'] ?? '';

        if (empty($username) || empty($apiKey)) {
            sendResponse(false, '需要登录', [], 401);
        }

        // 验证用户
        $users = json_decode(file_get_contents($dataFilePath), true) ?: [];
        $found = false;
        foreach ($users as $user) {
            if ($user['username'] === $username && $user['api_key'] === $apiKey) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            sendResponse(false, '验证失败', [], 401);
        }

        $sanitizedUsername = sanitizeUsername($username);
        $notificationsFile = "data/notifications/{$sanitizedUsername}.json";

        $notifications = [];
        if (file_exists($notificationsFile)) {
            $notifications = json_decode(file_get_contents($notificationsFile), true) ?: [];
        }

        // 只返回未读通知
        $unreadNotifications = array_filter($notifications, fn($n) => !($n['read'] ?? false));

        sendResponse(true, '获取通知成功', ['notifications' => array_values($unreadNotifications)]);
        break;

    // 标记通知已读
    case 'mark_notifications_read':
        $username = $data['username'] ?? '';
        $apiKey = $data['apiKey'] ?? '';
        $notificationIds = $data['notificationIds'] ?? [];

        if (empty($username) || empty($apiKey)) {
            sendResponse(false, '需要登录', [], 401);
        }

        // 验证用户
        $users = json_decode(file_get_contents($dataFilePath), true) ?: [];
        $found = false;
        foreach ($users as $user) {
            if ($user['username'] === $username && $user['api_key'] === $apiKey) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            sendResponse(false, '验证失败', [], 401);
        }

        $sanitizedUsername = sanitizeUsername($username);
        $notificationsFile = "data/notifications/{$sanitizedUsername}.json";

        if (file_exists($notificationsFile)) {
            $notifications = json_decode(file_get_contents($notificationsFile), true) ?: [];
            foreach ($notifications as &$n) {
                if (empty($notificationIds) || in_array($n['id'], $notificationIds)) {
                    $n['read'] = true;
                }
            }
            file_put_contents($notificationsFile, json_encode($notifications, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        sendResponse(true, '通知已标记为已读');
        break;

    default:
        sendResponse(false, '无效的操作类型。', [], 400);
        break;
}
?>
