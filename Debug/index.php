<?php
/**
 * Debug 工具统一入口
 * 提供所有调试和测试工具的导航页面
 */

require_once '../auth.php';
Auth::requireLogin();

// 工具分类
$tools = [
    '系统诊断' => [
        ['file' => 'diagnose.php', 'name' => '系统诊断', 'desc' => '检查系统配置和运行状态'],
        ['file' => 'storage_diagnostic.php', 'name' => '存储诊断', 'desc' => '检查存储空间和Session状态'],
        ['file' => 'code_review.php', 'name' => '代码审查', 'desc' => '代码质量检查工具'],
    ],
    '代理调试' => [
        ['file' => 'debug_proxy.php', 'name' => '代理调试', 'desc' => '单个代理连接测试'],
        ['file' => 'check_failed_proxies.php', 'name' => '故障代理检查', 'desc' => '检查失败次数较高的代理'],
        ['file' => 'fix_proxy_auth.php', 'name' => '代理认证修复', 'desc' => '修复代理认证信息'],
    ],
    '并行检测调试' => [
        ['file' => 'test_parallel_monitor.php', 'name' => '并行检测测试', 'desc' => '测试并行检测功能'],
        ['file' => 'test_parallel_progress.php', 'name' => '进度查询测试', 'desc' => '测试进度查询接口'],
        ['file' => 'debug_batch_sync.php', 'name' => '批次同步调试', 'desc' => '调试批次处理同步'],
        ['file' => 'test_batch_completion.php', 'name' => '批次完成测试', 'desc' => '测试批次完成逻辑'],
        ['file' => 'test_batch_status.php', 'name' => '批次状态测试', 'desc' => '测试批次状态更新'],
        ['file' => 'debug_check_all.php', 'name' => '全量检测调试', 'desc' => '调试全量检测功能'],
    ],
    '邮件测试' => [
        ['file' => 'test_email.php', 'name' => '邮件测试', 'desc' => '完整邮件发送测试'],
        ['file' => 'test_email_simple.php', 'name' => '简化邮件测试', 'desc' => '简化版邮件测试'],
        ['file' => 'test_mail_debug.php', 'name' => '邮件调试', 'desc' => '邮件发送详细调试'],
    ],
    '性能测试' => [
        ['file' => 'test_performance.php', 'name' => '性能测试', 'desc' => '系统性能基准测试'],
        ['file' => 'test_prepare_performance.php', 'name' => '预处理性能', 'desc' => '预处理语句性能测试'],
        ['file' => 'test_prepare_optimization.php', 'name' => '预处理优化', 'desc' => '预处理优化效果测试'],
        ['file' => 'test_timeout.php', 'name' => '超时测试', 'desc' => '连接超时测试'],
        ['file' => 'test_retry_mechanism.php', 'name' => '重试机制测试', 'desc' => '测试失败重试机制'],
    ],
    '功能测试' => [
        ['file' => 'test_search.php', 'name' => '搜索测试', 'desc' => '测试搜索功能'],
        ['file' => 'test_pagination.php', 'name' => '分页测试', 'desc' => '测试分页功能'],
        ['file' => 'test_login.php', 'name' => '登录测试', 'desc' => '测试登录功能'],
        ['file' => 'test_ajax_validation.php', 'name' => 'AJAX验证测试', 'desc' => '测试AJAX请求验证'],
        ['file' => 'test_sensitive_data.php', 'name' => '敏感数据测试', 'desc' => '测试敏感数据过滤'],
        ['file' => 'test_js_syntax.php', 'name' => 'JS语法检查', 'desc' => '检查JavaScript语法'],
    ],
    '流量监控调试' => [
        ['file' => 'check_snapshots.php', 'name' => '快照检查', 'desc' => '检查流量快照数据'],
        ['file' => 'check_stats_data.php', 'name' => '统计数据检查', 'desc' => '检查流量统计数据'],
        ['file' => 'test_traffic_reset.php', 'name' => '流量重置测试', 'desc' => '测试流量重置逻辑'],
        ['file' => 'test_timezone.php', 'name' => '时区测试', 'desc' => '测试时区设置'],
    ],
    '日志查看' => [
        ['file' => 'view_debug_log.php', 'name' => '调试日志', 'desc' => '查看系统调试日志'],
    ],
    '其他工具' => [
        ['file' => 'test.php', 'name' => '通用测试', 'desc' => '通用测试脚本'],
        ['file' => 'test_mobile.html', 'name' => '移动端测试', 'desc' => '移动端UI测试页面'],
    ],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug 工具中心 - NetWatch</title>
    <link rel="stylesheet" href="../includes/style-v2.css">
    <style>
        .debug-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        .debug-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1e3a5f, #0d1b2a);
            border-radius: 12px;
        }
        .debug-header h1 {
            color: #e2e8f0;
            margin: 0 0 10px 0;
            font-size: 28px;
        }
        .debug-header p {
            color: #94a3b8;
            margin: 0;
        }
        .category {
            margin-bottom: 30px;
        }
        .category-title {
            color: #3b82f6;
            font-size: 20px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1e3a5f;
        }
        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 15px;
        }
        .tool-card {
            background: #111c32;
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 10px;
            padding: 15px;
            transition: all 0.3s ease;
        }
        .tool-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(59, 130, 246, 0.2);
        }
        .tool-card a {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .tool-name {
            color: #e2e8f0;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .tool-desc {
            color: #94a3b8;
            font-size: 13px;
            line-height: 1.5;
        }
        .tool-file {
            color: #64748b;
            font-size: 11px;
            margin-top: 10px;
            font-family: monospace;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3b82f6;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .warning-box {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid #f59e0b;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #fbbf24;
        }
    </style>
</head>
<body>
    <div class="debug-container">
        <a href="../index.php" class="back-link">← 返回主页</a>
        
        <div class="debug-header">
            <h1>🔧 Debug 工具中心</h1>
            <p>NetWatch 系统调试和测试工具集合</p>
        </div>
        
        <div class="warning-box">
            ⚠️ 警告：这些工具仅供开发和调试使用，请勿在生产环境中随意执行可能影响系统稳定性的操作。
        </div>
        
        <?php foreach ($tools as $category => $categoryTools): ?>
        <div class="category">
            <h2 class="category-title"><?php echo htmlspecialchars($category); ?></h2>
            <div class="tools-grid">
                <?php foreach ($categoryTools as $tool): ?>
                <div class="tool-card">
                    <a href="<?php echo htmlspecialchars($tool['file']); ?>">
                        <div class="tool-name"><?php echo htmlspecialchars($tool['name']); ?></div>
                        <div class="tool-desc"><?php echo htmlspecialchars($tool['desc']); ?></div>
                        <div class="tool-file"><?php echo htmlspecialchars($tool['file']); ?></div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
