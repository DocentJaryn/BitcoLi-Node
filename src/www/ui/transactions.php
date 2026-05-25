<details id="transactions">
    <summary>Transactions</summary>
    <div class="card">

        <!-- Search -->
        <div style="display:flex; gap:0.5rem; margin-bottom:1rem; align-items:center;">
            <input id="txns-search"
                   placeholder="Search by memo, payment hash or invoice…"
                   style="margin-bottom:0; flex:1;"
                   autocomplete="off">
            <button class="btn btn-secondary" onclick="txnsLoad(1)" style="margin-top:0; white-space:nowrap;">
                Search
            </button>
        </div>

        <!-- Results -->
        <div id="txns-list">
            <p class="alert-muted" style="font-size:0.85rem">Loading…</p>
        </div>

        <!-- Pagination -->
        <div id="txns-pagination" style="display:flex; gap:0.5rem; align-items:center; margin-top:0.75rem; font-size:0.85rem; color:#8b949e;">
        </div>

    </div>
</details>

<!-- Transaction detail overlay -->
<div id="txn-detail-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:100; overflow-y:auto; padding:2rem 1rem;">
    <div style="max-width:600px; margin:0 auto;">
        <div class="card" style="margin-bottom:0;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <h2 style="margin:0;">Transaction detail</h2>
                <button class="btn btn-secondary" onclick="txnDetailClose()" style="margin-top:0;">✕ Close</button>
            </div>
            <div id="txn-detail-body">
                <p class="alert-muted" style="font-size:0.85rem">Loading…</p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {

var txnsCurrentPage = 1;

// ── Helpers ───────────────────────────────────────────────────────────────────

function esc(str) {
    return String(str ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function satFmt(msat) {
    return Math.round((msat || 0) / 1000).toLocaleString() + ' sat';
}

// Returns { badge, clr, prefix, opacity, lineThrough, amtSat }
function txStyle(t) {
    var status    = t.payment_status;
    var isPending = status === 0 || status === 1;
    var isFailed  = status === null;
    var isOut     = t.paid_msat < 0;
    var amtSat    = Math.round(Math.abs(status === 0 ? t.requested_msat : t.paid_msat) / 1000);

    var clr, prefix, opacity, lineThrough, badge;

    if (isFailed) {
        clr = isOut ? '#f85149' : '#3fb950'; prefix = isOut ? '−' : '+'; opacity = '0.8'; lineThrough = 'text-decoration:line-through;';
       // clr = '#8b949e'; prefix = ''; opacity = '0.45'; lineThrough = 'text-decoration:line-through;';
        badge = '<span title="Payment failed" style="cursor:default;">✕</span> ';
    } else if (isPending) {
//        clr = '#d29922'; prefix = isOut ? '−' : '+'; opacity = '0.5'; lineThrough = '';
        clr = isOut ? '#f85149' : '#3fb950'; prefix = isOut ? '−' : '+'; opacity = '0.8'; lineThrough = '';
        var title = status === 0 ? 'Invoice issued, awaiting payment' : 'Payment in flight';
        badge = '<span title="' + title + '" style="cursor:default;">⏳</span> ';
    } else {
        clr = isOut ? '#f85149' : '#3fb950'; prefix = isOut ? '−' : '+'; opacity = '1'; lineThrough = '';
        badge = '';
    }

    return { badge: badge, clr: clr, prefix: prefix, opacity: opacity, lt: lineThrough, amtSat: amtSat };
}

// ── List ──────────────────────────────────────────────────────────────────────

window.txnsLoad = function(page) {
    txnsCurrentPage = page || 1;
    var search = document.getElementById('txns-search').value.trim();
    var listEl = document.getElementById('txns-list');
    var pagEl  = document.getElementById('txns-pagination');
    listEl.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">Loading…</p>';
    pagEl.innerHTML  = '';

    fetch('?txns_page=' + txnsCurrentPage + '&txns_search=' + encodeURIComponent(search))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            renderTxnList(data);
            renderTxnPagination(data);
        })
        .catch(function() {
            listEl.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">⚠ Failed to load transactions.</p>';
        });
};

function renderTxnList(data) {
    var listEl = document.getElementById('txns-list');

    if (!data.txns || data.txns.length === 0) {
        listEl.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">No transactions found.</p>';
        return;
    }

    var html =
        '<p style="font-size:0.8rem; color:#8b949e; margin-bottom:0.75rem">'
      + esc(data.total) + ' ' + (data.total == 1 ? 'transaction' : 'transactions') + ' total</p>'
      + '<table style="width:100%; border-collapse:collapse; font-size:0.78rem; font-family:monospace;">'
      + '<thead><tr style="color:#8b949e; border-bottom:1px solid #30363d;">'
      + '<th style="text-align:left;  padding:0.3rem 0.4rem; white-space:nowrap;"></th>'    // badge
      + '<th style="text-align:left;  padding:0.3rem 0.4rem; white-space:nowrap;">Date UTC</th>'
      + '<th style="text-align:right; padding:0.3rem 0.4rem;">Amount</th>'
      + '<th style="text-align:right; padding:0.3rem 0.4rem;">Fee</th>'
      + '<th style="text-align:left;  padding:0.3rem 0.4rem;">Memo</th>'
      + '<th style="text-align:left;  padding:0.3rem 0.4rem;">User</th>'
      + '</tr></thead><tbody>';

    data.txns.forEach(function(t) {
        var s    = txStyle(t);
        var date = (t.settled || t.created || '').substring(0, 16);
        var feeSat = Math.round((t.fee_msat || 0) / 1000);
        var user = t.pub_key ? t.pub_key.substring(0, 10) + '…' : '—';

        html +=
            '<tr style="border-bottom:1px solid #21262d; opacity:' + s.opacity + '; cursor:pointer;"'
          + ' onclick="txnDetailOpen(' + JSON.stringify(t) + ')">'
          + '<td style="padding:0.3rem 0.4rem; ' + s.lt + '">' + s.badge + '</td>'
          + '<td style="padding:0.3rem 0.4rem; color:#8b949e; white-space:nowrap; ' + s.lt + '">' + esc(date) + '</td>'
          + '<td style="padding:0.3rem 0.4rem; text-align:right; color:' + s.clr + '; ' + s.lt + '">' + s.prefix + s.amtSat.toLocaleString() + ' sat</td>'
          + '<td style="padding:0.3rem 0.4rem; text-align:right; color:#8b949e; ' + s.lt + '">' + (feeSat ? feeSat + ' sat' : '—') + '</td>'
          + '<td style="padding:0.3rem 0.4rem; color:#8b949e; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; ' + s.lt + '" title="' + esc(t.memo) + '">' + esc(t.memo || '') + '</td>'
          + '<td style="padding:0.3rem 0.4rem; color:#8b949e; ' + s.lt + '" title="' + esc(t.pub_key) + '">' + esc(user) + '</td>'
          + '</tr>';
    });

    html += '</tbody></table>';
    listEl.innerHTML = html;
}

function renderTxnPagination(data) {
    var pagEl = document.getElementById('txns-pagination');
    if (data.pages <= 1) { pagEl.innerHTML = ''; return; }

    var html = '';
    if (txnsCurrentPage > 1)
        html += '<button class="btn btn-secondary" onclick="txnsLoad(' + (txnsCurrentPage - 1) + ')" style="margin-top:0; padding:0.3rem 0.75rem;">← Prev</button>';
    html += '<span style="flex:1; text-align:center;">Page ' + txnsCurrentPage + ' / ' + data.pages + '</span>';
    if (txnsCurrentPage < data.pages)
        html += '<button class="btn btn-secondary" onclick="txnsLoad(' + (txnsCurrentPage + 1) + ')" style="margin-top:0; padding:0.3rem 0.75rem;">Next →</button>';
    pagEl.innerHTML = html;
}

document.getElementById('txns-search').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') txnsLoad(1);
});

