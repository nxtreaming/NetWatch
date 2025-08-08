<?php
/**
 * NetWatch 通用工具函数
 */

/**
 * 验证是否为真正的AJAX请求
 * 防止移动端浏览器错误地将页面请求误认为AJAX请求
 */
function isValidAjaxRequest() {
    // 检查是否有XMLHttpRequest标头
    $isXmlHttpRequest = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                       strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    // 检查Accept标头是否包含json或任意类型
    $acceptsJson = isset($_SERVER['HTTP_ACCEPT']) && 
                   (strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false ||
                    strpos($_SERVER['HTTP_ACCEPT'], '*/*') !== false);
    
    // 检查Content-Type是否为json相关
    $contentTypeJson = isset($_SERVER['CONTENT_TYPE']) && 
                      strpos($_SERVER['CONTENT_TYPE'], 'json') !== false;
    
    // 检查Referer是否来自同一页面（防止直接访问）
    $hasValidReferer = isset($_SERVER['HTTP_REFERER']) && 
                      strpos($_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST']) !== false;
    
    // 检查是否为浏览器发起的请求（而不是直接访问）
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isBrowserRequest = !empty($userAgent) && 
                       (strpos($userAgent, 'Mozilla') !== false || 
                        strpos($userAgent, 'Chrome') !== false ||
                        strpos($userAgent, 'Safari') !== false ||
                        strpos($userAgent, 'Edge') !== false);
    
    // 移动端特殊处理：对于移动端浏览器，放宽验证条件
    $isMobile = strpos($userAgent, 'Mobile') !== false || 
                strpos($userAgent, 'Android') !== false || 
                strpos($userAgent, 'iPhone') !== false || 
                strpos($userAgent, 'iPad') !== false;
    
    // 如果是移动端且是浏览器请求，只要有Accept头就认为是有效的AJAX请求
    if ($isMobile && $isBrowserRequest && $acceptsJson) {
        return true;
    }
    
    // 特殊情况：如果是浏览器直接访问且没有Referer，则可能是问题请求
    if (!$hasValidReferer && $isBrowserRequest && !$isMobile) {
        // 检查是否是直接在地址栏输入或书签访问
        $isDirectAccess = !isset($_SERVER['HTTP_REFERER']) || empty($_SERVER['HTTP_REFERER']);
        if ($isDirectAccess) {
            return false; // 直接访问带ajax参数的URL，很可能是问题
        }
    }
    
    // 对于正常的AJAX请求，只要满足以下任一条件即可：
    // 1. 有XMLHttpRequest标头
    // 2. Accept头包含json或*/*
    // 3. 有有效的Referer且是浏览器请求
    return $isXmlHttpRequest || $acceptsJson || ($hasValidReferer && $isBrowserRequest);
}

/**
 * 格式化时间显示
 * @param string $timeString 时间字符串
 * @param string $format 时间格式，默认'm-d H:i'
 * @param bool $isUtc 是否为UTC时间，默认false（本地时间）
 * @return string 格式化后的时间字符串
 */
function formatTime($timeString, $format = 'm-d H:i', $isUtc = true) {
    if (!$timeString) {
        return 'N/A';
    }
    
    try {
        if ($isUtc) {
            // 数据库中的时间是UTC，转换为北京时间
            $dt = new DateTime($timeString, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Shanghai'));
        } else {
            // 直接格式化本地时间
            $dt = new DateTime($timeString);
        }
        return $dt->format($format);
    } catch (Exception $e) {
        // 如果转换失败，使用原始方法
        return date($format, strtotime($timeString));
    }
}
