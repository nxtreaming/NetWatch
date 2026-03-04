<?php
/**
 * 登录页面
 */

require_once 'config.php';
require_once 'auth.php';
require_once 'includes/RateLimiter.php';

// 如果未启用登录功能或已登录，重定向到主页
if (!Auth::isLoginEnabled() || Auth::isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$warning = '';
$errorDetail = '';
// 初始化表单字段，默认空字符串，并进行trim处理
$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';
$csrfToken = Auth::getCsrfToken();

// 检查存储空间状态
$storageStatus = Auth::checkStorageSpace();
if ($storageStatus['status'] === 'critical') {
    $error = $storageStatus['message'] . '。请联系系统管理员清理磁盘空间。';
} elseif ($storageStatus['status'] === 'warning') {
    $warning = $storageStatus['message'] . '。建议清理磁盘空间以确保系统正常运行。';
}

// 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedCsrfToken) || !Auth::validateCsrfToken($submittedCsrfToken)) {
        $error = '请求已失效，请刷新页面后重试';
    }

    // 对用户名和密码进行trim，避免首尾空格导致的登录失败
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($error) && (empty($username) || empty($password))) {
        $error = '请输入用户名和密码';
    } elseif (empty($error)) {
        // 如果存储空间严重不足，阻止登录尝试
        if ($storageStatus['status'] === 'critical') {
            $error = '服务器存储空间不足，无法完成登录。请联系系统管理员清理磁盘空间。';
        } else {
            $loginLimiter = RateLimitPresets::login();
            $loginKey = 'login:' . RateLimiter::getClientIp();
            if (!$loginLimiter->attempt($loginKey)) {
                $retryAfter = $loginLimiter->retryAfter($loginKey);
                $error = '登录尝试过于频繁，请稍后再试（' . $retryAfter . ' 秒后可重试）';
            } else {
                $loginResult = Auth::login($username, $password);
                
                if ($loginResult === true) {
                    $loginLimiter->clear($loginKey);
                    $success = '登录成功，正在跳转...';
                    $redirectUrl = Auth::getRedirectUrl();
                    header("refresh:1;url=$redirectUrl");
                } elseif ($loginResult === 'session_write_failed') {
                    $error = '登录验证成功，但由于服务器存储空间不足，无法保存登录状态。请联系系统管理员清理磁盘空间后重试。';
                    // 重新检查存储空间状态
                    $storageStatus = Auth::checkStorageSpace();
                    if ($storageStatus['status'] !== 'unknown') {
                        $errorDetail = '当前可用空间：' . $storageStatus['free_mb'] . ' MB (' . round($storageStatus['free_percent'], 2) . '%)';
                    }
                } else {
                    $error = '用户名或密码错误';
                }
            }
        }
    }
}

// 登出操作由 index.php 的 POST+CSRF 表单处理完成后重定向到此页
// login.php 仅负责显示登出成功提示，不再执行 Auth::logout()
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $success = '已成功登出';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 用户登录</title>
    <style>
        :root {
            --color-bg: #0f172a;
            --color-panel: #111c32;
            --color-panel-light: #14213d;
            --color-border: rgba(255, 255, 255, 0.08);
            --color-text: #e2e8f0;
            --color-muted: #94a3b8;
            --color-primary: #3b82f6;
            --color-success: #10b981;
            --color-danger: #ef4444;
            --color-warning: #f59e0b;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--color-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--color-text);
        }
        
        .login-container {
            background: var(--color-panel);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(8, 15, 40, 0.35);
            border: 1px solid var(--color-border);
            width: 100%;
            max-width: 400px;
            text-align: center;
        }
        
        .logo {
            font-size: 32px;
            font-weight: bold;
            color: var(--color-text);
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: var(--color-muted);
            margin-bottom: 30px;
            font-size: 14px;
        }
        
        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--color-text);
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            font-size: 14px;
            background: var(--color-panel-light);
            color: var(--color-text);
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: var(--color-primary);
        }
        
        .login-btn {
            width: 100%;
            padding: 12px;
            background: var(--color-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s ease;
        }
        
        .login-btn:hover {
            opacity: 0.9;
        }
        
        .login-btn:active {
            opacity: 0.8;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            border: 1px solid;
        }
        
        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            color: var(--color-danger);
            border-color: var(--color-danger);
        }
        
        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            color: var(--color-success);
            border-color: var(--color-success);
        }
        
        .alert-warning {
            background: rgba(245, 158, 11, 0.1);
            color: var(--color-warning);
            border-color: var(--color-warning);
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--color-border);
            color: var(--color-muted);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">🔍 NetWatch</div>
        <div class="subtitle">网络代理监控系统</div>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                ❌ <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                <?php if (!empty($errorDetail)): ?>
                    <br><small><?php echo htmlspecialchars($errorDetail, ENT_QUOTES, 'UTF-8'); ?></small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($warning): ?>
            <div class="alert alert-warning">
                ⚠️ <?php echo htmlspecialchars($warning); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                ✅ <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" 
                       value="<?php echo htmlspecialchars($username); ?>" 
                       required autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" 
                       required autocomplete="current-password">
            </div>
            
            <button type="submit" class="login-btn">登录</button>
        </form>
        

        
        <div class="footer">
            NetWatch &copy; 2025 - <?php echo date('Y'); ?> - 网络代理监控系统
        </div>
    </div>
    
    <script>
        // 自动聚焦到用户名输入框
        document.getElementById('username').focus();
        
        // 如果显示成功消息，自动跳转
        <?php if ($success): ?>
        setTimeout(function() {
            if (!window.location.href.includes('?')) {
                window.location.href = <?php echo json_encode(Auth::getRedirectUrl()); ?>;
            }
        }, 1500);
        <?php endif; ?>
    </script>
</body>
</html>
