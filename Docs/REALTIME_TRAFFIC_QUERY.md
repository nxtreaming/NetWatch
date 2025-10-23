# 实时流量查询功能说明

## 功能概述

为 `/proxy-status/` 流量监控页面添加了查询指定日期实时流量的功能，用户可以查看历史任意一天的流量趋势图。

## 主要特性

### 1. 日期选择器
- 位置：实时流量趋势图上方
- 功能：选择要查询的日期
- 限制：最大日期为今天（不能查询未来日期）

### 2. 查询按钮
- 点击"查询"按钮加载指定日期的流量数据
- 显示该日期每5分钟的增量流量消耗

### 3. 返回今日按钮
- 当查询历史日期时显示
- 点击快速返回今日的实时流量数据

### 4. 查询结果提示
- 查询历史日期时显示蓝色提示框
- 明确显示当前查看的日期

### 5. 无数据提示
- 当选择的日期没有流量快照数据时
- 显示黄色警告提示框

## 技术实现

### 后端修改

#### 1. traffic_monitor.php
```php
/**
 * 获取指定日期的流量快照用于图表展示
 */
public function getSnapshotsByDate($date) {
    return $this->db->getTrafficSnapshotsByDate($date);
}
```

#### 2. index.php
```php
// 处理实时流量图表的日期查询
$snapshotDate = isset($_GET['snapshot_date']) ? $_GET['snapshot_date'] : null;

if ($snapshotDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate)) {
    // 获取指定日期的流量快照
    $todaySnapshots = $trafficMonitor->getSnapshotsByDate($snapshotDate);
} else {
    // 默认获取今日流量快照
    $todaySnapshots = $trafficMonitor->getTodaySnapshots();
    $snapshotDate = date('Y-m-d');
}
```

### 前端UI

#### 日期选择表单
```html
<form method="GET">
    <label>查询日期:</label>
    <input type="date" 
           name="snapshot_date" 
           value="<?php echo $snapshotDate; ?>"
           max="<?php echo date('Y-m-d'); ?>">
    <button type="submit">查询</button>
    <a href="?">返回今日</a>
</form>
```

## URL参数

### snapshot_date
- 用途：查询实时流量图表的日期
- 格式：YYYY-MM-DD
- 示例：`?snapshot_date=2025-10-22`

### date
- 用途：查询每日统计表格的日期范围
- 格式：YYYY-MM-DD
- 示例：`?date=2025-10-22`

### 组合使用
两个参数可以独立使用，互不影响：
```
?snapshot_date=2025-10-22&date=2025-10-20
```
- 实时流量图显示：2025-10-22 的数据
- 每日统计表显示：2025-10-20 前后7天的数据

## 使用场景

### 1. 查看历史流量趋势
- 分析某一天的流量使用模式
- 对比不同日期的流量消耗情况

### 2. 问题排查
- 查看流量异常日期的详细数据
- 分析流量高峰时段

### 3. 数据分析
- 统计特定日期的流量分布
- 识别流量使用规律

## 移动端适配

- 日期选择器在移动端自动垂直排列
- 按钮宽度自适应屏幕
- 保持良好的触摸操作体验

## 数据来源

- 数据表：`traffic_snapshots`
- 更新频率：每5分钟
- 数据保留：根据系统配置

## 注意事项

1. **数据可用性**：只能查询有快照数据的日期
2. **日期限制**：不能查询未来日期
3. **性能考虑**：历史数据查询不影响实时监控
4. **参数独立**：实时流量查询和每日统计查询互不影响

## 示例

### 查询昨天的实时流量
```
/proxy-status/?snapshot_date=2025-10-22
```

### 查询本周一的实时流量
```
/proxy-status/?snapshot_date=2025-10-20
```

### 同时查询不同日期的数据
```
/proxy-status/?snapshot_date=2025-10-22&date=2025-10-20
```
- 图表显示：10月22日的实时流量
- 表格显示：10月20日前后7天的统计

## 更新日志

- **2025-10-23**: 初始版本，添加实时流量日期查询功能
