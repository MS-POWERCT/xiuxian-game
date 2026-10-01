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
| spirit_stones  | object | 灵石，四级分开计数 `{ low, mid, high, top }`        |
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

| 动作                      | 允许状态                                   |
| ------------------------- | ------------------------------------------ |
| 感悟 `dazuo`              | idle / meditating / retreating / exploring |
| 开始冥想 `meditate`       | idle                                       |
| 结算冥想 `meditate/claim` | meditating                                 |
| 开始闭关 `retreat/start`  | idle                                       |
| 结算闭关 `retreat/claim`  | retreating                                 |
| 渡劫突破 `breakthrough`   | idle                                       |
| 遗迹探索 `relic`          | idle                                       |
| 转世重修 `reincarnate`    | idle / dead                                |

规则：

- **冥想中（meditating）禁止**：再次冥想、闭关、探索、突破；感悟不受限制
- **闭关中（retreating）禁止**：冥想、再次闭关、探索、突破；感悟不受限制
- **死后（dead）禁止**：感悟、冥想、闭关、探索、突破，仅允许转世
- 感悟是即时动作，不改变玩家当前状态，也不与冥想、闭关、探索互斥
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

响应 data：`{ "player": { ... }, "retreat": {...} | null, "meditation": {...} | null, "dazuo": {...} }`

- `retreat`：当前进行中的闭关（`status = 0`），无则 `null`。字段：`{ retreat_id, start_at, finish_at, expected_exp, status }`
- `meditation`：当前进行中的冥想，无则 `null`。字段：`{ start_at, finish_at, duration, expected_exp }`
- `dazuo`：今日感悟状态，字段：`{ daily_used, daily_limit, batch_size }`

业务规则：

- 返回前先执行一次「自然时间结算」，离线期间同样按真实时间流逝寿元
- 冥想会话保存在服务端，页面刷新后继续按 `finish_at` 倒计时

---

### 2. 开始冥想

`POST /api/meditate`

请求：

```json
{ "duration": 180 }
```

- `duration`：冥想时长（秒），可选 180 / 1200；未传时默认 180

响应 data：

```json
{
  "expected_exp": 30,
  "player": { ... },
  "meditation": {
    "start_at": 1735560000,
    "finish_at": 1735560180,
    "duration": 180,
    "expected_exp": 30
  }
}
```

业务规则：

- 开始后玩家状态变为 `meditating`，服务端保存开始时间、结束时间和预计收益
- 预计收益 = `base_exp × meditation_exp_ratio × realm.cultivate_rate × age.cultivate_rate × (1 + speed_bonus)`
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

### 4. 感悟（即时微收益）

`POST /api/dazuo`

请求：

```json
{ "count": 5 }
```

- `count`：本次提交的点击次数，必须等于 `config/meditation.json` 的 `dazuo_batch_size`

响应 data：

```json
{
  "gained_exp": 10,
  "batch_size": 5,
  "daily_used": 5,
  "daily_limit": 300,
  "player": { ... }
}
```

业务规则：

- 前端每次点击只在本地计数，不请求接口；累计满 `dazuo_batch_size` 后才提交一次
- 后端只接受完整批次，按批次一次性结算修为
- 批次收益 = `dazuo_base_exp × count × realm.cultivate_rate × age.cultivate_rate × (1 + speed_bonus)`
- 每日上限按点击次数统计，使用 Redis 按「玩家 + 当前世」累计；转世后当前世重新计算
- 每日上限随境界提升：`daily_limit = dazuo_daily_limit_base + (境界 order - 1) × dazuo_daily_limit_per_realm`；两项均为 0 时不限制
- `dazuo_cooldown_ms` 大于 0 时，两次批次结算之间受 Redis 短期冷却限制
- `dazuo_click_interval_ms` 只控制前端按钮响应间隔，不参与修为计算

错误码：

- `1000` 提交次数不完整
- `1003` 当前状态不允许该操作（dead 状态禁止感悟）
- `4003` 今日感悟次数已达上限
- `4004` 感悟尚未冷却

