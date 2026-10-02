<?php

namespace app\services\Admin;

use app\support\AdminAudit;
use app\support\AdminAuth;
use app\support\GameConfig;
use support\Request;
use Throwable;
use Webman\Http\Response;

/**
 * 游戏 JSON 配置管理：读取、校验、原子写入和备份回滚。
 */
class AdminConfigService
{
    private const MAX_CONTENT_SIZE = 512 * 1024;
    private const MAX_BACKUPS = 20;

    public function list(Request $request): Response
    {
        $files = $this->configFiles();
        $names = GameConfig::get('labels')['config_names'] ?? [];
        $rows = [];
        foreach ($files as $name => $file) {
            $rows[] = [
                'name' => $name,
                'label' => (string)($names[$name] ?? $name),
                'file' => basename($file),
                'size' => (int)filesize($file),
                'updated_at' => (int)filemtime($file),
                'sha256' => hash_file('sha256', $file),
            ];
        }
        return $this->ok(['configs' => $rows]);
    }

    public function show(Request $request, string $name): Response
    {
        $file = $this->findConfig($name);
        if ($file === null) {
            return $this->fail(6008, '配置不存在');
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return $this->fail(6011, '配置读取失败');
        }
        return $this->ok([
            'name' => $name,
            'file' => basename($file),
            'content' => $content,
            'updated_at' => (int)filemtime($file),
            'sha256' => hash('sha256', $content),
            'backups' => $this->backupRows($name),
        ]);
    }

    public function save(Request $request, string $name): Response
    {
        $admin = AdminAuth::user($request);
        if ($admin === null) {
            return $this->fail(6001, '未登录或登录已失效');
        }
        $file = $this->findConfig($name);
        if ($file === null) {
            return $this->fail(6008, '配置不存在');
        }
        $content = (string)$request->input('content', '');
        $password = (string)$request->input('password', '');
        $reason = trim((string)$request->input('reason', ''));

        if ($error = $this->validateRequest($name, $content, $reason)) {
            return $error;
        }
        if ($error = (new AdminAuthService())->confirmSensitivePassword($request, $admin, $password)) {
            return $error;
        }

        $before = file_get_contents($file);
        if ($before === false) {
            return $this->fail(6011, '配置读取失败');
        }
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $formatted = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        $backup = $this->backup($name, $before);
        if ($backup === null || !$this->writeAtomic($file, $formatted)) {
            return $this->fail(6011, '配置写入失败');
        }

        AdminAudit::operation(
            (int)$admin['id'],
            'config_save',
            'config',
            $name,
            $reason,
            [
                'before_sha256' => hash('sha256', $before),
                'after_sha256' => hash('sha256', $formatted),
                'backup' => $backup,
            ],
            $request
        );
        return $this->ok([
            'name' => $name,
            'sha256' => hash('sha256', $formatted),
            'updated_at' => (int)filemtime($file),
        ]);
    }

    public function backups(Request $request, string $name): Response
    {
        if ($this->findConfig($name) === null) {
            return $this->fail(6008, '配置不存在');
        }
        return $this->ok(['backups' => $this->backupRows($name)]);
    }

    public function restore(Request $request, string $name): Response
    {
        $admin = AdminAuth::user($request);
        if ($admin === null) {
            return $this->fail(6001, '未登录或登录已失效');
        }
        $file = $this->findConfig($name);
        if ($file === null) {
            return $this->fail(6008, '配置不存在');
        }
        $backupName = basename((string)$request->input('backup', ''));
        $password = (string)$request->input('password', '');
        $reason = trim((string)$request->input('reason', ''));
        if ($backupName === '' || !preg_match('/^[0-9]{14}-[a-f0-9]{8}\.json$/', $backupName)) {
            return $this->fail(6012, '备份不存在');
        }
        if ($error = $this->validateReason($reason)) {
            return $error;
        }
        if ($error = (new AdminAuthService())->confirmSensitivePassword($request, $admin, $password)) {
            return $error;
        }

        $backupFile = $this->backupDir($name) . DIRECTORY_SEPARATOR . $backupName;
        if (!is_file($backupFile) || is_link($backupFile)) {
            return $this->fail(6012, '备份不存在');
        }
        $content = file_get_contents($backupFile);
        if ($content === false) {
            return $this->fail(6012, '备份不存在');
        }
        if (strlen($content) > self::MAX_CONTENT_SIZE) {
            return $this->fail(6010, '备份配置过大');
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return $this->fail(6009, '备份配置 JSON 无效');
        }
        if (!is_array($data) || ($error = AdminConfigValidator::validate($name, $data))) {
            return $this->fail(6009, '备份配置不符合当前结构约束');
        }

        $before = file_get_contents($file);
        if ($before === false) {
            return $this->fail(6011, '配置读取失败');
        }
        $backup = $this->backup($name, $before);
        if ($backup === null || !$this->writeAtomic($file, $content)) {
            return $this->fail(6011, '配置写入失败');
        }

        AdminAudit::operation(
            (int)$admin['id'],
            'config_restore',
            'config',
            $name,
            $reason,
            [
                'restore_from' => $backupName,
                'before_sha256' => hash('sha256', $before),
                'after_sha256' => hash('sha256', $content),
                'backup' => $backup,
            ],
            $request
        );
        return $this->ok([
            'name' => $name,
            'sha256' => hash('sha256', $content),
            'updated_at' => (int)filemtime($file),
        ]);
    }