// ── Detail overlay ────────────────────────────────────────────────────────────

window.txnDetailOpen = function(t) {
    document.getElementById('txn-detail-overlay').style.display = 'block';
    var s      = txStyle(t);
    var feeSat = Math.round((t.fee_msat || 0) / 1000);
    var date   = t.settled || t.created || '';

    // Status label
    var statusLabels = { 0: 'Invoice issued', 1: 'In flight', 2: 'Settled' };
    var statusLabel  = t.payment_status === null
        ? '<span style="color:#8b949e; text-decoration:line-through;">Failed</span>'
        : (t.payment_status === 0 || t.payment_status === 1)
            ? '<span style="color:#d29922;">⏳ ' + esc(statusLabels[t.payment_status]) + '</span>'
            : '<span style="color:#3fb950;">✓ Settled</span>';

    document.getElementById('txn-detail-body').innerHTML =

        // Amount + status
        '<div style="display:flex; align-items:baseline; gap:1rem; margin-bottom:1rem;">'
      + '  <span style="font-size:1.4rem; font-family:monospace; color:' + s.clr + '; ' + s.lt + '">'
      +     s.prefix + s.amtSat.toLocaleString() + ' sat'
      + '  </span>'
      + '  <span style="font-size:0.85rem;">' + statusLabel + '</span>'
      + '</div>'

        // Grid of fields
      + '<div style="display:grid; grid-template-columns:auto 1fr; gap:0.3rem 1rem; font-size:0.82rem; align-items:start; margin-bottom:1rem;">'

      + row('Date', esc(date) + ' UTC')
      + row('Fee', feeSat ? feeSat.toLocaleString() + ' sat' : '—')
      + row('Requested', satFmt(t.requested_msat))
      + row('Memo', esc(t.memo || '—'))
      + row('User', t.pub_key
            ? '<span style="font-family:monospace; word-break:break-all; font-size:0.75rem;">' + esc(t.pub_key) + '</span>'
            : '—')

      + '</div>'

        // Payment hash
      + (t.payment_hash
            ? '<label>Payment hash</label>'
            + '<code style="font-size:0.72rem; word-break:break-all; margin-bottom:0.75rem;">' + esc(t.payment_hash) + '</code>'
            : '');
};

function row(label, value) {
    return '<span style="color:#8b949e; white-space:nowrap;">' + label + '</span>'
         + '<span>' + value + '</span>';
}

function satFmt(msat) {
    return Math.round((msat || 0) / 1000).toLocaleString() + ' sat';
}

window.txnDetailClose = function() {
    document.getElementById('txn-detail-overlay').style.display = 'none';
};

document.getElementById('txn-detail-overlay').addEventListener('click', function(e) {
    if (e.target === this) txnDetailClose();
});

// ── Lazy-load when <details#transactions> first opens ─────────────────────────

document.querySelector('details#transactions').addEventListener('toggle', function(e) {
    if (e.target.open && !e.target.dataset.loaded) {
        e.target.dataset.loaded = true;
        txnsLoad(1);
    }
});

})();
</script>