<?php
$restore_step = (int) ($_GET['restore_step'] ?? 1);
$restore_ctx  = $_SESSION['restore'] ?? [];
if ($restore_step > 1 && empty($restore_ctx)) {
    $restore_step = 1;
}
?>

<?php if ($error): ?>
    <div class="alert alert-error" style="white-space:pre-wrap;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php /* ══════════════════════════════════════════
        KROK 1 — Nová peněženka, nebo zadání seedu
   ══════════════════════════════════════════════ */
if ($restore_step === 1): ?>

<div class="card">
    <h2>Generate new seed</h2>
    <p class="alert-muted" style="margin-bottom:0.75rem; font-size:0.85rem">
        Create a new BIP39 mnemonic and derive a fresh node identity from it.
        Back up the phrase immediately — it is the only way to recover your node.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <button class="btn" name="gen">Generate seed</button>
    </form>
</div>

<div class="card">
    <h2>Restore from mnemonic</h2>
    <p class="alert-muted" style="margin-bottom:0.75rem; font-size:0.85rem">
        Enter your 12- or 24-word BIP39 recovery phrase separated by spaces.
        Your phrase is processed locally on this server only.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <label>Recovery phrase (BIP39)</label>
        <textarea name="mnemonic" rows="3"
                  style="font-family:monospace;"
                  placeholder="word1 word2 word3 … (12 or 24 words)"><?= htmlspecialchars($_POST['mnemonic'] ?? '') ?></textarea>
        <div style="display:flex; justify-content:flex-end;">
            <button class="btn btn-secondary" name="restore_seed">Restore seed →</button>
        </div>
    </form>
</div>

<?php /* ══════════════════════════════════════════
        KROK 2 — URL zálohovacích serverů
   ══════════════════════════════════════════════ */
elseif ($restore_step === 2): ?>

<div class="card">
    <h2>Step 2 of 3 — Backup servers</h2>
    <p class="alert-muted" style="margin-bottom:0.75rem; font-size:0.85rem">
        Seed saved. Enter the URL addresses of backup servers where your database backup may be stored.
        One URL per line. Leave empty to skip database restore.
    </p>
    <form method="post">
        <?= csrf_field() ?>
        <label>Backup server URLs (one per line)</label>
        <textarea name="backup_urls" rows="4"
                  style="font-family:monospace; font-size:0.82rem;"
                  placeholder="#defaultbackup#&#10;http://another.onion"><?= htmlspecialchars($_POST['backup_urls'] ?? '#defaultbackup#') ?></textarea>

        <div style="display:flex; gap:0.5rem; justify-content:space-between; margin-top:0.5rem;">
            <a href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) ?>"
               class="btn btn-secondary">← Start over</a>
            <div style="display:flex; gap:0.5rem;">
                <button class="btn btn-secondary" name="restore_skip_db">Skip →</button>
                <button class="btn" name="restore_find">Search backups →</button>
            </div>
        </div>
    </form>
</div>

<?php /* ══════════════════════════════════════════
        KROK 3 — Výběr zálohy
   ══════════════════════════════════════════════ */
elseif ($restore_step === 3):
    $backups     = $restore_ctx['backups']     ?? [];
    $failed_urls = $restore_ctx['failed_urls'] ?? [];
?>

<div class="card">
    <h2>Step 3 of 3 — Select backup</h2>

    <?php if ($failed_urls): ?>
        <div class="alert" style="background:#1a1200; border-color:#d29922; color:#d29922; font-size:0.82rem; margin-bottom:0.75rem;">
            ⚠ Could not reach: <?= htmlspecialchars(implode(', ', array_map(
                fn($u) => BACKUP_PLACEHOLDERS[$u] ?? $u, $failed_urls
            ))) ?>
        </div>
    <?php endif; ?>

    <?php if (!$backups): ?>
        <p class="alert-muted" style="font-size:0.85rem; margin-bottom:1rem;">
            No backups found on any of the specified servers.
        </p>
        <a href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?restore_step=2') ?>"
           class="btn btn-secondary">← Back</a>

    <?php else: ?>
        <p class="alert-muted" style="font-size:0.85rem; margin-bottom:0.75rem;">
            Choose which backup to restore. The current database will be overwritten.
        </p>

        <form method="post">
            <?= csrf_field() ?>

            <div style="display:flex; flex-direction:column; gap:0.4rem; margin-bottom:0.75rem;">
                <?php foreach ($backups as $i => $b): ?>
                    <label style="display:flex; align-items:center; gap:0.75rem; cursor:pointer;
                                  background:#0d1117; border:1px solid #30363d; border-radius:6px;
                                  padding:0.65rem 1rem; font-size:0.83rem;"
                           data-url="<?= htmlspecialchars($b['_server_url']) ?>">
                        <input type="radio"
                               name="backup_dt"
                               value="<?= (int)$b['backup_dt'] ?>"
                               data-url="<?= htmlspecialchars($b['_server_url']) ?>"
                               <?= $i === 0 ? 'checked' : '' ?>
                               style="width:auto; margin:0; flex-shrink:0;">
                        <span style="flex:1; font-family:monospace; color:#c9d1d9; white-space:nowrap;">
                            <?= htmlspecialchars($b['date_utc']) ?> UTC
                        </span>
                        <span style="color:#8b949e; white-space:nowrap; font-size:0.78rem;">
                            <?= number_format($b['size'] / 1024, 1) ?> KB
                        </span>
                        <span style="color:#8b949e; white-space:nowrap; font-size:0.75rem;
                                     font-family:monospace; overflow:hidden; text-overflow:ellipsis; max-width:160px;">
                            <?= htmlspecialchars(BACKUP_PLACEHOLDERS[$b['_server_url']] ?? $b['_server_url']) ?>
                        </span>
                        <?php if ($i === 0): ?>
                            <span style="color:#3fb950; font-size:0.72rem; white-space:nowrap; flex-shrink:0;">latest</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>

            <input type="hidden" id="restore-server-url" name="backup_server_url"
                   value="<?= htmlspecialchars($backups[0]['_server_url'] ?? '') ?>">

            <script>
            (function () {
                var radios = document.querySelectorAll('input[name="backup_dt"]');
                var urlField = document.getElementById('restore-server-url');
                radios.forEach(function (r) {
                    r.addEventListener('change', function () {
                        urlField.value = this.dataset.url || '';
                    });
                });
                // Inicializuj z prvního checked
                var first = document.querySelector('input[name="backup_dt"]:checked');
                if (first) urlField.value = first.dataset.url || '';
            })();
            </script>

            <div class="alert" style="background:#1a1200; border-color:#d29922; color:#d29922;
                                      font-size:0.82rem; margin-bottom:0.75rem;">
                ⚠ This will overwrite the current database. This cannot be undone.
            </div>

            <div style="display:flex; gap:0.5rem; justify-content:space-between;">
                <a href="<?= htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . '?restore_step=2') ?>"
                   class="btn btn-secondary">← Back</a>
                <button class="btn btn-danger" name="restore_execute">⛔ Restore now</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php endif; ?>