<?php
/**
 * 存储空间和Session诊断工具
 * 帮助管理员排查登录问题
 */

require_once '../config.php';
require_once '../auth.php';

// 只允许已登录用户访问，如果无法登录则跳过验证
$skipAuth = isset($_GET['skip_auth']) && $_GET['skip_auth'] === 'true';
if (!$skipAuth) {
    Auth::requireLogin();
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NetWatch - 存储诊断工具</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 20px;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #eee;
        }
        .diagnostic-section {
            margin-bottom: 30px;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 6px;
        }
        .diagnostic-section h3 {
            margin-top: 0;
            color: #333;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .status-ok { color: #28a745; }
        .status-warning { color: #ffc107; }
        .status-critical { color: #dc3545; }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        .info-table th, .info-table td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .info-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .test-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
        }
        .test-button:hover {
            background: #0056b3;
        }
        .alert {
            padding: 12px;
            border-radius: 4px;
            margin: 10px 0;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔍 NetWatch 存储诊断工具</h1>
            <p>系统存储空间和Session状态诊断</p>
        </div>

        <?php
        // 获取存储空间状态
        $storageStatus = Auth::checkStorageSpace();
        
        // 获取session配置信息
        $sessionPath = session_save_path();
        if (empty($sessionPath)) {
            $sessionPath = sys_get_temp_dir();
        }
        
        // 获取磁盘空间信息
        $freeBytes = disk_free_space($sessionPath);
        $totalBytes = disk_total_space($sessionPath);
        $usedBytes = $totalBytes - $freeBytes;
        
        // 检查session目录权限
        $sessionWritable = is_writable($sessionPath);
        $sessionReadable = is_readable($sessionPath);
        
        // 测试session写入
        $sessionTestResult = null;
        if (isset($_POST['test_session'])) {
            session_start();
            $_SESSION['test_key'] = 'test_value_' . time();
            session_write_close();
            
            session_start();
            if (isset($_SESSION['test_key']) && $_SESSION['test_key'] === $_SESSION['test_key']) {
                $sessionTestResult = 'success';
                unset($_SESSION['test_key']);
            } else {
                $sessionTestResult = 'failed';
            }
        }
        ?>

        <!-- 存储空间状态 -->
        <div class="diagnostic-section">
            <h3>💾 存储空间状态</h3>
            
            <?php if ($storageStatus['status'] === 'critical'): ?>
                <div class="alert alert-danger">
                    <strong>❌ 严重警告：</strong> <?php echo $storageStatus['message']; ?>
                </div>
            <?php elseif ($storageStatus['status'] === 'warning'): ?>
                <div class="alert alert-warning">
                    <strong>⚠️ 警告：</strong> <?php echo $storageStatus['message']; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    <strong>✅ 正常：</strong> <?php echo $storageStatus['message']; ?>
                </div>
            <?php endif; ?>

            <table class="info-table">
                <tr>
                    <th>项目</th>
                    <th>值</th>
                    <th>状态</th>
                </tr>
                <tr>
                    <td>总空间</td>
                    <td><?php echo round($totalBytes / 1024 / 1024 / 1024, 2); ?> GB</td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>已使用空间</td>
                    <td><?php echo round($usedBytes / 1024 / 1024 / 1024, 2); ?> GB</td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>可用空间</td>
                    <td><?php echo round($freeBytes / 1024 / 1024, 2); ?> MB</td>
                    <td class="<?php echo $storageStatus['status'] === 'ok' ? 'status-ok' : ($storageStatus['status'] === 'warning' ? 'status-warning' : 'status-critical'); ?>">
                        <?php echo round($storageStatus['free_percent'], 2); ?>%
                    </td>
                </tr>
            </table>
        </div>

        <!-- Session配置信息 -->
        <div class="diagnostic-section">
            <h3>🔧 Session配置信息</h3>
            
            <table class="info-table">
                <tr>
                    <th>配置项</th>
                    <th>值</th>
                    <th>状态</th>
                </tr>
                <tr>
                    <td>Session保存路径</td>
                    <td><?php echo htmlspecialchars($sessionPath); ?></td>
                    <td class="<?php echo file_exists($sessionPath) ? 'status-ok' : 'status-critical'; ?>">
                        <?php echo file_exists($sessionPath) ? '✅ 存在' : '❌ 不存在'; ?>
                    </td>
                </tr>
                <tr>
                    <td>目录可读</td>
                    <td><?php echo $sessionReadable ? '是' : '否'; ?></td>
                    <td class="<?php echo $sessionReadable ? 'status-ok' : 'status-critical'; ?>">
                        <?php echo $sessionReadable ? '✅ 正常' : '❌ 异常'; ?>
                    </td>
                </tr>
                <tr>
                    <td>目录可写</td>
                    <td><?php echo $sessionWritable ? '是' : '否'; ?></td>
                    <td class="<?php echo $sessionWritable ? 'status-ok' : 'status-critical'; ?>">
                        <?php echo $sessionWritable ? '✅ 正常' : '❌ 异常'; ?>
                    </td>
                </tr>
                <tr>
                    <td>Session处理器</td>
                    <td><?php echo ini_get('session.save_handler'); ?></td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Session超时时间</td>
                    <td><?php echo ini_get('session.gc_maxlifetime'); ?> 秒</td>
                    <td>-</td>
                </tr>
            </table>
        </div>

        <!-- Session功能测试 -->
        <div class="diagnostic-section">
            <h3>🧪 Session功能测试</h3>
            
            <?php if ($sessionTestResult === 'success'): ?>
                <div class="alert alert-success">
                    <strong>✅ 测试成功：</strong> Session读写功能正常
                </div>
            <?php elseif ($sessionTestResult === 'failed'): ?>
                <div class="alert alert-danger">
                    <strong>❌ 测试失败：</strong> Session无法正常写入或读取
                </div>
            <?php endif; ?>

            <form method="POST">
                <button type="submit" name="test_session" class="test-button">
                    🧪 测试Session读写功能
                </button>
            </form>
        </div>

        <!-- 问题解决建议 -->
        <div class="diagnostic-section">
            <h3>💡 问题解决建议</h3>
            
            <?php if ($storageStatus['status'] === 'critical'): ?>
                <div class="alert alert-danger">
                    <strong>紧急处理建议：</strong>
                    <ul>
                        <li>立即清理磁盘空间，删除不必要的文件</li>
                        <li>检查日志文件是否过大：<code>/var/log/</code></li>
                        <li>清理临时文件：<code><?php echo $sessionPath; ?></code></li>
                        <li>检查数据库文件大小</li>
                        <li>考虑增加磁盘容量</li>
                    </ul>
                </div>
            <?php elseif ($storageStatus['status'] === 'warning'): ?>
                <div class="alert alert-warning">
                    <strong>预防性建议：</strong>
                    <ul>
                        <li>定期清理日志文件</li>
                        <li>设置日志轮转机制</li>
                        <li>监控磁盘使用情况</li>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!$sessionWritable): ?>
                <div class="alert alert-danger">
                    <strong>Session目录权限问题：</strong>
                    <ul>
                        <li>检查目录权限：<code>chmod 755 <?php echo $sessionPath; ?></code></li>
                        <li>检查目录所有者：<code>chown www-data:www-data <?php echo $sessionPath; ?></code></li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <!-- 系统命令 -->
        <div class="diagnostic-section">
            <h3>🖥️ 有用的系统命令</h3>
            
            <table class="info-table">
                <tr>
                    <th>功能</th>
                    <th>命令</th>
                </tr>
                <tr>
                    <td>查看磁盘使用情况</td>
                    <td><code>df -h</code></td>
                </tr>
                <tr>
                    <td>查看目录大小</td>
                    <td><code>du -sh <?php echo dirname($sessionPath); ?>/*</code></td>
                </tr>
                <tr>
                    <td>清理Session文件</td>
                    <td><code>find <?php echo $sessionPath; ?> -name "sess_*" -mtime +1 -delete</code></td>
                </tr>
                <tr>
                    <td>查看最大的文件</td>
                    <td><code>find / -type f -size +100M -ls 2>/dev/null</code></td>
                </tr>
            </table>
        </div>

        <div style="text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
            <p><a href="../index.php">← 返回主页</a> | <a href="?skip_auth=true">跳过登录验证访问</a></p>
        </div>
    </div>
</body>
</html>
