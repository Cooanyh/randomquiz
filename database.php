<?php
/**
 * 数据库操作类 (JSON文件版本)
 * 替代SQLite的轻量级实现
 */

class Database
{
    private static $instance = null;
    private $dataDir;

    private function __construct()
    {
        $this->dataDir = __DIR__ . '/data/';

        // 确保数据目录存在
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ========== 题库操作 ==========

    private function getQuizzesFile()
    {
        return $this->dataDir . 'quizzes_meta.json';
    }

    private function loadQuizzes()
    {
        $file = $this->getQuizzesFile();
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true) ?: [];
        }
        return [];
    }

    private function saveQuizzes($quizzes)
    {
        file_put_contents($this->getQuizzesFile(), json_encode($quizzes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 创建题库记录
     */
    public function createQuiz($name, $owner, $visibility, $status, $questionCount, $imageCount, $filePath)
    {
        $quizzes = $this->loadQuizzes();
        $id = time() . rand(1000, 9999);

        $quizzes[$id] = [
            'id' => $id,
            'name' => $name,
            'owner' => $owner,
            'visibility' => $visibility,
            'status' => $status,
            'question_count' => $questionCount,
            'image_count' => $imageCount,
            'file_path' => $filePath,
            'created_at' => date('c'),
            'updated_at' => date('c')
        ];

        $this->saveQuizzes($quizzes);
        return $id;
    }

    /**
     * 获取用户的题库列表
     */
    public function getUserQuizzes($username)
    {
        $quizzes = $this->loadQuizzes();
        return array_filter($quizzes, function ($q) use ($username) {
            return $q['owner'] === $username && $q['status'] !== 'deleted';
        });
    }

    /**
     * 获取公开题库列表
     */
    public function getPublicQuizzes()
    {
        $quizzes = $this->loadQuizzes();
        return array_filter($quizzes, function ($q) {
            return $q['visibility'] === 'public' && $q['status'] === 'active';
        });
    }

    /**
     * 删除题库记录
     */
    public function deleteQuiz($quizId)
    {
        $quizzes = $this->loadQuizzes();
        if (isset($quizzes[$quizId])) {
            $quizzes[$quizId]['status'] = 'deleted';
            $quizzes[$quizId]['updated_at'] = date('c');
            $this->saveQuizzes($quizzes);
        }
    }

    /**
     * 根据ID获取题库
     */
    public function getQuizById($quizId)
    {
        $quizzes = $this->loadQuizzes();
        return $quizzes[$quizId] ?? null;
    }

    /**
     * 根据路径获取题库
     */
    public function getQuizByPath($filePath)
    {
        $quizzes = $this->loadQuizzes();
        foreach ($quizzes as $quiz) {
            if ($quiz['file_path'] === $filePath) {
                return $quiz;
            }
        }
        return null;
    }

    // ========== 用户配额操作 ==========

    private function getQuotasFile()
    {
        return $this->dataDir . 'user_quotas.json';
    }

    private function loadQuotas()
    {
        $file = $this->getQuotasFile();
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true) ?: [];
        }
        return [];
    }

    private function saveQuotas($quotas)
    {
        file_put_contents($this->getQuotasFile(), json_encode($quotas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 获取用户配额
     */
    public function getUserQuota($username)
    {
        $quotas = $this->loadQuotas();

        $maxQuizzes = defined('USER_MAX_CLOUD_QUIZZES') ? max(1, (int)USER_MAX_CLOUD_QUIZZES) : 10;
        if (!isset($quotas[$username])) {
            $quotas[$username] = [
                'username' => $username,
                'quiz_count' => 0,
                'max_quizzes' => $maxQuizzes,
                'total_questions' => 0,
                'total_images' => 0,
                'updated_at' => date('c')
            ];
        } else {
            $quotas[$username]['max_quizzes'] = $maxQuizzes;
        }
        $this->saveQuotas($quotas);
        return $quotas[$username];
    }

    /**
     * 更新用户配额
     */
    public function updateUserQuota($username, $quizCount, $totalQuestions, $totalImages)
    {
        $quotas = $this->loadQuotas();
        $quotas[$username] = [
            'username' => $username,
            'quiz_count' => $quizCount,
            'max_quizzes' => defined('USER_MAX_CLOUD_QUIZZES') ? max(1, (int)USER_MAX_CLOUD_QUIZZES) : 10,
            'total_questions' => $totalQuestions,
            'total_images' => $totalImages,
            'updated_at' => date('c')
        ];
        $this->saveQuotas($quotas);
    }

}
