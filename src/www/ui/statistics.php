<details id="statistics">
    <summary>Statistics</summary>
    <div id="stats-root">
        <p class="alert-muted" style="font-size:0.85rem; padding:0.5rem 0;">Loading…</p>
    </div>
</details>

<script>
(function () {

// ── Helpers ───────────────────────────────────────────────────────────────────

function esc(str) {
    return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function satFmt(msat) {
    var sat = Math.round((msat || 0) / 1000);
    if (sat >= 1000000) return (sat / 1000000).toFixed(2) + 'M sat';
    if (sat >= 1000)    return (sat / 1000).toFixed(1)    + 'k sat';
    return sat.toLocaleString() + ' sat';
}

function pct(a, b) {
    return b > 0 ? Math.round(a / b * 100) : 0;
}

function tile(label, value, sub, valueColor) {
    return '<div style="background:#0d1117; border:1px solid #30363d; border-radius:6px; padding:0.85rem 1rem;">'
         +   '<div style="font-size:0.75rem; color:#8b949e; margin-bottom:0.3rem;">' + label + '</div>'
         +   '<div style="font-size:1.25rem; font-family:monospace; font-weight:600; color:' + (valueColor || '#e6edf3') + ';">' + value + '</div>'
         +   (sub ? '<div style="font-size:0.72rem; color:#8b949e; margin-top:0.2rem;">' + sub + '</div>' : '')
         + '</div>';
}

function bar(value, total, color) {
    var w = total > 0 ? Math.min(100, Math.round(value / total * 100)) : 0;
    return '<div style="background:#21262d; border-radius:3px; height:6px; margin-top:0.3rem;">'
         +   '<div style="background:' + color + '; width:' + w + '%; height:6px; border-radius:3px;"></div>'
         + '</div>';
}

function heading(text) {
    return '<h2 style="font-size:0.75rem; text-transform:uppercase; letter-spacing:0.08em; color:#8b949e; margin:1.25rem 0 0.6rem; padding-bottom:0.3rem; border-bottom:1px solid #21262d;">' + text + '</h2>';
}

// ── Render ────────────────────────────────────────────────────────────────────

function render(d) {
    var tx  = d.transactions;
    var u   = d.users;
    var b   = d.balance;
    var l30 = d.last_30d;

    var html = '<div class="card" style="margin-bottom:0;">';

    // ── Overview tiles ──
    html += heading('Overview');
    html += '<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(145px, 1fr)); gap:0.6rem;">';
    html += tile('Total users',      u.total,              u.active_30d + ' active last 30 days');
    html += tile('Total balance',    satFmt(b.total_msat), null, '#7ee787');
    html += tile('All transactions', tx.total,             l30.txns_total + ' in last 30 days');
    html += tile('Settled',          tx.settled,           pct(tx.settled, tx.total) + '% of all', '#3fb950');
    html += tile('Failed',           tx.failed,            pct(tx.failed,  tx.total) + '% of all', tx.failed > 0 ? '#f85149' : '#8b949e');
    html += tile('Volume (settled)', satFmt(tx.volume_msat), null, '#7ee787');
    html += tile('Fees collected',   satFmt(tx.fees_msat),   null, '#f7931a');
    html += tile('In flight now',    tx.in_flight,         tx.issued + ' invoices open', tx.in_flight > 0 ? '#d29922' : '#8b949e');
    html += '</div>';

    // ── Transaction breakdown bar chart ──
    html += heading('Transaction breakdown');
    html += '<div style="font-size:0.82rem;">';

    function txRow(label, count, color) {
        return '<div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:0.45rem;">'
             +   '<span style="width:80px; color:#8b949e; flex-shrink:0;">' + label + '</span>'
             +   '<div style="flex:1;">' + bar(count, tx.total, color) + '</div>'
             +   '<span style="width:90px; text-align:right; font-family:monospace; color:' + color + ';">'
             +     count + ' <span style="color:#8b949e; font-size:0.7rem;">(' + pct(count, tx.total) + '%)</span>'
             +   '</span>'
             + '</div>';
    }

    html += txRow('Settled',   tx.settled,   '#3fb950');
    html += txRow('In flight', tx.in_flight, '#d29922');
    html += txRow('Issued',    tx.issued,    '#8b949e');
    html += txRow('Failed',    tx.failed,    '#f85149');
    html += '</div>';

    // ── Terms compliance ──
    html += heading('Terms compliance');
    html += '<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(145px, 1fr)); gap:0.6rem;">';
    var terms_ok = Math.max(0, u.total - u.terms_expired - u.no_terms);
    html += tile('Terms OK',       terms_ok,        pct(terms_ok,        u.total) + '% of users', '#3fb950');
    html += tile('Expired / due',  u.terms_expired, pct(u.terms_expired, u.total) + '% of users', u.terms_expired > 0 ? '#f85149' : '#8b949e');
    html += tile('Never signed',   u.no_terms,      pct(u.no_terms,      u.total) + '% of users', u.no_terms > 0 ? '#d29922' : '#8b949e');
    html += '</div>';

    // ── Per-group breakdown ──
    if (d.groups && d.groups.length > 0) {
        html += heading('Users by group');
        html += '<div style="overflow-x:auto;">';
        html += '<table style="width:100%; border-collapse:collapse; font-size:0.8rem;">';
        html += '<thead><tr style="color:#8b949e; border-bottom:1px solid #30363d;">'
              + '<th style="text-align:left;  padding:0.35rem 0.5rem;">Group</th>'
              + '<th style="text-align:right; padding:0.35rem 0.5rem;">Users</th>'
              + '<th style="text-align:right; padding:0.35rem 0.5rem;">Total balance</th>'
              + '<th style="text-align:right; padding:0.35rem 0.5rem;">Max balance</th>'
              + '<th style="text-align:right; padding:0.35rem 0.5rem;">Fee ppm</th>'
              + '</tr></thead><tbody>';

        d.groups.forEach(function(g) {
            html += '<tr style="border-bottom:1px solid #21262d;">'
                  + '<td style="padding:0.35rem 0.5rem; color:#c9d1d9;">' + esc(g.name) + '</td>'
                  + '<td style="padding:0.35rem 0.5rem; text-align:right; color:#8b949e; font-family:monospace;">' + g.user_count + '</td>'
                  + '<td style="padding:0.35rem 0.5rem; text-align:right; color:#7ee787; font-family:monospace;">' + satFmt(g.balance_msat) + '</td>'
                  + '<td style="padding:0.35rem 0.5rem; text-align:right; color:#8b949e; font-family:monospace;">' + Number(g.max_balance_sat).toLocaleString() + ' sat</td>'
                  + '<td style="padding:0.35rem 0.5rem; text-align:right; color:#8b949e; font-family:monospace;">' + g.fee_ppm + '</td>'
                  + '</tr>';
        });

        html += '</tbody></table></div>';
    }

    // ── Top 5 users by balance ──
    if (d.top_users && d.top_users.length > 0) {
        var maxBal = Math.max.apply(null, d.top_users.map(function(tu) { return Number(tu.balance_msat); }));
        html += heading('Top users by balance');
        html += '<div style="font-size:0.8rem;">';

        d.top_users.forEach(function(tu, i) {
            var key = tu.pub_key ? tu.pub_key.substring(0, 18) + '…' : '—';
            html +=
                '<div style="display:flex; align-items:center; gap:0.6rem; margin-bottom:0.5rem;">'
              +   '<span style="width:1rem; color:#8b949e; text-align:right; flex-shrink:0;">' + (i + 1) + '</span>'
              +   '<span style="width:160px; font-family:monospace; font-size:0.75rem; color:#7ee787; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex-shrink:0;" title="' + esc(tu.pub_key) + '">' + esc(key) + '</span>'
              +   '<div style="flex:1;">' + bar(tu.balance_msat, maxBal, '#7ee787') + '</div>'
              +   '<span style="width:80px; text-align:right; font-family:monospace; color:#7ee787; flex-shrink:0;">' + satFmt(tu.balance_msat) + '</span>'
              + '</div>';
        });

        html += '</div>';
    }

    html += '</div>'; // /card

    document.getElementById('stats-root').innerHTML = html;
}

// ── Load & lazy init ──────────────────────────────────────────────────────────

function statsLoad() {
    document.getElementById('stats-root').innerHTML =
        '<p class="alert-muted" style="font-size:0.85rem; padding:0.5rem 0;">Loading…</p>';

    fetch('?stats')
        .then(function(r) { return r.json(); })
        .then(render)
        .catch(function() {
            document.getElementById('stats-root').innerHTML =
                '<p class="alert-muted" style="font-size:0.85rem; padding:0.5rem 0;">⚠ Failed to load statistics.</p>';
        });
}

document.querySelector('details#statistics').addEventListener('toggle', function(e) {
    if (e.target.open && !e.target.dataset.loaded) {
        e.target.dataset.loaded = true;
        statsLoad();
    }
});

})();
</script>