---

### 5. 开始闭关

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
- 本期闭关不消耗灵石，法阵/丹药仅提供加成（后续改为消耗储物戒中的物品）

错误码：

- `1003` 当前状态不允许该操作（非 idle，如闭关中 / 已死亡）
- `1004` 功能未解锁（如闭关需练气九层解锁）

---

### 6. 结算闭关

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

### 7. 渡劫突破

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

### 8. 遗迹探索

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

### 9. 转世重修 / 主动兵解

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
  - 灵石继承：四个品级各自按 `inherit_spirit_stone_ratio` 向下取整继承
  - 修炼速度加成 = 上一世突破的大境界次数 × `cultivate_speed_bonus_per_realm`，不超过 `cultivate_speed_bonus_cap`（练气不计，每突破一个大境界计一次，若上一世止于元婴 = 突破 3 次 = 45%）
- 新角色境界回练气初期、年龄回 `restart_age`、气血回满、继承灵石与速度加成、`life_no + 1`
- 每天重修次数受 `max_reincarnations_per_day` 限制（自然日，每天 00:00 重置；用 Redis 计数）

错误码：

- `1003` 当前状态不允许该操作（非 idle / dead，如闭关中 / 冥想中）
- `4002` 今日转世次数已达上限

---

### 10. 邮箱注册

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

### 11. 邮箱登录

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

### 12. 绑定身份证（计算年龄）

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

### 13. 前世档案列表

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
      "spirit_stones": { "low": 4500, "mid": 0, "high": 0, "top": 0 },
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
- `spirit_stones` 为四级对象 `{ low, mid, high, top }`

---

### 14. 灵石余额

`GET /api/spirit-stones`

请求头：`Authorization: Bearer <token>`

响应 data：

```json
{ "stones": { "low": 4500, "mid": 12, "high": 0, "top": 0 } }
```

业务规则：

- 灵石按四个品级分开计数（下品 low / 中品 mid / 上品 high / 极品 top）
- 品级与上限取自 `config/economy.json` 的 `spirit_stone_levels`、`spirit_stone_caps`

错误码：

- `5005` 未登录或登录已失效

---

### 15. 灵石品级兑换

`POST /api/spirit-stones/exchange`

请求头：`Authorization: Bearer <token>`

请求：

```json
{ "from_level": "low", "to_level": "mid", "amount": 10 }
```

- `amount` 恒为「高品级一侧」的数量；`from_level` 与 `to_level` 必须相邻

响应 data：

```json
{
  "stones": { "low": 3400, "mid": 22, "high": 0, "top": 0 },
  "cost": 1000,
  "fee": 50
}
```

业务规则：

- 升级（低→高）：1 高品 = `exchange_ratio` 低品 + 手续费；手续费 = 本金 × `exchange_fee_ratio` 对应兑换对比例，额外以源品级扣除
- 降级（高→低）：1 高品 = `exchange_ratio` 低品，免手续费
- 比例与手续费取自 `config/economy.json`

错误码：

- `1000` 兑换参数无效 / 品级不相邻
- `1001` 灵石不足
- `4005` 目标品级灵石已达上限
- `5005` 未登录或登录已失效

---

### 16. 商店商品列表

`GET /api/shop`

请求头：`Authorization: Bearer <token>`

响应 data：

```json
{
  "items": [
    {
      "id": "juqi_san",
      "name": "聚气散",
      "category": "pill",
      "ref_id": "juqi_san",
      "price": 10,
      "currency_level": "low"
    }
  ]
}
```

业务规则：

- 商品与价格来自 `config/shop.json`，`currency_level` 指定必须使用的灵石品级
- 本期不限购、不做上架解锁（`limit` / `unlock` 配置位保留）

错误码：

- `5005` 未登录或登录已失效

---

### 17. 购买商品

`POST /api/shop/buy`

请求头：`Authorization: Bearer <token>`

请求：

```json
{ "item_id": "juqi_san", "quantity": 2 }
```

响应 data：

