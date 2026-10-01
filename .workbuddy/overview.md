# 项目盘点 · 阶段诊断（2026-09-30）

## 项目定位

「文字修仙」减法向养成游戏：纯文字、真实时间压力（1 天 = 2 年）、寿元有限、可转世重修。
平台：抖音互动空间；核心受众 30-45 岁修仙小说读者。

## 技术栈（已锁定）

- 前端：Vue 3 + Vite + Pinia + TypeScript（手写 CSS，无重型 UI 库）
- 后端：PHP 8 + Webman（常驻内存，负责离线结算/校验）
- 存储：MySQL（玩家状态）+ Redis（短期计数）；数值一律 config/\*.json

## 文档资产（docs/，5 份，状态健康）

| 文档             | 内容                                                                | 状态                           |
| ---------------- | ------------------------------------------------------------------- | ------------------------------ |
| 修仙游戏设计方案 | 设计哲学、境界体系、核心循环、死亡转世、庆祝模块规格                | 完整                           |
| 技术选型方案     | Webman 选型理由、分层、部署、AI 生产流程                            | 完整                           |
| 数值系统设计 V1  | 全套基线数值（境界阈值/修为公式/经济/渡劫/遗迹概率）                | 完整，个别数值与 config 有出入 |
| api.md           | 13 个玩家接口 + 12 个后台接口契约                                   | 完整，遗迹接口仍为雏形         |
| database.md      | 7 张表（users/players/retreats/reincarnation_records + 3 admin 表） | 完整                           |

## 实现进度（代码实测）

- 后端：GameController（感悟/冥想/闭关/突破/转世/前世档案）、AuthController（注册/登录/实名）、Admin 后台 4 控制器（CSRF+审计+配置热更新）
- 前端：登录/实名/主界面 + DazuoPanel/MeditateBar/RetreatPanel/BreakthroughPanel/ReincarnationRecords/CelebrationOverlay/AudioControl + admin 后台
- config 11 个 JSON 齐全，核心数值与文档基本一致

## 阶段判定：制作阶段（Phase 5）后期

已完成：账号与实名、感悟、冥想、闭关、突破、转世与前世档案、离线寿元结算、Redis 计数、后台管理。

待完成缺口：

1. **遗迹探索未实现**——config/relics.json、economy.json 已备数值，api.md 的 relic 接口仍是雏形描述，前端无 RelicPanel
2. **真实年龄差异化回合校准**未做
3. **零测试**——前后端均无测试目录，核心流程（状态互斥/离线结算/渡劫/转世）无任何自动化验证

## 发现的不一致（低危，留意即可）

- realms.json 化神 exp_to_next 末项 2500000，但化神为封顶境界无下一境界；数值文档也未列此项
- 设计文档感悟「单次 2 修为/批 10」，config 为 base 1/批 5（以 config 为准即可，文档待同步）
- economy.json 的遗迹灵石区间、每日登录奖励对应玩法尚未实现
