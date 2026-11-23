<div class="settings-page" data-profile-page>
    <?php require project_path('view', 'partials', 'settings-profile.php'); ?>
    <?php $canEditSyncSettings = $page === 'settings-admin' && is_admin(); ?>
    <main class="panel settings-layout" id="sync" aria-label="Pengaturan aplikasi">
        <header><h2>Konfigurasi</h2><?php if (!$canEditSyncSettings): ?><p>Pengaturan sinkronisasi hanya dapat diubah oleh admin.</p><?php endif; ?></header>

        <div class="settings-columns" data-settings-columns>
            <div class="settings-column" data-settings-column>
                <section>
                    <span class="settings-anchor-alias" id="cache" aria-hidden="true"></span>
                    <span class="settings-anchor-alias" id="onu-missing" aria-hidden="true"></span>
                    <header class="tittle-subtitle"><h3>Sinkronisasi</h3></header>
                    <div class="settings-body">
                        <ul class="settings-list">
                        <?php foreach ($settingsSyncTimingFields as $field): ?>
                            <li>
                            <?php if ($canEditSyncSettings): ?>
                            <form method="post" class="settings-row" data-setting-edit-form>
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="action" value="save_numeric_setting">
                                <input type="hidden" name="setting_key" value="<?= h($field['key']) ?>">
                            <?php else: ?>
                            <div class="settings-row">
                            <?php endif; ?>
                                <div class="label">
                                    <div class="label-title">
                                        <?php if ($canEditSyncSettings): ?>
                                            <label for="<?= h($field['id']) ?>"><?= h($field['label']) ?></label>
                                        <?php else: ?>
                                            <span><?= h($field['label']) ?></span>
                                        <?php endif; ?>
                                        <button class="icon-button settings-help-button" type="button" data-setting-help="<?= h($field['help']) ?>" aria-label="Bantuan <?= h($field['label']) ?>" aria-controls="settingsHelpTooltip" aria-expanded="false"><i class="bi bi-question-circle" aria-hidden="true"></i></button>
                                        <?php if ($canEditSyncSettings): ?>
                                        <button class="icon-button" type="button" data-setting-edit data-target="<?= h($field['id']) ?>" aria-controls="<?= h($field['id']) ?>" aria-pressed="false" data-setting-label="<?= h($field['label']) ?>" aria-label="Edit <?= h($field['label']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                        <button class="icon-button" type="button" data-setting-cancel data-target="<?= h($field['id']) ?>" hidden aria-label="Batal mengedit <?= h($field['label']) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="control">
                                    <?php if ($canEditSyncSettings): ?>
                                    <span class="settings-number-shell">
                                        <input id="<?= h($field['id']) ?>" type="number" name="setting_value" min="<?= (int)$field['min'] ?>" max="<?= (int)$field['max'] ?>" step="<?= (int)$field['step'] ?>" required value="<?= (int)$field['value'] ?>" inputmode="numeric" disabled data-setting-edit-control>
                                        <span><?= h($field['unit']) ?></span>
                                    </span>
                                    <?php else: ?>
                                        <span><?= (int)$field['value'] ?> <?= h($field['unit']) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php if ($canEditSyncSettings): ?>
                            </form>
                            <?php else: ?>
                            </div>
                            <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                </section>

                <?php if ($canEditSyncSettings): ?>
                <section>
                    <header class="tittle-subtitle"><h3>Notifikasi</h3></header>
                    <div class="settings-body">
                        <ul class="settings-list">
                            <?php foreach ([
                                ['label' => 'Telegram', 'allowed' => $telegramFeatureAllowed],
                                ['label' => 'WhatsApp', 'allowed' => $whatsappFeatureAllowed],
                            ] as $channel): ?>
                                <li>
                                    <div class="settings-row<?= !$channel['allowed'] ? ' is-access-locked' : '' ?>"><span class="label"><?= h($channel['label']) ?></span><span class="control"><input type="checkbox" role="switch" <?= $channel['allowed'] ? 'checked' : '' ?> disabled aria-label="<?= h($channel['label'] . ($channel['allowed'] ? ' aktif' : ' tidak diizinkan')) ?>"><?php if ($channel['allowed']): ?><span class="toggle-switch" aria-hidden="true"></span><?php else: ?><span class="toggle-disabled-lock" aria-hidden="true"><i class="bi bi-lock-fill"></i></span><?php endif; ?></span></div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </section>
                <?php endif; ?>
            </div>
            <?php if ($canEditSyncSettings): ?>
            <div class="settings-column" data-settings-column>
                <section>
                    <header class="tittle-subtitle"><h3>Device</h3></header>
                    <form method="post" data-driver-support-form>
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="save_device_driver_support">
                        <div class="settings-body">
                            <ul class="settings-list">
                                <?php foreach (['olt', 'router', 'switch'] as $driverGroup): ?>
                                    <?php foreach ($deviceDriverCatalog as $driverId => $definition): ?>
                                        <?php if ((string)($definition['group'] ?? '') !== $driverGroup || (string)$driverId === 'external_olt_api') continue; ?>
                                        <?php $driverEnabled = $driverGroup === 'olt' ? olt_driver_support_enabled($oltDriverSupportFlags, (string)$driverId) : snmp_driver_support_enabled($snmpDriverSupportFlags, (string)$driverId); ?>
                                        <li>
                                            <label class="settings-row">
                                                <span class="label"><?= h((string)($definition['label'] ?? $driverId)) ?></span>
                                                <span class="control"><input type="checkbox" role="switch" name="driver_support[<?= h((string)$driverId) ?>]" value="1" <?= $driverEnabled ? 'checked' : '' ?>><span class="toggle-switch" aria-hidden="true"></span></span>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if ($driverGroup === 'olt'): ?>
                                        <?php foreach ($apiOltTypeOptions as $oltType => $oltTypeLabel): ?>
                                            <li>
                                                <label class="settings-row"><span class="label"><?= h((string)$oltTypeLabel) ?></span><span class="control"><input type="checkbox" role="switch" name="api_olt_type_support[<?= h((string)$oltType) ?>]" value="1" <?= api_olt_type_support_enabled($apiOltTypeSupportFlags, (string)$oltType) ? 'checked' : '' ?>><span class="toggle-switch" aria-hidden="true"></span></span></label>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </form>
                </section>
                <section id="user-limits">
                    <header class="tittle-subtitle"><h3>Users Device Limit</h3></header>
                    <div class="settings-body">
                        <?php if ($managedDeviceUsers === []): ?>
                            <p class="empty">Pengguna belum tersedia</p>
                        <?php else: ?>
                            <ul class="settings-list">
                                <?php foreach ($managedDeviceUsers as $managedUser): ?>
                                    <?php $managedUserId = (int)$managedUser['id']; ?>
                                    <li>
                                        <div class="settings-row">
                                            <span class="label"><?= h((string)$managedUser['username']) ?><small>OLT <?= (int)$managedUser['olt_limit'] ?> · Router <?= (int)$managedUser['mikrotik_limit'] ?> · Switch <?= (int)$managedUser['switch_limit'] ?></small></span>
                                            <span class="control"><button class="button secondary" type="button" data-settings-modal-open="userLimitEditPanel" data-limit-edit-open data-limit-user-id="<?= $managedUserId ?>" data-limit-name="<?= h((string)$managedUser['username']) ?>" data-limit-olt="<?= (int)$managedUser['olt_limit'] ?>" data-limit-mikrotik="<?= (int)$managedUser['mikrotik_limit'] ?>" data-limit-switch="<?= (int)$managedUser['switch_limit'] ?>" aria-expanded="false" aria-controls="userLimitEditPanel" aria-haspopup="dialog"><i class="bi bi-pencil-square" aria-hidden="true"></i> Edit</button></span>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <?php if ($canEditSyncSettings && $managedDeviceUsers !== []): ?>
        <div class="modal settings-edit-modal" id="userLimitEditPanel" data-settings-modal hidden>
            <div class="modal-card settings-edit-dialog" role="dialog" aria-modal="true" aria-labelledby="userLimitEditTitle" tabindex="-1">
                <div class="panel-heading tittle-subtitle settings-edit-heading modal-header">
                    <h2 id="userLimitEditTitle" data-limit-edit-title>Edit Limit Perangkat</h2>
                    <button class="icon-button modal-close-button" type="button" data-settings-modal-close aria-label="Tutup edit limit"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <form method="post" class="stack-form">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="save_user_device_limits">
                    <input type="hidden" name="user_id" value="">
                    <div class="form-grid">
                        <?php foreach ([
                            ['key' => 'olt_limit', 'label' => 'OLT'],
                            ['key' => 'mikrotik_limit', 'label' => 'MikroTik'],
                            ['key' => 'switch_limit', 'label' => 'Switch'],
                        ] as $field): ?>
                            <?php $inputId = 'userLimit_' . $field['key']; ?>
                            <div class="field">
                                <label for="<?= h($inputId) ?>"><?= h($field['label']) ?> (0 = tanpa batas akun)</label>
                                <input id="<?= h($inputId) ?>" type="number" name="<?= h($field['key']) ?>" min="0" step="1" required inputmode="numeric">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-actions"><button class="button primary" type="submit"><i class="bi bi-floppy" aria-hidden="true"></i> Simpan</button></div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    <?php require project_path('view', 'partials', 'settings-service-contact.php'); ?>
</div>
