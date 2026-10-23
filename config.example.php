<?php
/**
 * Copy this file to config.php and replace the placeholder values.
 * Never commit config.php or real credentials.
 */

// SMTP credentials used for email verification.
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_USERNAME', 'user@example.com');
define('SMTP_PASSWORD', 'replace-with-your-smtp-app-password');
define('SMTP_SECURE', 'tls');
define('SMTP_PORT', 587);
define('MAIL_FROM', 'user@example.com');
define('MAIL_FROM_NAME', 'RandomQuiz.Pro');
define('VERIFICATION_CODE_EXPIRY', 600);

// Server-side AI provider configuration. Leave a key empty to disable that provider.
define('USE_SILICONFLOW_FREE_MODEL', true);
define('SILICONFLOW_FREE_API_KEY', 'replace-with-your-provider-api-key');
define('SILICONFLOW_FREE_MODEL', 'Qwen/Qwen2.5-VL-7B-Instruct');
define('SILICONFLOW_FREE_TEXT_MODEL', 'Qwen/Qwen2.5-7B-Instruct');
define('SILICONFLOW_FREE_VISION_MODEL', 'Qwen/Qwen2.5-VL-7B-Instruct');
define('USE_DUAL_MODEL', false);
define('FREE_TEXT_MODEL', 'Qwen/Qwen2.5-7B-Instruct');
define('FREE_VISION_MODEL', 'Qwen/Qwen2.5-VL-7B-Instruct');
define('GLM_API_KEY', '');

// Optional models used by browser-side AI requests. Provider keys are supplied
// by each user in the account settings and stay in that user's account record.
define('CHAT_VISION_MODEL', 'Qwen/Qwen2.5-VL-72B-Instruct');
define('REGISTERED_TEXT_MODEL', 'deepseek-ai/DeepSeek-V3');

// Self-hosted request and storage limits.
define('FREE_MODEL_DAILY_LIMIT', 80);
define('REQUEST_RATE_LIMIT_WINDOW_SECONDS', 60);
define('REQUEST_RATE_LIMIT_MAX_REQUESTS', 5);
define('USER_MAX_CLOUD_QUIZZES', 10);
define('USER_MAX_QUESTIONS_PER_QUIZ', 500);
define('USER_MAX_IMAGES_PER_QUIZ', 20);

// AI content review is optional. Enabling it requires a configured review provider.
define('ENABLE_QUIZ_AI_REVIEW', false);

// Same-origin requests work without CORS. Add exact origins only when the
// frontend is intentionally hosted on a different origin.
define('CORS_ALLOWED_ORIGINS', []);