```json
{
  "item_id": "juqi_san",
  "name": "聚气散",
  "quantity": 2,
  "cost": 20,
  "currency_level": "low",
  "stones": { "low": 3380, "mid": 22, "high": 0, "top": 0 }
}
```

业务规则：

- 校验商品是否存在 → 扣对应品级灵石 → 写入储物戒（`player_items` 叠加数量）
- 购买后可用 `GET /api/items` 查看所持物品

错误码：

- `1000` 商品不存在
- `1001` 灵石不足
- `5005` 未登录或登录已失效

---

### 18. 储物戒物品列表

`GET /api/items`

请求头：`Authorization: Bearer <token>`

可选参数：`category`（如 `pill`），不传则返回全部

响应 data：

```json
{
  "items": [
    {
      "item_id": "juqi_san",
      "name": "聚气散",
      "category": "pill",
      "quantity": 2,
      "created_at": 1735560000,
      "updated_at": 1735560000
    }
  ]
}
```

业务规则：

- 储物戒只放物品，不放灵石
- `name` 由 `config/shop.json`、`config/pills.json` 翻译，不硬编码

错误码：

- `5005` 未登录或登录已失效

---

## 三、后台管理接口

> 后台接口只允许管理员使用，前缀统一为 `/api/admin`，不得与玩家接口复用登录态。
> 管理员会话使用 HttpOnly Cookie：`admin_token`（认证）与 `admin_csrf`（CSRF 校验）。
> 除 `GET` 外，所有后台请求必须携带 `X-CSRF-Token`，其值与 `admin_csrf` Cookie 一致。
> 配置写入与恢复属于敏感操作，还必须提交管理员密码和操作原因。

### 1. 获取 CSRF Token

`GET /api/admin/auth/csrf`

响应 data：

```json
{ "csrf_token": "…" }
```

业务规则：

- 服务端设置 `admin_csrf` HttpOnly Cookie；前端只在内存中保存返回的 token，并用于后续非 GET 请求头。
- CSRF Token 不用于身份认证，登录接口也必须先调用本接口。

---

### 2. 管理员登录

`POST /api/admin/auth/login`

请求头：`X-CSRF-Token: <csrf_token>`

请求：

```json
{ "username": "admin", "password": "管理员密码" }
```

响应 data：

```json
{
  "admin": { "id": 1, "username": "admin" },
  "csrf_token": "…"
}
```

业务规则：

- 账号密码正确后生成随机管理员令牌，仅将令牌哈希写入数据库，原始令牌放入 `admin_token` HttpOnly Cookie。
- 连续失败 5 次后锁定 15 分钟；登录成功或登录锁定期结束后重置失败计数。
- 登录成功、失败、锁定和解锁相关事件写入 `admin_login_logs`。
- 管理员密码使用 `password_hash(PASSWORD_BCRYPT)` 存储，绝不明文保存。

错误码：

- `6002` 账号或密码错误
- `6003` 登录失败次数过多，请稍后重试
- `6004` CSRF 校验失败

---

### 3. 当前管理员

`GET /api/admin/auth/me`

响应 data：

```json
{
  "admin": { "id": 1, "username": "admin" },
  "csrf_token": "…"
}
```

业务规则：

- 管理员未登录、令牌过期或账号停用时返回 `6001`。
- 响应头统一禁止缓存，后台数据不会进入浏览器共享缓存。

错误码：

- `6001` 未登录或登录已失效

---

### 4. 退出登录

`POST /api/admin/auth/logout`

请求头：`X-CSRF-Token: <csrf_token>`

响应 data：`{}`

业务规则：

- 清除数据库中的管理员令牌哈希，并使 `admin_token`、`admin_csrf` Cookie 立即失效。

---

### 5. 后台概览

`GET /api/admin/dashboard`

响应 data：

```json
{
  "users": 12,
  "players": 10,
  "alive_players": 8,
  "retreating_players": 2,
  "admin_users": 1,
  "configs": 11,
  "server_time": 1735560000
}
```

---