    private function validateRequest(string $name, string $content, string $reason): ?Response
    {
        if (strlen($content) > self::MAX_CONTENT_SIZE) {
            return $this->fail(6010, '配置内容过大');
        }
        if ($error = $this->validateReason($reason)) {
            return $error;
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return $this->fail(6009, '配置 JSON 无效');
        }
        if (!is_array($data)) {
            return $this->fail(6009, '配置 JSON 必须是对象或数组');
        }
        if ($error = AdminConfigValidator::validate($name, $data)) {
            return $this->fail(6009, $error);
        }
        return null;
    }

    private function validateReason(string $reason): ?Response
    {
        if ($this->textLength($reason) < 3 || $this->textLength($reason) > 200) {
            return $this->fail(6014, '操作原因需为 3-200 字');
        }
        return null;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function findConfig(string $name): ?string
    {
        if (!preg_match('/^[a-z0-9_]+$/', $name)) {
            return null;
        }
        $dir = realpath($this->configDir());
        if ($dir === false) {
            return null;
        }
        $file = $dir . DIRECTORY_SEPARATOR . $name . '.json';
        $real = realpath($file);
        if ($real === false || is_link($file) || !is_file($real)) {
            return null;
        }
        if (strpos($real, $dir . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $real;
    }

    /**
     * @return array<string, string>
     */
    private function configFiles(): array
    {
        $dir = $this->configDir();
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (!preg_match('/^([a-z0-9_]+)\.json$/', $file, $match)) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_link($path) || !is_file($path)) {
                continue;
            }
            $files[$match[1]] = $path;
        }
        ksort($files);
        return $files;
    }

    private function backupDir(string $name): string
    {
        return runtime_path('admin/config_backups/' . $name);
    }

    private function backup(string $name, string $content): ?string
    {
        $dir = $this->backupDir($name);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        // 同一秒内可能连续备份，用微秒的低 32 位作为名称内的单调序，保证列表顺序稳定
        $sequence = ((int)floor(microtime(true) * 1000000)) & 0xffffffff;
        $file = date('YmdHis') . '-' . sprintf('%08x', $sequence) . '.json';
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0640);
        $this->pruneBackups($dir);
        return $file;
    }

    /**
     * @return array<int, array{name: string, size: int, created_at: int}>
     */
    private function backupRows(string $name): array
    {
        $dir = $this->backupDir($name);
        if (!is_dir($dir)) {
            return [];
        }
        $rows = [];
        foreach (scandir($dir) ?: [] as $file) {
            if (!preg_match('/^[0-9]{14}-[a-f0-9]{8}\.json$/', $file)) {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $rows[] = [
                'name' => $file,
                'size' => (int)filesize($path),
                'created_at' => (int)filemtime($path),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            return $b['created_at'] <=> $a['created_at'] ?: strcmp($b['name'], $a['name']);
        });
        return $rows;
    }

    private function pruneBackups(string $dir): void
    {
        $rows = $this->backupRows(basename($dir));
        if (count($rows) <= self::MAX_BACKUPS) {
            return;
        }
        foreach (array_slice($rows, self::MAX_BACKUPS) as $row) {
            $file = $dir . DIRECTORY_SEPARATOR . $row['name'];
            if (is_file($file) && !is_link($file)) {
                @unlink($file);
            }
        }
    }

    private function writeAtomic(string $file, string $content): bool
    {
        $temp = $file . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, $content, LOCK_EX) === false) {
            return false;
        }
        @chmod($temp, 0640);
        if (!@rename($temp, $file)) {
            @unlink($temp);
            return false;
        }
        clearstatcache(true, $file);
        return true;
    }

    private function configDir(): string
    {
        return dirname(base_path()) . DIRECTORY_SEPARATOR . 'config';
    }

    private function ok(array $data = []): Response
    {
        return json(['code' => 0, 'msg' => 'ok', 'data' => $data ?: (object)[]]);
    }

    private function fail(int $code, string $msg): Response
    {
        return json(['code' => $code, 'msg' => $msg, 'data' => (object)[]]);
    }
}
