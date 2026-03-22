        <div class="auto-refresh">
            <span class="refresh-indicator"></span>
            页面每5分钟自动刷新一次
        </div>
    </div>
    
    <!-- Bootstrap JSON data for external JS -->
    <script type="application/json" id="proxy-status-data" nonce="<?php echo htmlspecialchars(netwatch_get_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
    <?php 
      $bootstrap = [
        'currentSnapshotDate' => $snapshotDate,
        'currentQueryDate' => $queryDate ?? '',
        'csrfToken' => Auth::getCsrfToken(),
        'today' => date('Y-m-d'),
        'intervalMs' => defined('TRAFFIC_UPDATE_INTERVAL') ? TRAFFIC_UPDATE_INTERVAL * 1000 : 300000,
        'todaySnapshots' => !empty($todaySnapshots) ? array_values($todaySnapshots) : [],
        'chartDisplayContext' => $chartDisplayContext ?? ['initial_interval_mb' => 0],
        'isViewingToday' => $isViewingToday,
      ];
      echo json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    ?>
    </script>
    <!-- 新模块化JS -->
    <script src="<?php echo htmlspecialchars($appRootPath . 'includes/js/core.js?v=' . filemtime(__DIR__ . '/../../includes/js/core.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($appRootPath . 'includes/js/ui.js?v=' . filemtime(__DIR__ . '/../../includes/js/ui.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($appRootPath . 'includes/utils.js?v=' . filemtime(__DIR__ . '/../../includes/utils.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    </div>
</body>
</html>
