<details id="backup">
    <summary>Backup</summary>
    <div class="card">
        <?php if (($_GET['runbackup'] ?? "") == 1): ?>
            <div class="alert alert-success"><?= htmlspecialchars("Backup will start in a minute.") ?></div>
        <?php endif; ?>

        <div style="display:flex; align-items:center; justify-content:space-between;">
            <h2 style="margin:0;">My backups</h2>
            <form method="post" action="">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="run_backup">

                <button class="btn btn-secondary" id="toggleSeedBtn">
                    Backup now
                </button>
            </form>
        </div>

        <?php
        $backup_nodes = $GLOBALS["DB"]->query("SELECT * FROM backup_nodes ORDER BY idx", []);
        ?>

        <?php foreach ($backup_nodes as $node): ?>

            <details class="backup-node" data-idx="<?= htmlspecialchars($node['idx']) ?>">
                <summary>
                    <?= htmlspecialchars($node['description'] ?: $node['url']) ?>
                    <?php if ($node['last_result'] === null): ?>
                        <span style="font-size:0.8rem; color:#8b949e; margin-left:0.5rem">— never backed up</span>
                    <?php elseif ($node['last_result'] === ''): ?>
                        <span class="status-dot green" style="margin-left:0.5rem"></span>
                        <span style="font-size:0.8rem; color:#3fb950"><?= htmlspecialchars($node['last_backup']) ?> UTC</span>
                    <?php else: ?>
                        <span class="status-dot yellow" style="margin-left:0.5rem"></span>
                        <span style="font-size:0.8rem; color:#d29922">Failed: <?= htmlspecialchars($node['last_result']) ?></span>
                    <?php endif; ?>
                </summary>

                <div class="card">
                    <!-- Stav záloh — načte se lazy po otevření <details> -->
                    <div class="backup-status" data-idx="<?= htmlspecialchars($node['idx']) ?>">
                        <p class="alert-muted" style="font-size:0.85rem">Loading backup status...</p>
                    </div>

                    <hr class="separator">

                    <form id="edit-node-<?= htmlspecialchars($node['idx']) ?>" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="edit_backup_node">
                        <input type="hidden" name="idx" value="<?= htmlspecialchars($node['idx']) ?>">

                        <label>Description:</label>
                        <input name="description" value="<?= htmlspecialchars($node['description'] ?? '') ?>" placeholder="e.g. Honza's node">

                        <label>URL:</label>
                        <input name="url" value="<?= htmlspecialchars($node['url']) ?>" placeholder="http://xyz.onion">

                        <label>Ed25519 public key (base64url):</label>
                        <input name="ed_pub" value="<?= htmlspecialchars($node['ed_pub']) ?>" placeholder="Server public key (43-character base64url, optional)">

                        <label>Password:</label>
                        <input name="pswd" value="<?= htmlspecialchars($node['pswd']) ?>" placeholder="Backup password issued by the server">

                        <?php if ($node['last_result'] !== null): ?>
                            <label>Last backup:</label>
                            <?php if ($node['last_result'] === ''): ?>
                                <code style="color:#3fb950">✓ <?= htmlspecialchars($node['last_backup']) ?> UTC — success</code>
                            <?php else: ?>
                                <code style="color:#d29922">✗ <?= htmlspecialchars($node['last_backup']) ?> UTC<br><?= htmlspecialchars($node['last_result']) ?></code>
                            <?php endif; ?>
                        <?php endif; ?>

                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:0.75rem">
                            <button class="btn btn-danger"
                                    form="delete-node-<?= htmlspecialchars($node['idx']) ?>"
                                    onclick="return confirm('Remove this backup node?')">Remove</button>
                            <button class="btn btn-secondary"
                                    form="edit-node-<?= htmlspecialchars($node['idx']) ?>">Save</button>
                        </div>
                    </form>
                    <form id="delete-node-<?= htmlspecialchars($node['idx']) ?>" method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_backup_node">
                        <input type="hidden" name="delete_backup_node" value="<?= htmlspecialchars($node['idx']) ?>">
                    </form>
                </div>

            </details>

        <?php endforeach; ?>

        <?php if (!$backup_nodes): ?>
            <p class="alert-muted" style="font-size:0.85rem; padding:0.5rem 0">No backup nodes configured yet.</p>
        <?php endif; ?>

        <details>
            <summary>➕ Add backup node</summary>
            <div class="card">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_backup_node">

                    <label>Description:</label>
                    <input name="description" placeholder="e.g. Honza's node">

                    <label>URL:</label>
                    <input name="url" placeholder="http://xyz.onion" required>

                    <label>Ed25519 public key (base64url):</label>
                    <input name="ed_pub" placeholder="Server public key (43-character base64url, optional)">  <!-- 43-character base64url key (leave empty for first connection -->

                    <label>Password:</label>
                    <input name="pswd" placeholder="Backup password issued by the server">

                    <div style="display:flex; justify-content:flex-end;">
                        <button class="btn">Add node</button>
                    </div>
                </form>
            </div>
        </details>

    </div>
    <div class="card">
        <h2>Friend's backups</h2>
        <label>To backup on my node, you need to authenticate with this password:</label>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit_backuppswd">
            <input class="editable" name="backuppswd" placeholder="backup password" value="<?= $configdb['backup_pswd'] ?? "" ?>">
            <div style="display:flex; justify-content:flex-end;">
                <button class="btn btn-secondary" name="saveBackupPswd">Save</button>
            </div>
        </form>

        <?php
        $friend_files = is_dir($backup_dir_fb) ? glob($backup_dir_fb . '/*.bin') : [];

// Seskup soubory podle pub_key nodu
        $friend_nodes = [];
        if ($friend_files) {
            foreach ($friend_files as $filepath) {
                $basename = basename($filepath, '.bin');         // "abc123_1714000000"
                $sep = strrpos($basename, '_');             // pozice posledního podtržítka
                if ($sep === false)
                    continue;

                $node_pub = substr($basename, 0, $sep);          // "abc123..."
                $dt = (int) substr($basename, $sep + 1);  // 1714000000

                $friend_nodes[$node_pub][] = [
                    'backup_dt' => $dt,
                    'date_utc' => gmdate('Y-m-d H:i:s', $dt),
                    'size' => filesize($filepath),
                ];
            }

            // Seřaď zálohy každého nodu od nejnovější
            foreach ($friend_nodes as &$entries) {
                usort($entries, fn($a, $b) => $b['backup_dt'] - $a['backup_dt']);
            }
            unset($entries);
        }
        ?>

        <?php if (!$friend_nodes): ?>
            <p class="alert-muted" style="font-size:0.85rem">No backups from other nodes stored on this server yet.</p>
        <?php else: ?>
    <?php foreach ($friend_nodes as $node_pub => $entries): ?>
                <details>
                    <summary>
                        <span class="mono" style="font-size:0.8rem"><?= htmlspecialchars(substr($node_pub, 0, 200)) ?></span>
                        <span style="font-size:0.8rem; color:#8b949e; margin-left:0.5rem">
                            — <?= count($entries) ?> <?= count($entries) === 1 ? 'backup' : 'backups' ?>,
                            latest <?= htmlspecialchars($entries[0]['date_utc']) ?> UTC
                        </span>
                    </summary>
                    <div class="card">
                        <label>Node ID (base64url):</label>
                        <code><?= htmlspecialchars($node_pub) ?></code>

                        <label>Stored backups (<?= count($entries) ?>):</label>
                        <code style="line-height:1.9">
                            <?php foreach ($entries as $e): ?>
                                <?= htmlspecialchars($e['date_utc']) ?> UTC
                                &nbsp;
                                <?= number_format($e['size'] / 1024, 1) ?> KB<br>
        <?php endforeach; ?>
                        </code>

                        <label>Total size:</label>
                        <code><?= number_format(array_sum(array_column($entries, 'size')) / 1024, 1) ?> KB</code>
                    </div>
                </details>
            <?php endforeach; ?>
<?php endif; ?>

    </div><!-- /card Friend's backups -->

</details>

<script>
    document.querySelectorAll('details.backup-node').forEach(function (el) {
        el.addEventListener('toggle', function () {
            if (!el.open)
                return;           // zavírání ignoruj
            if (el.dataset.loaded)
                return;  // už načteno, nenačítej znovu
            el.dataset.loaded = true;

            var idx = el.dataset.idx;
            var statusEl = el.querySelector('.backup-status[data-idx="' + idx + '"]');
            if (!statusEl)
                return;

            statusEl.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">⏳ Connecting to backup node...</p>';

            fetch('?backup_status=' + encodeURIComponent(idx))
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        if (data.error) {
                            statusEl.innerHTML =
                                    '<p class="alert-muted" style="font-size:0.85rem">⚠ ' +
                                    escHtml(data.message) + '</p>';
                            return;
                        }
                        if (data.backups.length === 0) {
                            statusEl.innerHTML =
                                    '<p class="alert-muted" style="font-size:0.85rem">No backups stored on this node yet.</p>';
                            return;
                        }
                        var html = '<label>Stored backups (' + data.backups.length + '):</label>' +
                                '<code style="line-height:1.9">';
                        data.backups.forEach(function (b) {
                            var sizeKb = (b.size / 1024).toFixed(1);
                            html += escHtml(b.date_utc) + ' UTC &nbsp; ' + sizeKb + ' KB<br>';
                        });
                        html += '</code>';
                        statusEl.innerHTML = html;
                    })
                    .catch(function () {
                        statusEl.innerHTML =
                                '<p class="alert-muted" style="font-size:0.85rem">⚠ Connection failed.</p>';
                    });
        });
    });

    function escHtml(str) {
        return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
</script>