<details id="users">
    <summary>Users</summary>
    <div class="card">

        <!-- Search -->
        <div style="display:flex; gap:0.5rem; margin-bottom:1rem; align-items:center;">
            <input id="users-search"
                   placeholder="Search by public key…"
                   style="margin-bottom:0; flex:1;"
                   autocomplete="off">
            <button class="btn btn-secondary" onclick="usersLoad(1)" style="margin-top:0; white-space:nowrap;">
                Search
            </button>
        </div>

        <!-- Results -->
        <div id="users-list">
            <p class="alert-muted" style="font-size:0.85rem">Loading…</p>
        </div>

        <!-- Pagination -->
        <div id="users-pagination" style="display:flex; gap:0.5rem; align-items:center; margin-top:0.75rem; font-size:0.85rem; color:#8b949e;">
        </div>

    </div>
</details>

<!-- User detail overlay -->
<div id="user-detail-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:100; overflow-y:auto; padding:2rem 1rem;">
    <div style="max-width:580px; margin:0 auto;">
        <div class="card" style="margin-bottom:0;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <h2 style="margin:0;">User detail</h2>
                <button class="btn btn-secondary" onclick="userDetailClose()" style="margin-top:0;">✕ Close</button>
            </div>
            <div id="user-detail-body">
                <p class="alert-muted" style="font-size:0.85rem">Loading…</p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {

var currentPage = 1;
var totalPages  = 1;

// ── Helpers ──────────────────────────────────────────────────────────────────

function esc(str) {
    return String(str ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function satFmt(msat) {
    var sat = Math.round((msat || 0) / 1000);
    return sat.toLocaleString() + ' sat';
}

function termsDot(u) {
    if (!u.terms_hash)    return '<span class="status-dot" style="background:#8b949e; display:inline-block;"></span>';
    if (u.terms_expired)  return '<span class="status-dot" style="background:#f85149; display:inline-block;"></span>';
    if (u.terms_due)      return '<span class="status-dot yellow" style="display:inline-block;"></span>';
    return '<span class="status-dot green" style="display:inline-block;"></span>';
}

function termsLabel(u) {
    if (!u.terms_hash)    return '<span style="color:#8b949e;">Not signed</span>';
    if (u.terms_expired)  return '<span style="color:#f85149;">✗ Expired — must re-sign by ' + esc(u.terms_valid_until) + ' UTC</span>';
    if (u.terms_due)      return '<span style="color:#d29922;">⚠ Must re-sign by ' + esc(u.terms_valid_until) + ' UTC</span>';
    return '<span style="color:#3fb950;">✓ Signed ' + esc(u.terms_time) + ' UTC</span>';
}

// ── List ─────────────────────────────────────────────────────────────────────

window.usersLoad = function(page) {
    currentPage = page || 1;
    var search = document.getElementById('users-search').value.trim();
    var listEl = document.getElementById('users-list');
    var pagEl  = document.getElementById('users-pagination');
    listEl.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">Loading…</p>';
    pagEl.innerHTML  = '';

    fetch('?users_page=' + currentPage + '&users_search=' + encodeURIComponent(search))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            totalPages = data.pages || 1;
            renderList(data);
            renderPagination(data);
        })
        .catch(function() {
            listEl.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">⚠ Failed to load users.</p>';
        });
};

function renderList(data) {
    var listEl = document.getElementById('users-list');
    if (!data.users || data.users.length === 0) {
        listEl.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">No users found.</p>';
        return;
    }

    var html = '<p style="font-size:0.8rem; color:#8b949e; margin-bottom:0.75rem">'
             + esc(data.total) + ' ' + (data.total == 1 ? 'user' : 'users') + ' total</p>';

    data.users.forEach(function(u) {
        html +=
            '<div style="display:flex; align-items:center; gap:0.5rem; padding:0.5rem 0; border-bottom:1px solid #21262d; flex-wrap:wrap; cursor:default;" onclick="userDetailOpen(' + u.idx + ')">'
          +   '<span style="flex-shrink:0;">' + termsDot(u) + '</span>'
          +   '<span style="font-family:monospace; font-size:0.75rem; color:#7ee787; flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">'
          +     esc(u.pub_key.substring(0, 24)) + '…'
          +   '</span>'
          +   '<span style="font-size:0.75rem; color:#8b949e; white-space:nowrap;">' + esc(u.group_name) + '</span>'
          +   '<span style="font-size:0.75rem; color:#7ee787; font-family:monospace; white-space:nowrap;">' + satFmt(u.balance_msat) + '</span>'
          +   '<button class="btn btn-secondary" onclick="event.stopPropagation(); userDetailOpen(' + u.idx + ')" style="margin-top:0; padding:0.3rem 0.75rem; font-size:0.8rem;">Detail</button>'
          + '</div>';
    });

    listEl.innerHTML = html;
}

function renderPagination(data) {
    var pagEl = document.getElementById('users-pagination');
    if (data.pages <= 1) { pagEl.innerHTML = ''; return; }

    var html = '';
    if (currentPage > 1)
        html += '<button class="btn btn-secondary" onclick="usersLoad(' + (currentPage - 1) + ')" style="margin-top:0; padding:0.3rem 0.75rem;">← Prev</button>';
    html += '<span style="flex:1; text-align:center;">Page ' + currentPage + ' / ' + data.pages + '</span>';
    if (currentPage < data.pages)
        html += '<button class="btn btn-secondary" onclick="usersLoad(' + (currentPage + 1) + ')" style="margin-top:0; padding:0.3rem 0.75rem;">Next →</button>';
    pagEl.innerHTML = html;
}

document.getElementById('users-search').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') usersLoad(1);
});

