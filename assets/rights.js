/* SzenzorKozpont — rights page (assets/rights.js).
   CSP: inline <script> banned, so separate file.
   - group collapse/expand (open by default)
   - group ↔ child check sync (control implies view)
   - name filter (display only, checks intact)
   - "all on/off" toggles (for filtered rows) */
(function () {
  "use strict";
  var t = document.getElementById("acltable");
  if (!t) {
    return;
  }

  function rows(sel) {
    return Array.prototype.slice.call(t.querySelectorAll(sel));
  }
  function kids(gk, cls) {
    return rows('tr.sen[data-gk="' + gk + '"] input.' + cls);
  }
  function head(gk) {
    return t.querySelector('tr.grp[data-gk="' + gk + '"]');
  }
  function trOf(el) {
    var r = el;
    while (r && r.tagName !== "TR") {
      r = r.parentNode;
    }
    return r;
  }
  function allChecked(bs) {
    if (!bs.length) {
      return false;
    }
    for (var i = 0; i < bs.length; i++) {
      if (!bs[i].checked) {
        return false;
      }
    }
    return true;
  }
  function setAll(bs, on) {
    bs.forEach(function (b) {
      b.checked = on;
    });
  }

  // control ⇒ view; unview ⇒ uncontrol. Direction matters:
  // unchecking view UNchecks control, checking control CHECKS
  // view — applying both together would re-check view
  // (as control was still on).
  function viewChanged(view, ctrl) {
    if (view && ctrl && !view.checked) {
      ctrl.checked = false;
    }
  }
  function ctrlChanged(view, ctrl) {
    if (view && ctrl && ctrl.checked) {
      view.checked = true;
    }
  }

  // Group check = whether all children checked in that column.
  function syncGroup(gk) {
    var h = head(gk);
    if (!h) {
      return;
    }
    var gv = h.querySelector(".gv");
    var gc = h.querySelector(".gc");
    if (gv) {
      gv.checked = allChecked(kids(gk, "sv"));
    }
    if (gc) {
      gc.checked = allChecked(kids(gk, "sc"));
    }
  }
  function syncAllGroups() {
    var seen = [];
    var sr = rows("tr.sen");
    for (var i = 0; i < sr.length; i++) {
      var gk = sr[i].getAttribute("data-gk") || "";
      if (seen.indexOf(gk) === -1) {
        seen.push(gk);
        syncGroup(gk);
      }
    }
  }
  document.addEventListener("change", function (ev) {
    var el = ev.target;
    if (!el || !el.classList) {
      return;
    }

    // "all on/off" — shown (filtered) rows; constraint applies
    // on same rows (group checks derived after)
    if (el.id === "aclallv" || el.id === "aclallc") {
      var isV = el.id === "aclallv";
      var on = el.checked;
      setAll(rows("tr:not(.fh) input." + (isV ? "sv" : "sc")), on);
      if (isV && !on) {
        setAll(rows("tr:not(.fh) input.sc"), false);
      } else if (!isV && on) {
        setAll(rows("tr:not(.fh) input.sv"), true);
      }
      syncAllGroups();
      return;
    }

    // group check: copy edited column to children
    if (el.classList.contains("gv") || el.classList.contains("gc")) {
      var gk = el.getAttribute("data-gk") || "";
      var h = head(gk);
      if (!h) {
        return;
      }
      var gv = h.querySelector(".gv");
      var gc = h.querySelector(".gc");
      if (!gv || !gc) {
        return;
      }
      var isGv = el.classList.contains("gv");
      var c0 = gc.checked;
      var v0 = gv.checked;
      if (isGv) {
        viewChanged(gv, gc);
      } else {
        ctrlChanged(gv, gc);
      }
      // copy edited column to all children
      setAll(kids(gk, isGv ? "sv" : "sc"), isGv ? gv.checked : gc.checked);
      // if constraint changed the other column too, follow it
      if (isGv && gc.checked !== c0) {
        setAll(kids(gk, "sc"), gc.checked);
      } else if (!isGv && gv.checked !== v0) {
        setAll(kids(gk, "sv"), gv.checked);
      }
      return;
    }

    // child: control/view sync + group recount
    if (el.classList.contains("sv") || el.classList.contains("sc")) {
      var tr = trOf(el);
      if (!tr) {
        return;
      }
      var sv = tr.querySelector(".sv");
      var sc = tr.querySelector(".sc");
      if (el.classList.contains("sv")) {
        viewChanged(sv, sc);
      } else {
        ctrlChanged(sv, sc);
      }
      syncGroup(tr.getAttribute("data-gk") || "");
      return;
    }
  });

  // group collapse/expand (open by default)
  document.addEventListener("click", function (ev) {
    var el = ev.target;
    if (!el || !el.classList || !el.classList.contains("tgl")) {
      return;
    }
    var gk = el.getAttribute("data-gk") || "";
    var open = el.getAttribute("aria-expanded") === "true";
    el.setAttribute("aria-expanded", open ? "false" : "true");
    el.textContent = open ? "▸" : "▾";
    rows('tr.sen[data-gk="' + gk + '"]').forEach(function (r) {
      if (open) {
        r.classList.add("fold");
      } else {
        r.classList.remove("fold");
      }
    });
  });

  // name filter + counter
  var q = document.getElementById("aclq");
  var cnt = document.getElementById("aclcnt");
  var total = rows("tr.sen").length;
  function applyFilter() {
    var v = q ? String(q.value || "").trim().toLowerCase() : "";
    var sr = rows("tr.sen");
    var shown = 0;
    for (var i = 0; i < sr.length; i++) {
      var r = sr[i];
      var n = (r.getAttribute("data-n") || "").toLowerCase();
      var h = head(r.getAttribute("data-gk") || "");
      var gmatch = v !== "" && h &&
        (h.getAttribute("data-n") || "").toLowerCase().indexOf(v) !== -1;
      var show = v === "" || n.indexOf(v) !== -1 || gmatch;
      if (show) {
        r.classList.remove("fh");
        shown++;
      } else {
        r.classList.add("fh");
      }
    }
    var hr = rows("tr.grp");
    for (var j = 0; j < hr.length; j++) {
      var g = hr[j];
      var gk = g.getAttribute("data-gk") || "";
      var own = v !== "" &&
        (g.getAttribute("data-n") || "").toLowerCase().indexOf(v) !== -1;
      var any = false;
      var gr = rows('tr.sen[data-gk="' + gk + '"]');
      for (var k = 0; k < gr.length; k++) {
        if (!gr[k].classList.contains("fh")) {
          any = true;
          break;
        }
      }
      if (v === "" || own || any) {
        g.classList.remove("fh");
      } else {
        g.classList.add("fh");
      }
    }
    if (cnt) {
      cnt.textContent = shown + " / " + total;
    }
  }
  if (q) {
    q.addEventListener("input", applyFilter);
  }
  applyFilter();

  // client switch — saved rights belong to selected one
  var sel = document.getElementById("aclclient");
  if (sel) {
    sel.addEventListener("change", function () {
      location.href = location.pathname + "?c=" + encodeURIComponent(sel.value);
    });
  }
})();
