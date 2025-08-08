// 搜索功能
function performSearch() {
    const searchTerm = document.getElementById('search-input').value.trim();
    const currentUrl = new URL(window.location);
    const searchParams = currentUrl.searchParams;
    
    if (searchTerm) {
        searchParams.set('search', searchTerm);
    } else {
        searchParams.delete('search');
    }
    
    // 重置到第一页
    searchParams.delete('page');
    
    window.location.href = currentUrl.toString();
}

// 清除搜索和筛选
function clearSearch() {
    const currentUrl = new URL(window.location);
    const searchParams = currentUrl.searchParams;
    
    searchParams.delete('search');
    searchParams.delete('status');
    searchParams.delete('page');
    
    window.location.href = currentUrl.toString();
}

// 状态筛选功能
function filterByStatus(status) {
    const currentUrl = new URL(window.location);
    const searchParams = currentUrl.searchParams;
    
    if (status) {
        searchParams.set('status', status);
    } else {
        searchParams.delete('status');
    }
    
    // 重置到第一页
    searchParams.delete('page');
    
    window.location.href = currentUrl.toString();
}

// 监听搜索框的回车键
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                performSearch();
            }
        });
        
        // 自动聚焦搜索框（如果有搜索词）
        // 注意：这部分需要在index.php中处理PHP条件判断
    }
});
