# 文字修仙

一款「减法向」的文字修仙养成游戏：纯文字、真实时间压力、寿元有限，让玩家「真的在修」。

## 核心玩法

- **感悟**（即时连点）：碎片时间获取微额修为
- **冥想**（3 分钟 - 20 分钟）：定期积累修为
- **闭关**（24/48 小时）：长期投入，配合心法、法阵、丹药提高收益
- **渡劫突破**：修为满后突破大境界，可能失败
- **寿元流逝**：真实时间驱动，到时间未突破则死亡，可转世重修
- **遗迹探索**（周期玩法）：高风险高回报的文字冒险

时间压缩比：**1 天真实时间 = 2 年游戏内时间**。

## 技术栈

| 层     | 技术                              |
| ------ | --------------------------------- |
| 前端   | Vue 3 + Vite + Pinia + TypeScript |
| 后端   | PHP 8 + Webman                    |
| 数据库 | MySQL + Redis（Redis 用于计数）   |
| 配置   | JSON（`config/`，前后端共用）     |

## 目录结构

```
frontend/          # Vue 3 前端
backend/           # Webman 后端
config/            # 游戏数值配置（JSON）
docs/              # 设计文档、接口契约
  api.md           # 接口契约（前后端唯一依据）
AGENTS.md          # AI 协作规范
```

## 快速开始

### 前端

```bash
cd frontend
npm install
npm run dev
```

### 后端

```bash
cd backend
composer install
php start.php start
```

后端依赖 PHP `ext-redis`，本地需先启动 Redis（默认 `127.0.0.1:6379`）。

### 后台管理

首次使用先创建一个管理员账号（密码至少 12 位）：

```bash
cd backend
php scripts/admin_create.php
```

开发环境访问 `http://localhost:5173/admin.html`。后台菜单区分「主业务数据」与「后台数据」，字段显示中文名、时间显示本地时间；闭关记录可二次确认管理员密码后调整 `finish_at` 进行测试。后台采用独立的 HttpOnly Cookie 会话、CSRF 校验、登录锁定和操作审计。

## 相关文档

- [游戏设计方案](docs/修仙游戏设计方案.md)
- [技术选型方案](docs/技术选型方案.md)
- [数值系统设计](docs/数值系统设计.md)
- [接口契约](docs/api.md)
