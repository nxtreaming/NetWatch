# NetWatch API 授权系统

## 概述

NetWatch API授权系统允许管理员为特定用户创建Token，授权访问固定数量的代理服务器。每个Token在有效期内返回的代理列表是固定的，确保用户获得稳定的代理资源。

## 主要特性

- ✅ **基于Token的授权机制** - 安全的API访问控制
- ✅ **固定代理分配** - 同一Token始终返回相同的代理列表
- ✅ **多种输出格式** - 支持JSON、文本、列表格式
- ✅ **灵活的认证方式** - URL参数、POST参数、Authorization头
- ✅ **完整的管理界面** - 可视化Token管理和API测试
- ✅ **智能代理分配** - 优先分配在线且响应快的代理

## 系统架构

```
NetWatch API系统
├── 数据库层
│   ├── api_tokens (Token信息表)
│   └── token_proxy_assignments (Token-代理分配表)
├── 管理层
│   ├── token_manager.php (Token管理界面)
│   └── api_demo.php (API使用示例)
├── API层
│   ├── api.php (RESTful API接口)
│   └── test_api.php (功能测试脚本)
└── 集成层
    └── index.php (主系统集成)
```

## 快速开始

### 1. 创建API Token

访问 `token_manager.php` 页面：

1. 填写Token名称（如："客户A的代理授权"）
2. 设置代理数量（1-1000个）
3. 选择有效期（7天-1年）
4. 点击"创建Token"

系统会自动：
- 生成64位安全Token
- 分配指定数量的最优代理
- 创建Token-代理绑定关系

### 2. 使用API获取代理

#### 基本用法
```bash
# 获取JSON格式的代理列表
curl "https://your-domain.com/api.php?action=proxies&token=YOUR_TOKEN"

# 获取文本格式的代理URL
curl "https://your-domain.com/api.php?action=proxies&token=YOUR_TOKEN&format=txt"

# 获取简单列表格式
curl "https://your-domain.com/api.php?action=proxies&token=YOUR_TOKEN&format=list"
```

#### 使用Authorization头
```bash
curl -H "Authorization: Bearer YOUR_TOKEN" \
     "https://your-domain.com/api.php?action=proxies"
```

## API端点详情

### 1. 获取代理列表
- **端点**: `GET /api.php?action=proxies`
- **参数**: 
  - `token` (必需): API Token
  - `format` (可选): 输出格式 `json|txt|list`
- **返回**: 授权的代理服务器列表

**JSON格式响应示例**:
```json
{
  "success": true,
  "data": {
    "token_name": "客户A的代理授权",
    "total_assigned": 10,
    "online_count": 8,
    "proxies": [
      {
        "id": 123,
        "host": "1.2.3.4",
        "port": 8080,
        "type": "http",
        "status": "online",
        "response_time": 150.5,
        "auth": {
          "username": "user",
          "password": "pass"
        }
      }
    ]
  },
  "timestamp": 1692604800
}
```

**文本格式响应示例**:
```
http://user:pass@1.2.3.4:8080
socks5://user2:pass2@5.6.7.8:1080
http://9.10.11.12:3128
```

### 2. 获取Token信息
- **端点**: `GET /api.php?action=info`
- **参数**: `token` (必需)
- **返回**: Token的基本信息和统计数据

### 3. 获取状态统计
- **端点**: `GET /api.php?action=status`
- **参数**: 
  - `token` (必需)
  - `proxy_id` (可选): 特定代理ID
- **返回**: 代理状态统计信息

### 4. 帮助信息
- **端点**: `GET /api.php?action=help`
- **返回**: API使用说明和端点列表

## 代理分配算法

系统使用智能算法为Token分配代理：

1. **优先级排序**: 在线 > 未知 > 离线
2. **性能排序**: 响应时间越短越优先
3. **稳定性排序**: 失败次数越少越优先
4. **固定分配**: 一旦分配完成，Token始终返回相同代理

## 安全特性

- **Token唯一性**: 64位随机Token，防止碰撞
- **有效期控制**: 支持灵活的过期时间设置
- **权限隔离**: 每个Token只能访问分配给它的代理
- **请求验证**: 多重验证机制防止未授权访问
- **CORS支持**: 支持跨域请求，便于前端集成

## 管理功能

### Token管理 (`token_manager.php`)
- 创建新Token
- 查看Token列表和状态
- 刷新Token有效期
- 重新分配代理数量
- 删除过期Token
- 复制Token到剪贴板

### API测试 (`api_demo.php`)
- 在线API测试工具
- 多种格式预览
- 代码示例展示
- 实时响应查看

## 编程语言示例

### PHP
```php
<?php
$token = "YOUR_TOKEN_HERE";
$url = "https://your-domain.com/api.php?action=proxies&token=" . $token;

$response = file_get_contents($url);
$data = json_decode($response, true);

if ($data["success"]) {
    foreach ($data["data"]["proxies"] as $proxy) {
        echo $proxy["type"] . "://" . $proxy["host"] . ":" . $proxy["port"] . "\n";
    }
}
?>
```

### Python
```python
import requests

token = "YOUR_TOKEN_HERE"
url = "https://your-domain.com/api.php"

response = requests.get(url, params={"action": "proxies", "token": token})
data = response.json()

if data["success"]:
    for proxy in data["data"]["proxies"]:
        print(f"{proxy['type']}://{proxy['host']}:{proxy['port']}")
```

### JavaScript
```javascript
const token = "YOUR_TOKEN_HERE";
const url = "https://your-domain.com/api.php";

fetch(`${url}?action=proxies&token=${token}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            data.data.proxies.forEach(proxy => {
                console.log(`${proxy.type}://${proxy.host}:${proxy.port}`);
            });
        }
    });
```

## 故障排除

### 常见错误

1. **"Invalid or expired token"**
   - 检查Token是否正确
   - 确认Token未过期
   - 验证Token是否被删除

2. **"No proxies available"**
   - 检查系统中是否有可用代理
   - 确认代理状态是否正常
   - 考虑重新分配代理

3. **HTTP 404错误**
   - 确认API端点URL正确
   - 检查服务器配置

### 调试技巧

1. 使用 `action=help` 查看API文档
2. 使用 `action=info` 检查Token状态
3. 查看 `api_demo.php` 进行在线测试
4. 运行 `test_api.php` 进行功能验证

## 性能优化

- **缓存机制**: Token验证结果缓存
- **数据库索引**: 关键字段建立索引
- **批量操作**: 支持批量代理分配
- **连接复用**: 数据库连接优化

## 版本历史

- **v1.0.0** - 初始版本，基础Token授权功能
- 支持多种输出格式
- 完整的管理界面
- 智能代理分配算法

## 技术支持

如需技术支持，请：
1. 查看API示例页面 (`api_demo.php`)
2. 运行测试脚本 (`test_api.php`)
3. 检查系统日志文件
4. 联系系统管理员

---

**NetWatch API授权系统** - 为代理服务提供安全、稳定的API访问解决方案。
