<?php

namespace app\services\Admin;

use app\support\AdminAudit;
use app\support\AdminAuth;
use app\support\Db;
use support\Request;
use Webman\Http\Response;

/**
 * 游历测试工具：只允许调整进行中游历记录的归来时间。
 */
class AdminTravelService
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
        $stmt = $pdo->prepare('SELECT id, player_id, finish_at, status FROM travels WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $travel = $stmt->fetch();
        if ($travel === false || (int)$travel['status'] !== 0) {
            return $this->fail(6013, '游历记录不存在或已结束');
        }

        $before = (int)$travel['finish_at'];
        $stmt = $pdo->prepare('UPDATE travels SET finish_at = ? WHERE id = ? AND status = 0');
        $stmt->execute([$finishAt, $id]);

        AdminAudit::operation(
            (int)$admin['id'],
            'travel_finish_at',
            'travel',
            (string)$id,
            $reason,
            [
                'player_id' => (int)$travel['player_id'],
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