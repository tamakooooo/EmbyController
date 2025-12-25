# EmbyController

Emby 影视站用户管理系统，提供完整的用户注册、会员管理、支付续费等功能。

## 功能特性

- **用户系统**：注册、登录、密码找回、签到
- **Emby 账号管理**：创建、激活、续期、改密
- **会员系统**：余额充值、兑换码激活、自动续期
- **影评系统**：影片评分与评论
- **工单系统**：用户反馈与客服支持
- **通知推送**：邮件 & Telegram Bot 通知
- **后台管理**：用户管理、兑换码生成、系统配置

## 快速开始

### Docker 部署（推荐）

1. **拉取镜像**
```bash
docker pull tamakoo/emby-controller:latest
```

2. **准备配置文件**
```bash
# 下载示例配置
curl -O https://raw.githubusercontent.com/tamakooooo/EmbyController/main/example.env
mv example.env .env

# 编辑配置
vi .env
```

3. **启动容器**
```bash
docker run -d \
  --name emby-controller \
  -p 8018:8018 \
  -v $(pwd)/.env:/app/.env \
  tamakoo/emby-controller:latest
```

### Docker Compose

```yaml
version: '3.8'

services:
  emby-controller:
    image: tamakoo/emby-controller:latest
    container_name: emby-controller
    restart: unless-stopped
    ports:
      - "8018:8018"
    volumes:
      - ./.env:/app/.env
    environment:
      - TZ=Asia/Shanghai
```

启动：
```bash
docker-compose up -d
```

### 手动部署

1. **环境要求**
   - PHP 8.0+
   - MySQL 5.7+
   - Redis
   - Composer

2. **安装步骤**
```bash
# 克隆项目
git clone https://github.com/tamakooooo/EmbyController.git
cd EmbyController

# 安装依赖
composer install

# 配置环境
cp example.env .env
vi .env

# 导入数据库
mysql -u root -p your_database < demomedia_2025-02-14.sql

# 启动服务
php think run
```

默认账号：`admin` / `A123456`

## 配置说明

主要配置项（.env 文件）：

| 配置项 | 说明 |
|--------|------|
| `DB_HOST` | 数据库地址 |
| `DB_NAME` | 数据库名称 |
| `DB_USER` | 数据库用户 |
| `DB_PASS` | 数据库密码 |
| `EMBY_URL` | Emby 服务器地址 |
| `EMBY_API_KEY` | Emby API Key |
| `RENEW_COST` | 续期费用（默认 10） |
| `RENEW_DAYS` | 续期天数（默认 30） |

完整配置请参考 `example.env` 文件。

## 技术栈

- **后端**：PHP 8 + ThinkPHP 6
- **前端**：HTML + JavaScript + TailwindCSS
- **数据库**：MySQL + Redis
- **其他**：Telegram Bot API、Cloudflare Turnstile

## 预览

| 首页 | 控制台 |
|------|--------|
| ![首页](image/index1.png) | ![控制台](image/dashboard.png) |

| 账号管理 | 工单系统 |
|----------|----------|
| ![账号](image/account-active.png) | ![工单](image/request-detail.png) |

## 许可证

[Apache License 2.0](LICENSE)