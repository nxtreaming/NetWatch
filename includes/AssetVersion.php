<?php
/**
 * 前端资源版本管理器
 * 提供统一的版本号管理，避免缓存问题
 */

class AssetVersion {
    private static ?string $version = null;
    private static array $fileVersions = [];
    
    /**
     * 获取应用版本号
     * 优先使用配置的版本号，否则使用文件修改时间
     */
    public static function getVersion(): string {
        if (self::$version === null) {
            // 尝试从配置获取版本号
            if (defined('APP_VERSION')) {
                self::$version = APP_VERSION;
            } else {
                // 使用日期作为默认版本
                self::$version = date('Ymd');
            }
        }
        return self::$version;
    }
    
    /**
     * 设置应用版本号
     */
    public static function setVersion(string $version): void {
        self::$version = $version;
    }
    
    /**
     * 获取带版本号的资源URL
     * @param string $path 资源路径
     * @param bool $useFileTime 是否使用文件修改时间作为版本
     * @return string
     */
    public static function url(string $path, bool $useFileTime = false): string {
        $version = self::getVersion();
        
        if ($useFileTime) {
            $version = self::getFileVersion($path);
        }
        
        $separator = strpos($path, '?') !== false ? '&' : '?';
        return $path . $separator . 'v=' . $version;
    }
    
    /**
     * 获取文件版本（基于修改时间）
     */
    public static function getFileVersion(string $path): string {
        // 缓存文件版本
        if (isset(self::$fileVersions[$path])) {
            return self::$fileVersions[$path];
        }
        
        // 构建完整路径
        $fullPath = $path;
        if (!file_exists($fullPath) && defined('ROOT_PATH')) {
            $fullPath = ROOT_PATH . ltrim($path, '/');
        }
        
        if (file_exists($fullPath)) {
            self::$fileVersions[$path] = filemtime($fullPath);
        } else {
            self::$fileVersions[$path] = self::getVersion();
        }
        
        return (string)self::$fileVersions[$path];
    }
    
    /**
     * 生成CSS链接标签
     */
    public static function css(string $path, bool $useFileTime = false): string {
        $url = self::url($path, $useFileTime);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url) . '">';
    }
    
    /**
     * 生成JS脚本标签
     */
    public static function js(string $path, bool $useFileTime = false, bool $defer = false, bool $async = false): string {
        $url = self::url($path, $useFileTime);
        $attrs = [];
        
        if ($defer) $attrs[] = 'defer';
        if ($async) $attrs[] = 'async';
        
        $attrStr = !empty($attrs) ? ' ' . implode(' ', $attrs) : '';
        return '<script src="' . htmlspecialchars($url) . '"' . $attrStr . '></script>';
    }
    
    /**
     * 批量生成CSS链接
     */
    public static function cssBundle(array $paths, bool $useFileTime = false): string {
        $output = '';
        foreach ($paths as $path) {
            $output .= self::css($path, $useFileTime) . "\n";
        }
        return $output;
    }
    
    /**
     * 批量生成JS脚本
     */
    public static function jsBundle(array $paths, bool $useFileTime = false, bool $defer = false): string {
        $output = '';
        foreach ($paths as $path) {
            $output .= self::js($path, $useFileTime, $defer) . "\n";
        }
        return $output;
    }
    
    /**
     * 清除版本缓存
     */
    public static function clearCache(): void {
        self::$version = null;
        self::$fileVersions = [];
    }
}