### 6. 配置列表

`GET /api/admin/configs`

响应 data：

```json
{
  "configs": [
    {
      "name": "realms",
      "file": "realms.json",
      "size": 1405,
      "updated_at": 1735560000,
      "sha256": "…"
    }
  ]
}
```

业务规则：

- 仅列出仓库根目录 `config/*.json`，不返回任意服务器路径。
- 配置名只允许小写字母、数字和下划线。
- 拒绝符号链接、目录、空文件和超大文件。

---

### 7. 配置详情

`GET /api/admin/configs/{name}`

响应 data：

```json
{
  "name": "realms",
  "file": "realms.json",
  "content": "{...}",
  "updated_at": 1735560000,
  "sha256": "…",
  "backups": []
}
```

业务规则：

- `content` 返回原始 JSON 文本，前端编辑后再提交。
- 后台只读取 `config/{name}.json`，不允许路径穿越。

---

### 8. 保存配置

`POST /api/admin/configs/{name}`

请求头：`X-CSRF-Token: <csrf_token>`

请求：

```json
{
  "content": "{...}",
  "password": "当前管理员密码",
  "reason": "调整练气阶段寿命上限"
}
```

响应 data：

```json
{ "name": "realms", "sha256": "…", "updated_at": 1735560000 }
```

业务规则：

- 必须通过当前管理员密码二次确认；连续错误 5 次锁定敏感操作 15 分钟。
- 必须提交 3-200 字操作原因，写入审计日志。
- 服务端用 `json_decode` 校验 JSON，并校验核心配置结构；非法 JSON 或明显不满足项目约束的配置直接拒绝。
- 写入前自动在 `backend/runtime/admin/config_backups/` 创建备份，使用临时文件写入后原子替换原文件。
- 每次配置保存写入 `admin_operation_logs`，记录管理员、配置名、原因、前后摘要和备份名。
- 配置读取按文件修改时间热更新，修改成功后无需重启 Webman。

错误码：

- `6005` 请求参数不正确
- `6006` 管理员密码错误
- `6007` 敏感操作已锁定
- `6008` 配置不存在
- `6009` 配置 JSON 无效
- `6010` 配置过大
- `6011` 配置写入失败
- `6014` 操作原因必填

---

### 9. 配置备份

`GET /api/admin/configs/{name}/backups`

响应 data：

```json
{
  "backups": [
    {
      "name": "20260930T010203-abcdef12.json",
      "size": 1405,
      "created_at": 1735560000
    }
  ]
}
```

`POST /api/admin/configs/{name}/restore`

请求：

```json
{
  "backup": "20260930T010203-abcdef12.json",
  "password": "当前管理员密码",
  "reason": "回滚错误的寿命调整"
}
```

业务规则：

- 恢复前先备份当前文件，再原子替换。
- 备份文件名必须来自白名单列表，不允许路径穿越。
- 恢复同样需要管理员密码、操作原因，并写入审计日志。

错误码：

- `6006` 管理员密码错误
- `6012` 备份不存在
- `6011` 配置写入失败

---

### 10. 数据表列表

`GET /api/admin/tables`

响应 data：

```json
{
  "tables": [
    { "name": "users", "label": "用户账号", "group": "game" },
    { "name": "players", "label": "玩家角色", "group": "game" },
    { "name": "admin_users", "label": "管理员账号", "group": "admin" }
  ]
}
```

业务规则：

- 只展示固定白名单表，不允许通过表名访问任意数据库表。
- `group = game` 为主业务表，`group = admin` 为后台自身数据表，管理端菜单分组展示。
- 默认只读，不提供任意 SQL、通用行编辑、删除或结构修改入口；仅对 `retreats`、`players` 各提供一个专用敏感操作接口（见 12、13）。
- `users.password_hash`、`users.auth_token` 等敏感字段统一返回 `***`。

---

### 11. 数据表内容

`GET /api/admin/tables/{table}?page=1&page_size=20&keyword=&sort=id&order=desc`

响应 data：