// ── Detail panel ─────────────────────────────────────────────────────────────

window.userDetailOpen = function(idx) {
    var overlay = document.getElementById('user-detail-overlay');
    var body    = document.getElementById('user-detail-body');
    overlay.style.display = 'block';
    body.innerHTML = '<p class="alert-muted" style="font-size:0.85rem">Loading…</p>';

    fetch('?user_detail=' + encodeURIComponent(idx))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                body.innerHTML = '<p class="alert-muted">' + esc(data.message) + '</p>';
                return;
            }
            renderDetail(data);
        })
        .catch(function() {
            body.innerHTML = '<p class="alert-muted">⚠ Failed to load user detail.</p>';
        });
};

window.userDetailClose = function() {
    document.getElementById('user-detail-overlay').style.display = 'none';
};

document.getElementById('user-detail-overlay').addEventListener('click', function(e) {
    if (e.target === this) userDetailClose();
});

function renderDetail(d) {
    var u    = d.user;
    var txns = d.transactions;

    // Group select options
    var groupOptions = d.groups.map(function(g) {
        return '<option value="' + esc(g.level) + '"' + (g.level == u.level ? ' selected' : '') + '>'
             + esc(g.name) + '</option>';
    }).join('');

    // Transactions table
    var txHtml = '';
    if (!txns || txns.length === 0) {
        txHtml = '<p class="alert-muted" style="font-size:0.85rem; margin-top:0.5rem">No transactions yet.</p>';
    } else {
        txHtml = '<div style="overflow-x:auto; margin-top:0.5rem;">'
               + '<table style="width:100%; border-collapse:collapse; font-size:0.78rem; font-family:monospace;">'
               + '<thead><tr style="color:#8b949e; border-bottom:1px solid #30363d;">'
               + '<th style="text-align:left;  padding:0.3rem 0.4rem; white-space:nowrap;">Date UTC</th>'
               + '<th style="text-align:right; padding:0.3rem 0.4rem;">Amount</th>'
               + '<th style="text-align:right; padding:0.3rem 0.4rem;">Fee</th>'
               + '<th style="text-align:left;  padding:0.3rem 0.4rem;">Memo</th>'
               + '</tr></thead><tbody>';

        txns.forEach(function(t) {
            var status   = t.payment_status;   // 0=issued, 1=in-flight, 2=settled, null=failed
            var isPending = status === 0 || status === 1;
            var isFailed  = status === null;
            var isOut    = t.paid_msat < 0;
            var amtSat   = Math.round(Math.abs(t.paid_msat) / 1000);
            if (status === 0) {
               amtSat = Math.round(Math.abs(t.requested_msat) / 1000);
            }
            var feeSat   = Math.round((t.fee_msat || 0) / 1000);
            var date     = t.settled || t.created || '';

            // Colour & prefix depend on status
            var clr, prefix, rowOpacity;
            if (isFailed) {
                clr = isOut ? '#f85149' : '#3fb950'; prefix = isOut ? '−' : '+'; rowOpacity = '0.8';
//                clr = '#8b949e'; prefix = ''; rowOpacity = '0.45';
            } else if (isPending) {
                clr = isOut ? '#f85149' : '#3fb950'; prefix = isOut ? '−' : '+'; rowOpacity = '0.8';
//                clr = '#d29922'; prefix = isOut ? '−' : '+';  rowOpacity = '1';
            } else {
                clr = isOut ? '#f85149' : '#3fb950'; prefix = isOut ? '−' : '+'; rowOpacity = '1';
            }

            // Status badge
            var badge;
            if (isPending) {
                var title = status === 0 ? 'Invoice issued, awaiting payment' : 'Payment in flight';
                badge = '<span title="' + title + '" style="font-size:0.9em; cursor:default;">⏳</span> ';
            } else if (isFailed) {
                badge = '<span title="Payment failed" style="font-size:0.9em; cursor:default;">✕</span> ';
            } else {
                badge = '';
            }

            // Strikethrough everything on failed
            var lineThrough = isFailed ? 'text-decoration:line-through;' : '';

            txHtml +=
                '<tr style="border-bottom:1px solid #21262d; opacity:' + rowOpacity + ';">'
              + '<td style="padding:0.3rem 0.4rem; color:#8b949e; white-space:nowrap; ' + lineThrough + '">' + badge + esc(date.substring(0, 16)) + '</td>'
              + '<td style="padding:0.3rem 0.4rem; text-align:right; color:' + clr + '; ' + lineThrough + '">' + prefix + amtSat.toLocaleString() + ' sat</td>'
              + '<td style="padding:0.3rem 0.4rem; text-align:right; color:#8b949e; ' + lineThrough + '">' + (feeSat ? feeSat + ' sat' : '—') + '</td>'
              + '<td style="padding:0.3rem 0.4rem; color:#8b949e; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; ' + lineThrough + '" title="' + esc(t.memo) + '">' + esc(t.memo || '') + '</td>'
              + '</tr>';
        });

        txHtml += '</tbody></table></div>';
    }

    document.getElementById('user-detail-body').innerHTML =

        // ── Identity ──
        '<label>Public key (base64url)</label>'
      + '<code style="font-size:0.72rem; word-break:break-all;">' + esc(u.pub_key) + '</code>'

        // ── Stats row ──
      + '<div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:0.75rem; margin-bottom:0.75rem;">'
      +   '<div><label>Balance</label>'
      +     '<code style="color:#7ee787; margin-bottom:0; text-align:right;">' + satFmt(u.balance_msat) + '</code></div>'
      +   '<div><label>Registered</label>'
      +     '<code style="color:#8b949e; margin-bottom:0; font-size:0.72rem;">' + esc((u.created || '—').substring(0, 10)) + '</code></div>'
      +   '<div><label>Last login</label>'
      +     '<code style="color:#8b949e; margin-bottom:0; font-size:0.72rem;">' + esc((u.lastLogin || '—').substring(0, 10)) + '</code></div>'
      + '</div>'

        // ── Terms ──
      + '<label>Terms status</label>'
      + '<div style="font-size:0.85rem; margin-bottom:0.75rem;">' + termsLabel(u) + '</div>'

        // ── Change group ──
      + '<hr class="separator">'
      + '<h2 style="font-size:0.95rem; margin-bottom:0.75rem;">Change group</h2>'
      + '<form onsubmit="userGroupSave(event, ' + u.idx + ')" autocomplete="off">'
      +   '<label>Group</label>'
      +   '<select name="level" class="mono" id="ud-level-' + u.idx + '">' + groupOptions + '</select>'
      +   '<label style="margin-top:0.25rem;">Days to sign new terms before receiving is blocked</label>'
      +   '<input type="number" name="sign_deadline_days" id="ud-deadline-' + u.idx + '" min="1" max="365" value="30">'
      +   '<p class="alert-muted" style="font-size:0.8rem; margin-top:-0.4rem; margin-bottom:0.75rem;">'
      +     'If the user does not sign the updated terms within this period, they will be unable to receive further payments.'
      +   '</p>'
      +   '<div style="display:flex; justify-content:flex-end;">'
      +     '<button class="btn btn-secondary" type="submit" style="margin-top:0;">Save</button>'
      +   '</div>'
      +   '<div id="ud-msg-' + u.idx + '" style="margin-top:0.5rem; font-size:0.85rem;"></div>'
      + '</form>'

        // ── Transactions ──
      + '<hr class="separator">'
      + '<h2 style="font-size:0.95rem; margin-bottom:0;">Recent transactions</h2>'
      + txHtml;
}

