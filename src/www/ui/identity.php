<?php
$mnemonic = file_exists($mnemonicFile) ? file_get_contents($mnemonicFile) : "";
$pub_key = getPublicKey();
$nodeId = b64e($pub_key);
//$nodeName = 'Testovací server';
$selectedinv = ($_GET['inv'] ?? "");
$selectedinv_idx = 0;
for ($i = 0; $i < count($invitations_t); $i++) {
    if ($selectedinv == $invitations_t[$i]['idx']) {
        $selectedinv_idx = $i;
        break;
    }
}
$clearnetaddr = trim($configdb['clearnet_addr'] ?? '');
$addr = ($clearnetaddr !== '') ? $clearnetaddr . ',' . $onion : $onion;
$connectionString = 'bitcoli:node/' . $nodeId . '@' . $addr . '?' . $invitations_t[$selectedinv_idx]['id'];
?>

<div class="card">
    <h2>BitcoLi Node Identity</h2>
    <?php
    if (time() - ((int) ($configdb["app_started_at"] ?? time())) < 180) {
        echo ('<div class="alert alert-success">Initializing your Tor hidden service…<br>' .
        'Your .onion address may be unreachable for the first few minutes while it propagates through the Tor network.</div>');
    }
    ?>
    <label>Node ID (base64url)</label>
    <code><?= htmlspecialchars($nodeId) ?></code>

    <label>Node name</label>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_nodename">
        <input class="editable" name="node_name" placeholder="name" value="<?= $configdb['node_name'] ?>">
        <div style="display:flex; justify-content:flex-end;">
            <button class="btn btn-secondary" name="saveNodeName">Save</button>
        </div>
    </form>
    <form method="get">
        <label>Invitation</label>
        <select name="inv" class="mono" onchange="this.form.submit()">
            <?php foreach ($invitations_t as $row): ?>
                <option value="<?= $row['idx'] ?>" <?= ($selectedinv == $row['idx']) ? 'selected' : '' ?>><?= htmlspecialchars($row['description'] . ' (' . $row['used'] . '/' . $row['cnt'] . ')') ?></option>
            <?php endforeach; ?>
        </select>
    </form>      

    <?php if ($onion): ?>
        <?php
        ob_start();
        QRcode::png(
                'node/' . $nodeId . '@' . $addr . '?' . $invitations_t[$selectedinv_idx]['id'],
                null,
                QR_ECLEVEL_L,
                5,
                2,
                false
        );
        $imageData = ob_get_clean();
        $base64 = base64_encode($imageData);
        ?>
        <div class="qr-wrap">
            <a href="<?= htmlspecialchars($connectionString) ?>">
                <img src="data:image/png;base64,<?= $base64 ?>" alt="Node QR">
            </a>
        </div>


        <script>
            function copyText(sourceId, btn) {
                const text = document.getElementById(sourceId).textContent;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(() => {
                        btn.textContent = 'Copied ✓';
                        setTimeout(() => btn.textContent = 'Copy', 2000);
                    });
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.opacity = '0';
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                    btn.textContent = 'Copied ✓';
                    setTimeout(() => btn.textContent = 'Copy', 2000);
                }
            }
        </script>


        <label>Connection string</label>
        <div style="display:flex; gap:0.5rem; align-items:flex-start;">
            <code id="connection-string" style="flex:1; margin-bottom:0"><?= htmlspecialchars($connectionString) ?></code>
            <button class="btn btn-secondary" onclick="copyText('connection-string', this)" style="margin-top:0">Copy</button>
        </div>

    <?php else: ?>
        <p class="alert-muted" style="font-size:0.85rem;margin-top:0.5rem">
            QR code will appear once the onion address is ready.
        </p>
    <?php endif; ?>

    <br>
    <h2>API</h2>
    <?php if ($onion != ''): ?>
        <label>Onion address</label>
        <code><?= htmlspecialchars($onion) ?></code>
    <?php else: ?>
        <span class="status-dot yellow"></span>
        <span class="alert-muted">Onion address not ready yet.</span>
    <?php endif; ?>

    <label>Clearnet address (optional)</label>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit_clearnet">
        <div style="display:flex; gap:0.5rem; align-items:flex-start;">
            <input class="editable" name="clearnet_addr" style="flex:1"
                   placeholder="http://x.x.x.x:<?= htmlspecialchars($cfg['API_PORT']) ?>"
                   value="<?= htmlspecialchars($configdb['clearnet_addr'] ?? '') ?>">
            <button class="btn btn-secondary" style="margin-top:0">Save</button>
        </div>
        <p class="alert-muted" style="font-size:0.78rem; margin-top:0.35rem;">
            If set, it is prepended to the .onion address in the connection string.
            Only http:// and https:// are allowed — if you omit the scheme, http:// is added automatically.
            Leave empty to advertise the Tor address only.
        </p>
    </form>

    <hr class="separator">

    <?php $in_maintenance = ($configdb['maintenance'] ?? '0') === '1'; ?>

    <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
        <div>
            <h2 style="margin-bottom:0.25rem;">Maintenance mode</h2>
            <?php if ($in_maintenance): ?>
                <p style="font-size:0.82rem; color:#d29922; margin:0;">
                    <span class="status-dot yellow"></span>
                    Active — entire API suspended. No invoices, payments or terms acceptance are processed.
                </p>
            <?php else: ?>
                <p style="font-size:0.82rem; color:#8b949e; margin:0;">
                    <span class="status-dot" style="background:#8b949e;"></span>
                    Inactive — node is operating normally.
                </p>
            <?php endif; ?>
        </div>
        <form method="post" style="margin:0; flex-shrink:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_maintenance">
            <?php if ($in_maintenance): ?>
                <button class="btn btn-secondary" style="margin:0;">✓ Disable maintenance</button>
            <?php else: ?>
                <button class="btn" style="margin:0; background:#d29922; color:#0d1117;"
                        onclick="return confirm('Enable maintenance mode? The node will stop accepting new payments.')">
                    🔧 Enable maintenance
                </button>
            <?php endif; ?>
        </form>
    </div>
    <p class="alert-muted" style="font-size:0.78rem; margin-top:0.5rem;">
        When maintenance mode is active, the entire API is suspended — invoice creation,
        payments, payment status checks and terms acceptance are all rejected.
        Enable it before migrating to a new server to ensure no activity occurs
        while you back up and restore the node.
    </p>

</div>
