        <div class="auto-refresh">
            <span class="refresh-indicator"></span>
            页面每5分钟自动刷新一次
        </div>
    </div>
    
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
