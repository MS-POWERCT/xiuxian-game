# API 接口契约

> 本文件是前后端接口的唯一依据。改接口先改这里，再改代码。
> 数值来源统一为 `config/*.json`，接口层只负责读写玩家状态、执行规则。

---

## 一、通用约定

- 统一前缀 `/api`
- 请求/响应均为 JSON，编码 UTF-8
- 认证：请求头 `Authorization: Bearer <token>`
- 登录后获取 token，有效期 30 天；过期或缺失统一返回 `5005 未登录或登录已失效`。除「邮箱注册 / 邮箱登录」外，其余接口均需登录态。
- 时间戳：秒级 Unix 时间戳（整数）
- 通用响应包裹：

```json
{ "code": 0, "msg": "ok", "data": {} }
```

- `code`：0 成功，非 0 失败（对应错误码见各接口）
- 玩家状态字段统一如下（下文用 `player` 表示）：

| 字段           | 类型   | 说明                                                |
| -------------- | ------ | --------------------------------------------------- |
| id             | int    | 玩家 ID                                             |
| life_no        | int    | 当前第几世（从 1 开始）                             |
| name           | string | 道号                                                |
| realm_id       | string | 当前境界 id（qi/zhuji/jindan/yuanying/huashen）     |
| stage_index    | int    | 当前小阶段下标（对应 realms.json 的 stages）        |
| exp            | int    | 当前小阶段内累计修为                                |
| age            | int    | 当前游戏内年龄（年）                                |
| lifespan_max   | int    | 当前境界寿命上限（年）                              |
| hp             | int    | 气血（0-100）                                       |
| spirit_stones  | int    | 灵石                                                |
| cultivate_rate | float  | 年龄补偿效率系数                                    |
| speed_bonus    | float  | 转世修炼速度加成系数（上一世突破的大境界次数）      |
| status         | string | 状态互斥：idle/retreating/meditating/exploring/dead |

---

### 状态互斥

玩家同一时间只能处于一种修炼状态，非空闲状态下禁止执行其他修炼动作。`status` 取值：

| 值         | 含义   |
| ---------- | ------ |
| idle       | 空闲   |
| retreating | 闭关中 |
| meditating | 冥想中 |
| exploring  | 探索中 |
| dead       | 已死亡 |

各动作允许的玩家状态（违反统一返回错误码 `1003 当前状态不允许该操作`）：

| 动作                     | 允许状态    |
| ------------------------ | ----------- |
| 开始冥想 `meditate`       | idle        |
| 结算冥想 `meditate/claim` | meditating  |
| 开始闭关 `retreat/start`  | idle        |
| 结算闭关 `retreat/claim`  | retreating  |
| 渡劫突破 `breakthrough`   | idle        |
| 遗迹探索 `relic`          | idle        |
| 转世重修 `reincarnate`    | idle / dead |

规则：

- **冥想中（meditating）禁止**：再次冥想、闭关、探索、突破
- **闭关中（retreating）禁止**：冥想、再次闭关、探索、突破
- **死后（dead）禁止**：冥想、闭关、探索、突破，仅允许转世
- 校验由后端统一集中处理（入口 `guard`），前端禁用按钮仅作体验优化，真正拦截以后端为准

---

### 功能解锁

玩法开启受境界/层级控制，条件集中维护在 `config/unlock.json`：

```json
{
  "meditate_deep": { "name": "深度冥想", "realm_id": "qi", "stage": 3 },
  "retreat": { "name": "闭关", "realm_id": "qi", "stage": 9 }
}
```

- 键为功能标识，值含 `name`（名称）、`realm_id`（解锁境界）、`stage`（解锁层级，从 1 起，一层=1）
- 解锁判定：玩家境界 `order` 高于条件境界；或同境界且 `stage_index + 1 >= stage`
- 未在 `unlock.json` 登记的功能默认已解锁（不限制）
- 未解锁时相关接口统一返回错误码 `1004 功能未解锁`，`msg` 形如「深度冥想需练气三层解锁」
- 校验由后端统一集中处理（入口 `guardUnlock`），前端「锁定」显示仅作体验优化，真正拦截以后端为准

---

## 二、接口列表

### 1. 获取玩家状态

`GET /api/player`

响应 data：`{ "player": { ... }, "retreat": {...} | null, "meditation": {...} | null }`

- `retreat`：当前进行中的闭关（`status = 0`），无则 `null`。字段：`{ retreat_id, finish_at, expected_exp, status }`
- `meditation`：当前进行中的冥想，无则 `null`。字段：`{ start_at, finish_at, duration, expected_exp }`

