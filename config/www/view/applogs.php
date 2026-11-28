<?php
$appLogTotal = max(0, (int)($appLogTotal ?? 0));
$appLogPage = max(1, (int)($appLogPage ?? 1));
$appLogPages = max(1, (int)($appLogPages ?? 1));

$appLogUrl = static function (int $targetPage): string {
    return public_page_url('applogs', ['log_page' => max(1, $targetPage)]);
};
?>
<section class="table-panel">
    <div class="table-toolbar table-filter-toolbar">
        <div class="left">
            <div class="table-search">
                <span><i class="bi bi-search" aria-hidden="true"></i></span>
                <input data-log-search="appLogEntries" aria-label="Cari log aplikasi" placeholder="Cari...">
            </div>
        </div>
    </div>
    <div class="list-scroll-viewport" role="region" aria-label="App Log" tabindex="0">
        <div class="public-log-list" id="appLogEntries">
            <?php require project_path('view', 'partials', 'applogs', 'app-log-entries.php'); ?>
        </div>
    </div>
    <?php render_numbered_pagination([
        'page' => $appLogPage,
        'pages' => $appLogPages,
        'url' => $appLogUrl,
        'label' => 'Halaman log aplikasi',
        'class' => 'app-log-pagination',
        'total' => $appLogTotal,
    ]); ?>
</section>