```json
{
  "table": "players",
  "columns": [
    {
      "name": "id",
      "label": "玩家ID",
      "type": "bigint unsigned",
      "is_time": false,
      "masked": false
    },
    {
      "name": "name",
      "label": "道号",
      "type": "varchar(32)",
      "is_time": false,
      "masked": false
    },
    {
      "name": "created_at",
      "label": "创建时间",
      "type": "int unsigned",
      "is_time": true,
      "masked": false
    }
  ],
  "rows": [],
  "page": 1,
  "page_size": 20,
  "total": 0
}
```

业务规则：

- `sort` 必须是该表真实列名；`order` 只允许 `asc` / `desc`。
- `page_size` 最大 50，防止一次性拉取整表。
- `keyword` 只在该表白名单文本列中搜索，使用 PDO 参数绑定，不拼接用户输入。
- `columns[].label` 为字段中文名；`is_time = true` 的字段由前端统一格式化为本地时间。
- 表数据仅用于排查和审计，不提供通用写入接口。

错误码：

- `6013` 数据表不允许访问或不存在

---

### 12. 调整闭关结束时间（测试工具）

`POST /api/admin/retreats/{id}/finish-at`

请求头：`X-CSRF-Token: <csrf_token>`

请求：

```json
{
  "finish_at": 1735560000,
  "password": "当前管理员密码",
  "reason": "测试闭关立即到期"
}
```

响应 data：

```json
{ "id": 88, "before_finish_at": 1735563600, "finish_at": 1735560000 }
```

业务规则：

- 只允许修改 `status = 0` 的进行中闭关记录，历史闭关不可改。
- 只修改 `retreats.finish_at`，不修改玩家状态、预计收益或结算结果。
- 请求必须经过管理员登录、CSRF 校验、当前管理员密码二次确认，并填写 3-200 字测试原因。
- 修改写入 `admin_operation_logs`，记录修改前后时间戳。
- 可通过该项把结束时间调整到当前时间或未来时间，用于测试结算、死亡和状态流转。

错误码：

- `6005` 时间参数不正确
- `6006` 管理员密码错误
- `6007` 敏感操作已锁定
- `6013` 闭关记录不存在或已结束
- `6014` 操作原因必填

---

### 13. 给玩家增加灵石

`POST /api/admin/players/{id}/spirit-stones`

请求头：`X-CSRF-Token: <csrf_token>`

请求：

```json
{
  "amounts": { "low": 1000, "mid": 10, "high": 0, "top": 0 },
  "password": "当前管理员密码",
  "reason": "补偿玩家异常丢失的灵石"
}
```

- `amounts`：四个品级各自要增加的数量，键取自 `config/economy.json` 的 `spirit_stone_levels`，未填按 0 处理；至少一项大于 0

响应 data：

```json
{
  "player_id": 12,
  "player_name": "青云子",
  "requested": { "low": 1000, "mid": 10, "high": 0, "top": 0 },
  "before": { "low": 320, "mid": 0, "high": 0, "top": 0 },
  "after": { "low": 1320, "mid": 10, "high": 0, "top": 0 }
}
```

业务规则：

- 在玩家现有灵石基础上按品级叠加，规则与游戏内一致：每个品级不得超过 `config/economy.json` 的 `spirit_stone_caps`，超上限部分直接丢弃
- 请求必须经过管理员登录、CSRF 校验、当前管理员密码二次确认，并填写 3-200 字操作原因
- 发放写入 `admin_operation_logs`（`action = player_spirit_stones`），记录申请量、发放前后持有量
- 前端在提交前会按上限校验，避免出现被静默丢弃的差额

错误码：

- `6005` 灵石数量不正确 / 未填写任何数量
- `6006` 管理员密码错误
- `6007` 敏感操作已锁定
- `6014` 操作原因必填
- `6015` 玩家不存在

---

## 四、未尽事项

- 未定义的接口（宗门争锋、炼丹）在玩法确定前**不实现**
- 所有数值规则以 `config/*.json` 为准，接口层不重复硬编码