业务规则：

- 返回前先执行一次「自然时间结算」，离线期间同样按真实时间流逝寿元
- 冥想会话保存在服务端，页面刷新后继续按 `finish_at` 倒计时

---

### 2. 开始冥想

`POST /api/meditate`

请求：

```json
{ "duration": 30 }
```

- `duration`：冥想时长（秒），可选 30 / 300；未传时默认 30

响应 data：

```json
{
  "expected_exp": 5,
  "player": { ... },
  "meditation": {
    "start_at": 1735560000,
    "finish_at": 1735560030,
    "duration": 30,
    "expected_exp": 5
  }
}
```

业务规则：

- 开始后玩家状态变为 `meditating`，服务端保存开始时间、结束时间和预计收益
- 预计收益 = `base_exp × realm.cultivate_rate × age.cultivate_rate × (1 + speed_bonus)`
- `base_exp` 取自 `config/meditation.json`
- 境界效率与年龄系数分别取自 `config/realms.json`、`config/lifecycle.json`

错误码：

- `1003` 当前状态不允许该操作（非 idle，如闭关中 / 已死亡）
- `1004` 功能未解锁（如深度冥想需练气三层解锁）

---

### 3. 结算冥想

`POST /api/meditate/claim`

请求：`{}`

响应 data：

```json
{
  "gained_exp": 5,
  "player": { ... }
}
```

业务规则：

- 仅 `status = meditating` 时可结算
- `finish_at` 之前请求返回 `3002 冥想尚未结束`
- 结算后发放预计收益、状态恢复为 `idle`、清空当前冥想会话

错误码：

- `1003` 当前状态不允许该操作（非 meditating）
- `3002` 冥想尚未结束

---

### 4. 开始闭关

`POST /api/retreat/start`

请求：

```json
{
  "duration_hours": 24,
  "technique_id": "tuna",
  "formation_id": "juling_small",
  "pill_ids": ["peiyuan_dan", "peiyuan_dan"]
}
```

- `duration_hours`：闭关时长（小时），可选 24 / 48（对应 `config/meditation.json` 的 retreats）

响应 data：

```json
{
  "retreat_id": 88,
  "finish_at": 1735560000,
  "expected_exp": 5000
}
```

业务规则：

- 闭关产出 = `retreat_base_exp × realm.cultivate_rate × 功法效率 × (1 + 法阵加成 + 丹药加成)`
- buff 叠加上限 = `config/meditation.json` 的 `retreat_buff_cap`
- 消耗灵石：法阵按 `cost_per_use`、丹药按 `price`

错误码：

- `1001` 灵石不足
- `1003` 当前状态不允许该操作（非 idle，如闭关中 / 已死亡）
- `1004` 功能未解锁（如闭关需练气九层解锁）

---

### 5. 结算闭关

`POST /api/retreat/claim`

请求：

```json
{ "retreat_id": 88 }
```

响应 data：

```json
{ "gained_exp": 5200, "player": { ... } }
```

业务规则：

- 到期结算（`finish_at` 已过）：获得全额 `expected_exp`
- 提前出关：获得 `expected_exp × (已过时长 ÷ 总时长) × retreat_early_exit_ratio`，`retreat_early_exit_ratio` 取 `config/meditation.json`（默认 0.5，即减半）
- 结算后走火入魔判定（护神阵可降低概率）

错误码：

- `1003` 当前状态不允许该操作（非 retreating）

---

### 6. 渡劫突破

`POST /api/breakthrough`

请求：`{}`

响应 data（成功）：

```json
{ "success": true, "to_realm": "zhuji", "player": { ... } }
```

响应 data（失败）：

```json
{ "success": false, "exp_rollback": 5000, "hp_loss": 30, "player": { ... } }
```

业务规则：

- 需当前小阶段修为达到 `exp_to_next` 最后一项，且处于大境界过渡
- 失败率/回退比例/气血损失取自 `config/breakthrough.json`

错误码：

- `1003` 当前状态不允许该操作（非 idle，如闭关中 / 已死亡）
- `3001` 修为不足

---

### 7. 遗迹探索

`POST /api/relic`

请求：`{}`

响应 data：

```json
{
  "result": "small_fortune",
  "reward": "灵石 100-500 / 丹药",
  "player": { ... }
}
```

业务规则：

- 按 `config/relics.json` 的概率抽取结果
- 陨落结果需判断是否有替死符

---

### 8. 转世重修 / 主动兵解

`POST /api/reincarnate`

请求：`{}`

响应 data：

```json
{ "player": { ... } }
```

