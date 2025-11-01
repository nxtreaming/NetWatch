(function () {
  'use strict';

  function getInitialData() {
    const el = document.getElementById('proxy-status-data');
    if (!el) return null;
    try {
      return JSON.parse(el.textContent || '{}');
    } catch (e) {
      console.error('Failed to parse initial data JSON:', e);
      return null;
    }
  }

  const data = getInitialData() || {};
  let currentSnapshotDate = data.currentSnapshotDate || '';
  let currentQueryDate = data.currentQueryDate || '';
  let autoRefreshTimer = null;
  const TODAY = data.today || '';
  const INTERVAL_MS = Number(data.intervalMs || 300000);

  function startAutoRefresh() {
    if (autoRefreshTimer) {
      clearInterval(autoRefreshTimer);
    }
    autoRefreshTimer = setInterval(function () {
      location.reload();
    }, INTERVAL_MS);
  }

  async function updateTrafficChart(date) {
    try {
      const response = await fetch(`api.php?action=chart&date=${encodeURIComponent(date)}`);
      const result = await response.json();
      if (result.success) {
        createTrafficChart(result.data, date === TODAY);
      } else {
        console.error('图表更新失败:', result.message);
      }
    } catch (error) {
      console.error('图表请求失败:', error);
    }
  }

  function createTrafficChart(snapshots, isViewingToday) {
    if (!snapshots || snapshots.length === 0) return;

    const labels = snapshots.map(s => s.snapshot_time.substring(0, 5));
    const rxData = [];
    const txData = [];
    const totalData = [];

    for (let i = 0; i < snapshots.length; i++) {
      if (i === 0) {
        rxData.push(0);
        txData.push(0);
        totalData.push(0);
      } else {
        let rxIncrement = (snapshots[i].rx_bytes - snapshots[i - 1].rx_bytes) / (1024 * 1024);
        let txIncrement = (snapshots[i].tx_bytes - snapshots[i - 1].tx_bytes) / (1024 * 1024);
        let totalIncrement = (snapshots[i].total_bytes - snapshots[i - 1].total_bytes) / (1024 * 1024);
        if (totalIncrement < 0) {
          rxIncrement = snapshots[i].rx_bytes / (1024 * 1024);
          txIncrement = snapshots[i].tx_bytes / (1024 * 1024);
          totalIncrement = snapshots[i].total_bytes / (1024 * 1024);
        }
        rxData.push(Number(rxIncrement.toFixed(2)));
        txData.push(Number(txIncrement.toFixed(2)));
        totalData.push(Number(totalIncrement.toFixed(2)));
      }
    }

    const ctx = document.getElementById('trafficChart');
    if (!ctx) return;
    if (window.trafficChartInstance) {
      window.trafficChartInstance.destroy();
    }

    const displayLabels = labels;
    const displayData = totalData;

    window.trafficChartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels: displayLabels,
        datasets: [
          {
            label: '本时段流量',
            data: displayData,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointRadius: 1,
            pointHoverRadius: 3
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: 'rgba(0, 0, 0, 0.8)',
            padding: 12,
            displayColors: false,
            titleFont: { size: 14, weight: 'bold' },
            bodyFont: { size: 13 },
            callbacks: {
              title: function (context) {
                const currentTime = context[0].label;
                const index = context[0].dataIndex;
                if (index === 0) return currentTime + ' (起始点)';
                const prevTime = context[0].chart.data.labels[index - 1];
                return prevTime + ' → ' + currentTime;
              },
              label: function (context) {
                const value = parseFloat(context.parsed.y).toFixed(2);
                return context.dataIndex === 0 ? '本时段流量: 0 MB (起始点)' : '本时段流量: ' + value + ' MB';
              }
            }
          }
        },
        scales: {
          y: {
            beginAtZero: true,
            title: { display: true, text: '每5分钟消耗流量 (MB)', font: { size: 14, weight: 'bold' } },
            ticks: { stepSize: 1000, callback: (v) => v + ' MB', font: { size: 12 } },
            grid: { color: 'rgba(0, 0, 0, 0.05)' }
          },
          x: {
            title: { display: false, text: '时间', font: { size: 14, weight: 'bold' } },
            ticks: { font: { size: 11 }, maxRotation: 45, minRotation: 0, autoSkip: true },
            grid: { display: false }
          }
        }
      }
    });
  }

  // Expose functions globally (existing names)
  window.startAutoRefresh = startAutoRefresh;
  window.updateTrafficChart = updateTrafficChart;
  window.createTrafficChart = createTrafficChart;

  window.resetSnapshotToToday = function resetSnapshotToToday() {
    const dateInput = document.getElementById('snapshot-date');
    const today = TODAY;
    if (!dateInput) return;
    dateInput.value = today;
    currentSnapshotDate = today;
    updateTrafficChart(today);
    const backButton = document.getElementById('snapshot-back-today');
    if (backButton) backButton.style.display = 'none';
    const infoDiv = document.getElementById('snapshot-info');
    if (infoDiv) infoDiv.style.display = 'none';
    const tipText = document.getElementById('snapshot-tip');
    if (tipText) tipText.innerHTML = '💡 提示：显示当日从00:00开始的流量数据';
  };

  window.resetQueryToRecent = function resetQueryToRecent() {
    const dateInput = document.getElementById('query-date');
    const today = TODAY;
    if (!dateInput) return;
    dateInput.value = today;
    currentQueryDate = '';
    updateStatsTable('');
    const backButton = document.getElementById('query-back-recent');
    if (backButton) backButton.style.display = 'none';
    const queryForm = document.getElementById('query-date-form');
    if (queryForm) {
      const titleElement = queryForm.closest('.chart-section').querySelector('h2');
      if (titleElement) titleElement.textContent = '📊 最近32天流量统计';
    }
    const statsInfo = document.getElementById('stats-info');
    if (statsInfo) statsInfo.style.display = 'none';
  };

  window.handleSnapshotDateChange = function handleSnapshotDateChange() {
    const dateInput = document.getElementById('snapshot-date');
    const newDate = dateInput ? dateInput.value : '';
    if (newDate && newDate !== currentSnapshotDate) {
      currentSnapshotDate = newDate;
      updateTrafficChart(newDate);
      const isToday = newDate === TODAY;
      const backButton = document.getElementById('snapshot-back-today');
      if (backButton) backButton.style.display = isToday ? 'none' : 'inline-block';
      const infoDiv = document.getElementById('snapshot-info');
      if (infoDiv) {
        if (isToday) infoDiv.style.display = 'none';
        else {
          infoDiv.innerHTML = `<strong>📅 查询结果:</strong> 显示 ${newDate} 日流量数据`;
          infoDiv.style.display = 'block';
        }
      }
      const tipText = document.getElementById('snapshot-tip');
      if (tipText) tipText.innerHTML = '💡 提示：' + (isToday ? '显示当日从00:00开始的流量数据' : '显示当日全天流量数据');
    }
  };

  window.handleQueryDateChange = function handleQueryDateChange() {
    const dateInput = document.getElementById('query-date');
    const newDate = dateInput ? dateInput.value : '';
    if (newDate !== currentQueryDate) {
      currentQueryDate = newDate;
      updateStatsTable(newDate);
      const backButton = document.getElementById('query-back-recent');
      if (backButton) backButton.style.display = newDate ? 'inline-block' : 'none';
      const queryForm = document.getElementById('query-date-form');
      if (queryForm) {
        const statsSection = queryForm.closest('.chart-section');
        const titleElement = statsSection.querySelector('h2');
        if (titleElement) titleElement.textContent = newDate ? '📊 日期范围流量统计' : '📊 最近32天流量统计';
        const infoDiv = document.getElementById('stats-info');
        if (infoDiv) {
          if (newDate) {
            const startDate = new Date(newDate);
            startDate.setDate(startDate.getDate() - 7);
            const endDate = new Date(newDate);
            endDate.setDate(endDate.getDate() + 7);
            infoDiv.innerHTML = `<strong>📅 查询结果:</strong> 显示 ${newDate} 前后7天的流量数据（${startDate.toISOString().split('T')[0]} 至 ${endDate.toISOString().split('T')[0]}）`;
            infoDiv.style.display = 'block';
          } else {
            infoDiv.style.display = 'none';
          }
        }
      }
    }
  };

  async function updateStatsTable(centerDate) {
    try {
      const url = centerDate ? `api.php?action=stats&date=${encodeURIComponent(centerDate)}` : 'api.php?action=stats';
      const response = await fetch(url);
      const result = await response.json();
      if (result.success) {
        renderStatsTable(result.data, centerDate);
      } else {
        console.error('统计表格更新失败:', result.message);
      }
    } catch (error) {
      console.error('统计表格请求失败:', error);
    }
  }

  function renderStatsTable(stats, centerDate) {
    const queryForm = document.getElementById('query-date-form');
    if (!queryForm) return;
    const statsSection = queryForm.closest('.chart-section');
    const tbody = statsSection ? statsSection.querySelector('tbody') : null;
    if (!tbody) return;

    if (!stats || stats.length === 0) {
      tbody.innerHTML = '<tr><td colspan="5" class="table-empty">暂无数据</td></tr>';
      return;
    }

    const statsByDate = {};
    stats.forEach(s => { statsByDate[s.usage_date] = s; });

    let html = '';
    stats.forEach(stat => {
      const currentDate = stat.usage_date;
      const previousDate = new Date(currentDate);
      previousDate.setDate(previousDate.getDate() - 1);
      const previousDateStr = previousDate.toISOString().split('T')[0];

      let calculatedDailyUsage;
      if (statsByDate[previousDateStr]) {
        const previousDayUsed = statsByDate[previousDateStr].used_bandwidth;
        calculatedDailyUsage = stat.used_bandwidth - previousDayUsed;
        if (calculatedDailyUsage < 0) calculatedDailyUsage = stat.used_bandwidth;
      } else {
        calculatedDailyUsage = stat.daily_usage;
      }

      const totalBandwidth = parseFloat(stat.total_bandwidth).toFixed(2);
      const usedBandwidth = parseFloat(stat.used_bandwidth).toFixed(2);
      const remainingBandwidth = parseFloat(stat.remaining_bandwidth).toFixed(2);
      const dailyUsage = parseFloat(calculatedDailyUsage).toFixed(2);

      const isHighlighted = !!centerDate && stat.usage_date === centerDate;
      const rowClass = isHighlighted ? 'row-highlight' : '';

      html += `
        <tr class="${rowClass}">
          <td>${stat.usage_date}</td>
          <td>${dailyUsage} GB</td>
          <td>${usedBandwidth} GB</td>
          <td>${totalBandwidth} GB</td>
          <td>${remainingBandwidth} GB</td>
        </tr>
      `;
    });

    tbody.innerHTML = html;
  }

  document.addEventListener('DOMContentLoaded', function () {
    const snapshotDateForm = document.getElementById('snapshot-date-form');
    if (snapshotDateForm) {
      snapshotDateForm.addEventListener('submit', function (e) {
        e.preventDefault();
        window.handleSnapshotDateChange();
      });
    }

    const queryDateForm = document.getElementById('query-date-form');
    if (queryDateForm) {
      queryDateForm.addEventListener('submit', function (e) {
        e.preventDefault();
        window.handleQueryDateChange();
      });
    }

    startAutoRefresh();

    if (Array.isArray(data.todaySnapshots) && data.todaySnapshots.length > 0) {
      createTrafficChart(data.todaySnapshots, !!data.isViewingToday);
    }
  });
})();
