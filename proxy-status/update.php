<?php
/**
 * 手动更新流量数据的AJAX端点
 * 已禁用 - 只允许定时任务更新数据
 */

require_once __DIR__ . '/../includes/JsonResponse.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 禁止手动更新，只允许定时任务更新
JsonResponse::error('disabled', '手动更新已禁用，数据由定时任务自动更新', 403);
exit;