业务规则：

- 空闲（idle）调用 = 主动兵解，`death_reason` 记 `self`；死亡（dead）调用 = 正常转世，`death_reason` 记当前死亡原因（当前唯一死亡机制为 `lifespan` 寿元耗尽）
- 转世前先把这一世最终状态快照写入 `reincarnation_records`（`life_no` 自增）
- 传承规则取自 `config/lifecycle.json` 的 `reincarnation`：
  - 灵石继承 = 前世灵石 × `inherit_spirit_stone_ratio`
  - 修炼速度加成 = 上一世突破的大境界次数 × `cultivate_speed_bonus_per_realm`，不超过 `cultivate_speed_bonus_cap`（练气不计，每突破一个大境界计一次，若上一世止于元婴 = 突破 3 次 = 45%）
- 新角色境界回练气初期、年龄回 `restart_age`、气血回满、继承灵石与速度加成、`life_no + 1`
- 每天重修次数受 `max_reincarnations_per_day` 限制（自然日，每天 00:00 重置；用 Redis 计数）

错误码：

- `1003` 当前状态不允许该操作（非 idle / dead，如闭关中 / 冥想中）
- `4002` 今日转世次数已达上限

---

### 9. 邮箱注册

`POST /api/auth/register`

请求：

```json
{ "email": "player@example.com", "password": "12345678" }
```

响应 data：

```json
{ "id": 1, "email": "player@example.com" }
```

业务规则：

- 校验邮箱格式合法、密码长度至少 8 位
- 密码用 PHP 内置 bcrypt（`password_hash`）加密存储，**绝不明文**
- 同一邮箱只能注册一次

错误码：

- `5001` 邮箱格式不正确
- `5002` 密码长度至少 8 位
- `5003` 邮箱已被注册

---

### 10. 邮箱登录

`POST /api/auth/login`

请求：

```json
{ "email": "player@example.com", "password": "12345678" }
```

响应 data：

```json
{
  "token": "…",
  "user": { "id": 1, "email": "player@example.com", "realname_age": null }
}
```

业务规则：

- 校验邮箱与密码，通过后生成登录令牌 `token` 并返回
- 后续需要登录态的接口在请求头携带 `Authorization: Bearer <token>`
- `realname_age` 未绑定身份证时为 `null`

错误码：

- `5004` 邮箱或密码错误

---

### 11. 绑定身份证（计算年龄）

`POST /api/auth/bind-idcard`

请求（需登录态）：

```json
{ "id_card": "110101199201150012" }
```

请求头：`Authorization: Bearer <token>`

响应 data：

```json
{ "age": 34, "realname_age": 34 }
```

业务规则：

- 校验身份证号合法性（GB 11643-1999）：
  - 18 位，前 17 位数字，末位数字或 `X`
  - 第 7-14 位为合法出生日期（YYYYMMDD）
  - 校验码（末位）正确
- 按出生日期计算周岁年龄（生日是否已过判断），年龄必须在 12-70 岁之间
- **只保存计算出的年龄 `realname_age`，不保存身份证号原文**
- 校验通过后写入 `users.realname_age` 并记录绑定时间
- 若该用户尚未创建角色，首次创建时以 `realname_age` 作为游戏内初始年龄，并按 `config/lifecycle.json` 的年龄区间发放修炼效率与初始灵石
- 未绑定实名时按 `config/realms.json` 的 `start_age_years` 创建标准角色

错误码：

- `5005` 未登录或登录已失效
- `5006` 身份证号不合法
- `5007` 年龄须在 12-70 岁之间

---

### 12. 前世档案列表

`GET /api/reincarnate/records`

响应 data：

```json
{
  "records": [
    {
      "life_no": 2,
      "name": "无名散修",
      "realm_id": "zhuji",
      "realm_name": "筑基",
      "stage_index": 1,
      "stage_name": "中期",
      "exp": 1200,
      "age": 78,
      "lifespan_max": 200,
      "hp": 100,
      "spirit_stones": 4500,
      "cultivate_rate": 1.25,
      "total_days": 32,
      "death_reason": "lifespan",
      "death_at": 1735560000
    }
  ]
}
```

业务规则：

- 按 `life_no` 倒序返回该用户所有前世档案
- `realm_name` / `stage_name` 由 `config/realms.json` 翻译，不硬编码
- `death_reason` 中文含义由前端映射展示

---

## 三、未尽事项

- 未定义的接口（宗门争锋、炼丹）在玩法确定前**不实现**
- 所有数值规则以 `config/*.json` 为准，接口层不重复硬编码
