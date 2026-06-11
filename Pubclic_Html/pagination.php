<?php
// pagination.php - Reusable, accessible pagination component
// Usage: echo render_pagination($current_page, $total_pages, $base_url);

if (!function_exists('e')) {
    // Fallback if functions.php not included
    function e($v) { return htmlspecialchars($v ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
}

/**
* Render accessible pagination markup
* @param int $current_page Current page number (1-based)
* @param int $total_pages Total number of pages
* @param string $base_url Base URL with query params (without page=)
* @param array $options Optional: ['prev_text'=>'← Prev', 'next_text'=>'Next →', 'class'=>'pagination']
* @return string HTML pagination markup
*/
function render_pagination($current_page, $total_pages, $base_url, $options = []) {
    if ($total_pages <= 1) return '';
    
    $opts = array_merge([
        'prev_text' => '← Prev',
        'next_text' => 'Next →',
        'class' => 'pagination',
        'ellipsis' => '...'
    ], $options);
    
    $html = '<nav aria-label="Product pagination" class="' . e($opts['class']) . '">';
    $html .= '<ul class="pagination-list">';
    
    // Previous button
    if ($current_page > 1) {
        $prev_url = $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . 'page=' . ($current_page - 1);
        $html .= '<li><a href="' . e($prev_url) . '" class="page-btn" aria-label="Previous page">' . e($opts['prev_text']) . '</a></li>';
    } else {
        $html .= '<li><span class="page-btn" aria-disabled="true" tabindex="-1">' . e($opts['prev_text']) . '</span></li>';
    }
    
    // Page numbers with ellipsis logic
    $start = max(1, $current_page - 2);
    $end = min($total_pages, $current_page + 2);
    
    if ($start > 1) {
        $first_url = $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . 'page=1';
        $html .= '<li><a href="' . e($first_url) . '" class="page-btn">1</a></li>';
        if ($start > 2) $html .= '<li><span class="page-ellipsis" aria-hidden="true">' . e($opts['ellipsis']) . '</span></li>';
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $url = $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . 'page=' . $i;
        $active = $i === $current_page ? 'active' : '';
        $aria_current = $i === $current_page ? ' aria-current="page"' : '';
        $html .= '<li><a href="' . e($url) . '" class="page-btn ' . e($active) . '"' . $aria_current . '>' . $i . '</a></li>';
    }
    
    if ($end < $total_pages) {
        if ($end < $total_pages - 1) $html .= '<li><span class="page-ellipsis" aria-hidden="true">' . e($opts['ellipsis']) . '</span></li>';
        $last_url = $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . 'page=' . $total_pages;
        $html .= '<li><a href="' . e($last_url) . '" class="page-btn">' . $total_pages . '</a></li>';
    }
    
    // Next button
    if ($current_page < $total_pages) {
        $next_url = $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . 'page=' . ($current_page + 1);
        $html .= '<li><a href="' . e($next_url) . '" class="page-btn" aria-label="Next page">' . e($opts['next_text']) . '</a></li>';
    } else {
        $html .= '<li><span class="page-btn" aria-disabled="true" tabindex="-1">' . e($opts['next_text']) . '</span></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}
?>