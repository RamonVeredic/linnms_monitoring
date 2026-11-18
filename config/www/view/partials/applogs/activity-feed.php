<?php
$feed = $feed ?? [];
?>
<?php if ($feed === []): ?>
    <div class="public-log-empty"><div class="empty-state"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Belum ada aktivitas terbaru</span></div></div>
<?php else: foreach ($feed as $event):
    $entryTime = format_feed_time($event['created_at'] ?? null);
    $level = strtolower(trim((string)($event['level'] ?? 'info')));
    $message = rtrim(trim((string)($event['message'] ?? '')), " .\t\n\r\0\x0B");
    $username = trim((string)($event['display_actor'] ?? $event['username'] ?? ''));
    $showUsername = $username !== '' && $username !== 'System';
    $action = trim((string)($event['action_name'] ?? ''));
    if ($showUsername && in_array($action, ['add_device', 'add_olt', 'add_mikrotik', 'add_switch'], true)
        && preg_match('/^Perangkat (.+) berhasil ditambahkan\. IP (.+)$/u', $message, $parts)) {
        $message = 'IP ' . $parts[2] . ', ' . $username . ' berhasil menambahkan perangkat ' . $parts[1];
    }
    if ($action === 'login' && $level === 'warning'
        && preg_match('/^Percobaan login gagal(?:: kredensial tidak valid)?(?:\. IP (.+))?$/u', $message, $parts)) {
        $level = 'info';
        $reason = str_contains($message, 'kredensial tidak valid') ? ': username atau password salah' : '';
        $message = (isset($parts[1]) ? 'IP ' . $parts[1] . ', ' : '')
            . ($showUsername ? $username . ' ' : '') . 'percobaan login gagal' . $reason;
    }
    $logText = $showUsername && !str_contains(mb_strtolower($message), mb_strtolower($username))
        ? $username . ': ' . $message : $message;
    $badge = match ($level) {
        'warning' => ['label' => 'Peringatan', 'class' => 'warning'],
        'error' => ['label' => 'Error', 'class' => 'danger'],
        'critical' => ['label' => 'Kritikal', 'class' => 'danger'],
        default => null,
    };
?>
        <div class="public-log-entry" data-log-row data-log-text="<?= h(strtolower(($badge['label'] ?? '') . ' ' . $logText . ' ' . $entryTime)) ?>">
            <div class="public-log-message" data-log-expandable>
                <?php if ($badge !== null): ?><span class="app-log-badge <?= h($badge['class']) ?>"><?= h($badge['label']) ?></span><?php endif; ?>
                <span class="public-log-text" data-log-expand-text><?= h($logText) ?></span>
                <button class="public-log-toggle" type="button" data-log-expand-toggle aria-label="Tampilkan log selengkapnya" title="Tampilkan selengkapnya" aria-expanded="false" hidden><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
            </div>
            <time class="public-log-time"><?= h($entryTime) ?></time>
        </div>
<?php endforeach; endif; ?>