// ── Save group via AJAX ───────────────────────────────────────────────────────

window.userGroupSave = function(e, idx) {
    e.preventDefault();
    var level    = document.getElementById('ud-level-'    + idx).value;
    var deadline = document.getElementById('ud-deadline-' + idx).value;
    var msgEl    = document.getElementById('ud-msg-'      + idx);

    msgEl.style.color = '#8b949e';
    msgEl.textContent = 'Saving…';

    var csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    var body = new URLSearchParams();
    body.append('action',             'edit_user');
    body.append('idx',                idx);
    body.append('level',              level);
    body.append('sign_deadline_days', deadline);
    body.append('csrf_token',         csrfToken);

    fetch(window.location.pathname, { method: 'POST', body: body })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok) {
                msgEl.style.color = '#3fb950';
                msgEl.textContent = '✓ Saved.';
                usersLoad(currentPage);  // refresh list
            } else {
                msgEl.style.color = '#f85149';
                msgEl.textContent = '✗ ' + esc(data.error || 'Error.');
            }
        })
        .catch(function() {
            msgEl.style.color = '#f85149';
            msgEl.textContent = '✗ Request failed.';
        });
};

// ── Lazy-load when <details#users> first opens ───────────────────────────────

document.querySelector('details#users').addEventListener('toggle', function(e) {
    if (e.target.open && !e.target.dataset.loaded) {
        e.target.dataset.loaded = true;
        usersLoad(1);
    }
});

})();
</script>