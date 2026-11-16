<?php foreach ([
    'telegram' => ['label' => 'Telegram', 'form_id' => 'telegramMasterSwitchForm'],
    'whatsapp' => ['label' => 'WhatsApp', 'form_id' => 'whatsappToggleForm'],
] as $channel => $definition):
    $label = $definition['label'];
    $field = $channel . '_enabled';
    $allowed = $notificationView[$channel . '_allowed'];
    $available = $notificationView[$channel . '_available'];
    $message = $notificationView[$channel . '_message'];
?>
    <li>
        <form id="<?= h($definition['form_id']) ?>" method="post" data-instant-toggle>
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= h($channel . '_toggle_notifications') ?>">
            <input type="hidden" name="return_page" value="<?= h($page) ?>">
            <input type="hidden" name="<?= h($field) ?>" value="0">
            <div class="settings-row channel-master-switch<?= $allowed ? '' : ' is-access-locked' ?>"<?= $allowed ? '' : ' aria-disabled="true"' ?>>
                <span class="label"><span><?= h($label) ?></span><?php if ($message !== ''): ?><small><?= h($message) ?></small><?php endif; ?></span>
                <label class="control">
                    <input type="checkbox" role="switch" name="<?= h($field) ?>" value="1" <?= $notificationView[$field] ? 'checked' : '' ?> <?= $available ? '' : 'disabled' ?> data-instant-toggle-input aria-label="Aktifkan pengiriman notifikasi <?= h($label) ?>">
                    <i class="toggle-switch" aria-hidden="true"></i>
                </label>
            </div>
            <noscript><?php if ($available): ?><button class="button primary" type="submit">Simpan</button><?php endif; ?></noscript>
        </form>
    </li>
<?php endforeach; ?>
