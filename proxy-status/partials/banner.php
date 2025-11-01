<?php
// Banner rendering helpers (no UI/behavior change)
function renderInfoBanner(string $html, ?string $id = null): void {
    echo '<div class="info-banner"' . ($id ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '') . '>' . $html . '</div>';
}

function renderWarningBanner(string $html, ?string $id = null): void {
    echo '<div class="warning-banner"' . ($id ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '') . '>' . $html . '</div>';
}
