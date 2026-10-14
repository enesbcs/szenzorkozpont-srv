/* SzenzorKozpont — small UI helpers (separate file for CSP). */
(function () {
  function syncHeader(table) {
    var head = table.querySelector('thead input[data-select-all], tr:first-child input[data-select-all]');
    if (!head) return;
    var boxes = table.querySelectorAll('tbody input[type="checkbox"][name^="sel["], tr input[type="checkbox"][name^="sel["]');
    // Row checkboxes only (skip header).
    var rows = [];
    for (var i = 0; i < boxes.length; i++) {
      if (boxes[i] !== head) rows.push(boxes[i]);
    }
    if (!rows.length) {
      head.checked = false;
      head.indeterminate = false;
      return;
    }
    var n = 0;
    for (var j = 0; j < rows.length; j++) if (rows[j].checked) n++;
    head.checked = n === rows.length;
    head.indeterminate = n > 0 && n < rows.length;
  }

  // Slider → shown value: <input type="range" data-out="elem-id">
  // tracks matching <output id="elem-id"> text. 'input' fires
  // while dragging, 'change' on release — refresh on both so
  // the set value always shows before submit.
  function syncRange(el) {
    if (el && el.matches && el.matches('input[type="range"][data-out]')) {
      var out = document.getElementById(el.getAttribute('data-out'));
      if (out) {
        out.textContent = el.value;
      }
    }
  }
  document.addEventListener('input', function (ev) {
    syncRange(ev.target);
  });
  document.addEventListener('change', function (ev) {
    syncRange(ev.target);
  });

  // Auto refresh: <input type="checkbox" id="autorefresh"
  // data-secs="60" checked> (user client page 60s, log 5s).
  // Reloads page when on; state persists in
  // localStorage (so off stays off).
  (function () {
    var box = document.getElementById('autorefresh');
    if (!box) {
      return;
    }
    var secs = parseInt(box.getAttribute('data-secs') || '60', 10);
    if (!(secs > 0)) {
      secs = 60;
    }
    var KEY = 'szk_autorefresh';
    var t = null;
    function arm(on) {
      if (t) {
        clearTimeout(t);
        t = null;
      }
      if (on) {
        t = setTimeout(function () {
          location.reload();
        }, secs * 1000);
      }
    }
    try {
      var saved = localStorage.getItem(KEY);
      if (saved === '0') {
        box.checked = false;
      } else if (saved === '1') {
        box.checked = true;
      }
    } catch (e) {}
    box.addEventListener('change', function () {
      try {
        localStorage.setItem(KEY, box.checked ? '1' : '0');
      } catch (e) {}
      arm(box.checked);
    });
    arm(box.checked);
  })();

  document.addEventListener('DOMContentLoaded', function () {
    var tables = document.querySelectorAll('table');
    for (var t = 0; t < tables.length; t++) {
      (function (table) {
        var head = table.querySelector('tr:first-child input[data-select-all]');
        if (head) {
          head.addEventListener('change', function () {
            var boxes = table.querySelectorAll('input[type="checkbox"][name^="sel["]');
            for (var i = 0; i < boxes.length; i++) {
              if (boxes[i] !== head) boxes[i].checked = head.checked;
            }
            syncHeader(table);
          });
        }
        table.addEventListener('change', function (ev) {
          if (ev.target && ev.target.matches('input[type="checkbox"][name^="sel["]')) {
            syncHeader(table);
          }
        });
        syncHeader(table);
      })(tables[t]);
    }
  });

  // Theme toggle: cookie + html[data-theme]. Server pre-renders
  // from cookie (no flash); JS only syncs, toggles, and saves
  // system theme on first visit.
  (function () {
    function readCookie() {
      var m = document.cookie.match(/(?:^|;\s*)szk_theme=(dark|light)/);
      return m ? m[1] : null;
    }
    function cur() {
      return document.documentElement.getAttribute('data-theme') || 'light';
    }
    function set(t, save) {
      if (t === 'light') {
        document.documentElement.removeAttribute('data-theme');
      } else {
        document.documentElement.setAttribute('data-theme', t);
      }
      if (save) {
        try { document.cookie = 'szk_theme=' + t + '; path=/; max-age=31536000'; } catch (e) {}
      }
      var b = document.getElementById('theme-toggle');
      if (b) b.textContent = (t === 'dark') ? '☀' : '◐';
    }
    document.addEventListener('DOMContentLoaded', function () {
      var saved = readCookie();
      var t = saved || cur();
      if (!saved) {
        // First visit (no cookie): system theme, then save.
        t = (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)
          ? 'dark' : 'light';
        set(t, true);
      } else {
        set(t, false);
      }
      var b = document.getElementById('theme-toggle');
      if (b) b.addEventListener('click', function () {
        set(cur() === 'dark' ? 'light' : 'dark', true);
      });
    });
  })();
  // Sensor search: #sensor-search filters .sen cards by text,
  // hides emptied .card groups. Pure client-side.
  (function () {
    function apply(box) {
      var q = (box.value || '').toLowerCase();
      var cards = document.querySelectorAll('.sen');
      for (var i = 0; i < cards.length; i++) {
        var txt = (cards[i].textContent || '').toLowerCase();
        cards[i].style.display = (!q || txt.indexOf(q) !== -1) ? '' : 'none';
      }
      var wraps = document.querySelectorAll('.card');
      for (var w = 0; w < wraps.length; w++) {
        var items = wraps[w].querySelectorAll('.sgrid .sen');
        if (!items.length) continue;
        var any = false;
        for (var j = 0; j < items.length; j++) {
          if (items[j].style.display !== 'none') { any = true; break; }
        }
        wraps[w].style.display = any ? '' : 'none';
      }
    }
    document.addEventListener('DOMContentLoaded', function () {
      var box = document.getElementById('sensor-search');
      if (!box) return;
      box.addEventListener('input', function () { apply(box); });
    });
  })();
  // QR render: #qrbox[data-payload] + qrcode.js lib (runs only
  // if page included both — e.g. after usertoken generation).
  (function () {
    document.addEventListener('DOMContentLoaded', function () {
      var box = document.getElementById('qrbox');
      if (!box || typeof QRCode === 'undefined') return;
      try {
        new QRCode(box, {
          text: box.getAttribute('data-payload') || '',
          width: 256, height: 256,
          correctLevel: QRCode.CorrectLevel.M
        });
      } catch (e) {}
    });
  })();
  // Rule form: client picker loads only that client's devices
  // (fetch). Checks survive client switches; on save hidden
  // clients' marks go as hidden fields.
  (function () {
    function escapeHtml(s) {
      return String(s).replace(/[&<>"']/g, function (c) {
        return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
      });
    }
    document.addEventListener('DOMContentLoaded', function () {
      var sel = document.getElementById('rule-client');
      var box = document.getElementById('rule-sensors');
      if (!sel || !box) return;
      var keep = document.getElementById('rule-keep');
      var info = document.getElementById('rule-selinfo');
      var chosen = {};
      // Translated message from #rule-selinfo data attributes (server-rendered).
      function msg(name) {
        return info ? (info.getAttribute(name) || '') : '';
      }
      function stored() {
        try {
          var el = document.getElementById('rule-stored');
          if (!el) return [];
          var v = JSON.parse(el.textContent || '[]');
          return Array.isArray(v) ? v : [];
        } catch (e) { return []; }
      }
      function refreshInfo() {
        var n = 0;
        for (var k in chosen) if (chosen[k]) n++;
        // Message templates come from data attributes (server-rendered, translated).
        var tpl = info ? (info.getAttribute('data-sel') || '') : '';
        if (info) info.textContent = n ? tpl.split('{n}').join(String(n)) : '';
      }
      function stash() {
        var boxes = box.querySelectorAll('input[type="checkbox"][name="sensors[]"]');
        for (var i = 0; i < boxes.length; i++) {
          var id = parseInt(boxes[i].value, 10);
          if (id > 0) {
            if (boxes[i].checked) chosen[id] = true;
            else delete chosen[id];
          }
        }
      }
      function render(list) {
        var h = '';
        for (var i = 0; i < list.length; i++) {
          var s = list[i];
          var lbl = (s.label && s.label !== '') ? s.label : s.key;
          h += '<label style="display:block"><input type="checkbox" name="sensors[]" value="' + s.id + '"'
            + (chosen[s.id] ? ' checked' : '') + '> ' + escapeHtml(lbl)
            + ' <small>' + escapeHtml(s.key) + '</small></label>';
        }
        box.innerHTML = list.length ? h
          : '<p><small>' + escapeHtml(msg('data-empty')) + '</small></p>';
        refreshInfo();
      }
      function load(cid) {
        if (!cid) {
          box.innerHTML = '<p><small>' + escapeHtml(msg('data-pick')) + '</small></p>';
          return;
        }
        fetch(box.getAttribute('data-url') + '?client=' + encodeURIComponent(cid),
            {headers: {'Accept': 'application/json'}})
          .then(function (r) { if (!r.ok) throw 0; return r.json(); })
          .then(function (d) { render((d && d.sensors) || []); })
          .catch(function () {
            box.innerHTML = '<p><small>' + escapeHtml(msg('data-err')) + '</small></p>';
          });
      }
      var pre = stored();
      var firstCid = 0;
      for (var p = 0; p < pre.length; p++) {
        if (pre[p].id > 0) {
          chosen[pre[p].id] = true;
          if (!firstCid && pre[p].cid > 0) firstCid = pre[p].cid;
        }
      }
      refreshInfo();
      if (firstCid) {
        sel.value = String(firstCid);
        load(firstCid);
      }
      sel.addEventListener('change', function () {
        stash();
        load(parseInt(sel.value, 10) || 0);
      });
      var form = box.closest ? box.closest('form') : null;
      if (form) form.addEventListener('submit', function () {
        stash();
        if (!keep) return;
        var have = {};
        var cur = box.querySelectorAll('input[type="checkbox"][name="sensors[]"]');
        for (var i = 0; i < cur.length; i++) have[parseInt(cur[i].value, 10)] = true;
        var h = '';
        for (var id in chosen) {
          if (chosen[id] && !have[id]) {
            h += '<input type="hidden" name="sensors[]" value="' + parseInt(id, 10) + '">';
          }
        }
        keep.innerHTML = h;
      });
    });
  })();
})();
