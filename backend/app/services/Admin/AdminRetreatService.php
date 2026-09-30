<?php

namespace app\services\Admin;

use app\support\AdminAudit;
use app\support\AdminAuth;
use app\support\Db;
use support\Request;
use Webman\Http\Response;

/**
 * 闭关测试工具：只允许调整进行中闭关记录的结束时间。
 */
class AdminRetreatService
{
    public function updateFinishAt(Request $request, int $id): Response
    {
        $admin = AdminAuth::user($request);
        if ($admin === null) {
            return $this->fail(6001, '未登录或登录已失效');
        }

        $finishAt = (int)$request->input('finish_at', 0);
        $password = (string)$request->input('password', '');
        $reason = trim((string)$request->input('reason', ''));
        if ($id < 1 || $finishAt < 1 || $finishAt > 4294967295) {
            return $this->fail(6005, '时间参数不正确');
        }
        if ($this->textLength($reason) < 3 || $this->textLength($reason) > 200) {
            return $this->fail(6014, '操作原因需为 3-200 字');
        }
        if ($error = (new AdminAuthService())->confirmSensitivePassword($request, $admin, $password)) {
            return $error;
        }

        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT id, player_id, finish_at, status FROM retreats WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $retreat = $stmt->fetch();
        if ($retreat === false || (int)$retreat['status'] !== 0) {
            return $this->fail(6013, '闭关记录不存在或已结束');
        }

        $before = (int)$retreat['finish_at'];
        $stmt = $pdo->prepare('UPDATE retreats SET finish_at = ? WHERE id = ? AND status = 0');
        $stmt->execute([$finishAt, $id]);

        AdminAudit::operation(
            (int)$admin['id'],
            'retreat_finish_at',
            'retreat',
            (string)$id,
            $reason,
            [
                'player_id' => (int)$retreat['player_id'],
                'before_finish_at' => $before,
                'after_finish_at' => $finishAt,
            ],
            $request
        );

        return $this->ok([
            'id' => $id,
            'before_finish_at' => $before,
            'finish_at' => $finishAt,
        ]);
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
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
