<?php
/**
 * 简单服务容器
 * 提供依赖注入和服务管理功能
 */

class Container {
    private static ?Container $instance = null;
    private array $bindings = [];
    private array $instances = [];
    
    private function __construct() {}
    
    /**
     * 获取容器单例
     */
    public static function getInstance(): Container {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * 绑定服务
     * @param string $abstract 抽象名称
     * @param callable|string|null $concrete 具体实现
     * @param bool $shared 是否单例
     */
    public function bind(string $abstract, $concrete = null, bool $shared = false): void {
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => $shared
        ];
    }
    
    /**
     * 绑定单例服务
     */
    public function singleton(string $abstract, $concrete = null): void {
        $this->bind($abstract, $concrete, true);
    }
    
    /**
     * 绑定已存在的实例
     */
    public function instance(string $abstract, $instance): void {
        $this->instances[$abstract] = $instance;
    }
    
    /**
     * 解析服务
     * @param string $abstract 抽象名称
     * @return mixed
     */
    public function make(string $abstract) {
        // 如果已有实例，直接返回
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }
        
        // 获取绑定信息
        $binding = $this->bindings[$abstract] ?? null;
        
        if ($binding === null) {
            // 尝试直接实例化
            return $this->build($abstract);
        }
        
        $concrete = $binding['concrete'];
        
        // 如果是闭包，执行它
        if ($concrete instanceof Closure) {
            $object = $concrete($this);
        } elseif (is_string($concrete)) {
            $object = $this->build($concrete);
        } else {
            $object = $concrete;
        }
        
        // 如果是单例，保存实例
        if ($binding['shared']) {
            $this->instances[$abstract] = $object;
        }
        
        return $object;
    }
    
    /**
     * 构建类实例
     */
    private function build(string $concrete) {
        if (!class_exists($concrete)) {
            throw new RuntimeException("Class {$concrete} does not exist");
        }
        
        $reflector = new ReflectionClass($concrete);
        
        if (!$reflector->isInstantiable()) {
            throw new RuntimeException("Class {$concrete} is not instantiable");
        }
        
        $constructor = $reflector->getConstructor();
        
        if ($constructor === null) {
            return new $concrete();
        }
        
        $dependencies = $this->resolveDependencies($constructor->getParameters());
        
        return $reflector->newInstanceArgs($dependencies);
    }
    
    /**
     * 解析构造函数依赖
     */
    private function resolveDependencies(array $parameters): array {
        $dependencies = [];
        
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            
            if ($type === null || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                } else {
                    throw new RuntimeException(
                        "Cannot resolve parameter {$parameter->getName()}"
                    );
                }
            } else {
                $dependencies[] = $this->make($type->getName());
            }
        }
        
        return $dependencies;
    }
    
    /**
     * 检查是否已绑定
     */
    public function has(string $abstract): bool {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }
    
    /**
     * 快捷方法：获取数据库实例
     */
    public function db(): Database {
        return $this->make(Database::class);
    }
    
    /**
     * 快捷方法：获取监控器实例
     */
    public function monitor(): NetworkMonitor {
        return $this->make(NetworkMonitor::class);
    }
    
    /**
     * 快捷方法：获取日志实例
     */
    public function logger(): Logger {
        return $this->make(Logger::class);
    }
}

/**
 * 全局辅助函数：获取容器实例
 */
function app(?string $abstract = null) {
    $container = Container::getInstance();
    
    if ($abstract === null) {
        return $container;
    }
    
    return $container->make($abstract);
}
