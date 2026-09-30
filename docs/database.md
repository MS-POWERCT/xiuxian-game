# 数据库设计

> 规则数值不存数据库，统一放 `config/*.json`。数据库只存「玩家运行时状态」与「后台安全审计数据」。
> 字符集 `utf8mb4`，时间戳统一秒级 Unix 整数（命名 `*_at`）。

---

## 一、表与职责

| 表                    | 职责                        |
| --------------------- | --------------------------- |
| users                 | 账号（实名年龄）            |
| players               | 玩家角色状态（核心）        |
| retreats              | 闭关记录                    |
| reincarnation_records | 前世档案（转世/死亡时快照） |
| admin_users           | 后台管理员账号              |
| admin_login_logs      | 后台登录、失败和锁定审计    |
| admin_operation_logs  | 后台敏感操作审计            |

> 玩家运行时状态与后台安全审计分开；后台表仅服务管理端，不参与游戏数值计算。

---

## 二、users（账号）

| 字段         | 类型               | 说明                                        |
| ------------ | ------------------ | ------------------------------------------- |
| id                 | BIGINT UNSIGNED PK | 自增                                        |
| email              | VARCHAR(255)       | 登录邮箱，唯一索引                          |
| password_hash      | VARCHAR(255)       | bcrypt 密码哈希，绝不明文                   |
| realname_age       | TINYINT NULL       | 实名接口取到的真实年龄（未成年可能为 NULL） |
| auth_token         | VARCHAR(64) NULL   | 玩家登录令牌                                |
| token_expires_at   | INT UNSIGNED NULL  | 玩家令牌过期时间                            |
| realname_bound_at  | INT UNSIGNED NULL  | 实名绑定时间                                |
| created_at         | INT UNSIGNED       | 注册时间（秒）                              |
| updated_at         | INT UNSIGNED       | 更新时间（秒）                              |

**规则：不存原始身份证号**，只存年龄。

---

## 三、players（玩家角色状态）

对应 `docs/api.md` 中的 `player` 对象。

| 字段          | 类型               | 说明                                        | 来源       |
| ------------- | ------------------ | ------------------------------------------- | ---------- |
| id            | BIGINT UNSIGNED PK | 自增                                        | —          |
| user_id       | BIGINT UNSIGNED    | 关联 users.id                               | —          |
| life_no       | INT UNSIGNED       | 当前第几世（从 1 开始）                     | 状态       |
| name          | VARCHAR(32)        | 道号                                        | 创建时生成 |
| realm_id      | VARCHAR(16)        | 境界 id（qi/zhuji/jindan/yuanying/huashen） | 状态       |
| stage_index   | TINYINT UNSIGNED   | 当前小阶段下标                              | 状态       |
| exp           | INT UNSIGNED       | 当前小阶段内修为                            | 状态       |
| age           | INT UNSIGNED       | 游戏内年龄（年）                            | 状态       |
| lifespan_max  | INT UNSIGNED       | 寿命上限（年，冗余存，取自 realms.json）    | 派生       |
| hp            | TINYINT UNSIGNED   | 气血 0-100                                  | 状态       |
| spirit_stones | INT UNSIGNED       | 灵石                                        | 状态       |
| alive         | TINYINT(1)         | 是否存活 1/0                                | 状态       |
| status        | VARCHAR(16)        | idle/retreating/meditating/exploring/dead   | 状态       |
| meditation_start_at    | INT UNSIGNED | 当前冥想开始时间，无冥想时为 NULL | 状态 |
| meditation_finish_at   | INT UNSIGNED | 当前冥想结束时间，无冥想时为 NULL | 状态 |
| meditation_duration    | INT UNSIGNED | 当前冥想时长（秒），无冥想时为 NULL | 状态 |
| meditation_expected_exp| INT UNSIGNED | 当前冥想预计收益，无冥想时为 NULL | 状态 |
| created_at    | INT UNSIGNED       | 创角时间（秒）                              | —          |
| updated_at    | INT UNSIGNED       | 更新时间（秒）                              | —          |

**说明：**

- `lifespan_max` 是**冗余派生值**（本可由 `realm_id` 查 `config/realms.json` 得到），冗余存是为了离线结算快、少一次联表查询。
- `cultivate_rate`（年龄补偿效率）**不存库**，实时由 `age` + `config/lifecycle.json` 计算，避免两处不一致。
- 冥想会话直接挂在玩家状态上，刷新页面不会丢失；结算后四个 `meditation_*` 字段清空。
- 「自然时间结算」由后端按 `updated_at` 懒结算，离线期间同样计算寿元流逝与死亡。

---

## 四、retreats（闭关记录）

| 字段         | 类型               | 说明                            |
| ------------ | ------------------ | ------------------------------- |
| id           | BIGINT UNSIGNED PK | 自增                            |
| player_id    | BIGINT UNSIGNED    | 关联 players.id                 |
| technique_id | VARCHAR(32)        | 功法 id（对应 techniques.json） |
| formation_id | VARCHAR(32)        | 法阵 id（对应 formations.json） |
| pill_ids     | JSON               | 丹药 id 数组（对应 pills.json） |
| expected_exp | INT UNSIGNED       | 预估收益                        |
| finish_at    | INT UNSIGNED       | 到期时间（秒）                  |
| status       | TINYINT            | 0 进行中 / 1 已结算             |
| created_at   | INT UNSIGNED       | 开始时间（秒）                  |

