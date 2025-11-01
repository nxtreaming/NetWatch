        <div class="auto-refresh">
            <span class="refresh-indicator"></span>
            页面每5分钟自动刷新一次
        </div>
    </div>
    
    <!-- Chart.js 库 -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Bootstrap JSON data for external JS -->
    <script type="application/json" id="proxy-status-data">
    <?php 
      $bootstrap = [
        'currentSnapshotDate' => $snapshotDate,
        'currentQueryDate' => $queryDate ? htmlspecialchars($queryDate) : '',
        'today' => date('Y-m-d'),
        'intervalMs' => defined('TRAFFIC_UPDATE_INTERVAL') ? TRAFFIC_UPDATE_INTERVAL * 1000 : 300000,
        'todaySnapshots' => !empty($todaySnapshots) ? array_values($todaySnapshots) : [],
        'isViewingToday' => $isViewingToday,
      ];
      echo json_encode($bootstrap, JSON_UNESCAPED_UNICODE);
    ?>
    </script>
    </div>
</body>
</html>