**规则：**

- 一名玩家同一时间最多一条 `status = 0` 的进行中闭关（对应 api.md 错误码 `1002`）。
- 已结算的闭关不删除，保留作记录（雏形可先不查历史）。

---

## 五、reincarnation_records（前世档案）

转世/死亡时，把这一世最终状态**快照**存下来，供「重新修仙」页面前世档案列表展示。

| 字段           | 类型               | 说明                                                               |
| -------------- | ------------------ | ------------------------------------------------------------------ |
| id             | BIGINT UNSIGNED PK | 自增                                                               |
| user_id        | BIGINT UNSIGNED    | 关联 users.id                                                      |
| life_no        | INT UNSIGNED       | 第几世（从 1 开始）                                                |
| name           | VARCHAR(32)        | 该世道号                                                           |
| realm_id       | VARCHAR(16)        | 该世最终境界 id                                                    |
| stage_index    | TINYINT UNSIGNED   | 该世最终小阶段下标                                                 |
| exp            | INT UNSIGNED       | 该世最终修为                                                       |
| age            | INT UNSIGNED       | 该世死亡时年龄（游戏内年）                                         |
| lifespan_max   | INT UNSIGNED       | 该世寿命上限（年）                                                 |
| hp             | TINYINT UNSIGNED   | 该世最终气血                                                       |
| spirit_stones  | INT UNSIGNED       | 该世最终灵石                                                       |
| cultivate_rate | FLOAT              | 该世年龄补偿效率系数                                               |
| total_days     | INT UNSIGNED       | 该世真实游玩天数（创角到死亡）                                     |
| death_reason   | VARCHAR(16)        | 死亡原因：`lifespan` 寿元耗尽 / `self` 主动兵解 / `relic` 遗迹陨落 |
| death_at       | INT UNSIGNED       | 死亡时间戳（秒）                                                   |
| created_at     | INT UNSIGNED       | 该世开始时间戳（秒）                                               |

**规则：**

- 快照字段与 players 表保持对齐，转世那一刻复制，之后不再变动
- 物品（丹药/法宝等）当前无背包系统，暂不纳入快照，等背包系统上线再补
- `life_no` 由该 user 已有前世数 + 1 生成

---

## 六、后台管理表

### admin_users（管理员账号）

| 字段                     | 类型               | 说明                                      |
| ------------------------ | ------------------ | ----------------------------------------- |
| id                       | BIGINT UNSIGNED PK | 自增                                      |
| username                 | VARCHAR(32)        | 唯一管理员账号                            |
| password_hash            | VARCHAR(255)       | bcrypt 密码哈希，绝不明文                 |
| is_enabled               | TINYINT(1)         | 是否启用                                  |
| auth_token_hash          | CHAR(64) NULL      | 后台登录令牌的 SHA-256 哈希，不存原文     |
| auth_token_expires_at    | INT UNSIGNED NULL  | 后台令牌过期时间                          |
| failed_login_count       | TINYINT UNSIGNED   | 连续登录失败次数                          |
| locked_until             | INT UNSIGNED NULL  | 登录锁定截止时间                          |
| sensitive_fail_count     | TINYINT UNSIGNED   | 敏感操作密码连续失败次数                  |
| sensitive_locked_until   | INT UNSIGNED NULL  | 敏感操作锁定截止时间                      |
| last_login_at            | INT UNSIGNED NULL  | 最后登录时间                              |
| last_login_ip            | VARCHAR(45) NULL   | 最后登录 IP                               |
| created_at / updated_at  | INT UNSIGNED       | 创建、更新时间（秒）                      |

### admin_login_logs（管理员登录审计）

记录每次登录成功、失败、锁定检查的账号、IP、User-Agent 和时间。用于排查暴力破解与异常登录。

### admin_operation_logs（管理员操作审计）

记录后台配置保存、配置恢复、退出等敏感操作。字段包含管理员 id、动作、目标、操作原因、操作摘要、IP、User-Agent 和时间。

### 后台安全规则

- `admin_token` 和 `admin_csrf` 均使用 HttpOnly Cookie，不进入前端 localStorage。
- 后台写操作必须通过 CSRF 校验；配置写入与恢复还必须二次确认管理员密码。
- 数据表浏览只读，且只允许固定白名单表；敏感列由服务端脱敏。

---

## 七、索引建议

- `players.user_id` 建唯一索引（一个账号一个角色）
- `retreats.player_id` + `retreats.status` 建联合索引
- `reincarnation_records.user_id` + `reincarnation_records.life_no` 建唯一索引

---

## 八、待定（雏形不含）

- 遗迹探索记录表
- 排行榜表
- 宗门/社交相关表
- 玩家背包/物品持有表

以上等对应玩法确定后再设计，避免提前建无用表。
