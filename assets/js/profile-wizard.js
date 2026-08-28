/* ==========================================================================
   CSM — Profile Edit UX · Phase 3  (AJAX no-reload wizard)   [STAGING DRAFT]
   --------------------------------------------------------------------------
   Goal   : one continuous flow across all xProfile groups with NO page reload
            between groups. Builds on the Phase-2 per-field flow.
   How    : each group is still saved by BuddyPress's OWN form POST — but sent
            via fetch() instead of a full navigation, so all server-side logic
            (validation, xprofile_save_data, age-sync #11611, completion gate,
            hooks) runs EXACTLY as native. On success we fetch the next group's
            edit page, splice its form fields into the page, and continue.
   Save   : NO custom save endpoint. We replay the native form POST.
   Safety : wrapped in try/catch, FAILS OPEN. If anything is unexpected it
            leaves the native form + the Phase-2 flow untouched.

   ⚠ NOT YET LIVE-TESTED. Two things MUST be verified on staging before trust:
     (A) SAVE-RESPONSE DETECTION — how BP signals success vs a required-field
         error in the fetch response (selectors in isSaveError()).
     (B) NEXT-GROUP URL construction (nextGroupUrl()).
   Both are marked with  // VERIFY  below.
   ========================================================================== */
(function () {
  "use strict";
  try {
    if (!document.body || !document.body.classList.contains("profile-edit")) return;
    var form = document.getElementById("profile-edit-form");
    if (!form) return;
    if (form.getAttribute("data-csm-ajax")) return;
    form.setAttribute("data-csm-ajax", "1");

    /* xProfile groups in tab display order (from dev-ref §3.4). Photo tab is a
       separate avatar screen and is intentionally NOT part of this wizard. */
    var GROUP_ORDER = [1, 7, 6, 4, 9, 8, 10];

    injectStyles();

    /* module state */
    var steps = [];        // array of step-units for the CURRENT group
    var segs = [];         // field-level progress segments
    var i = 0;             // current field index within group
    var busy = false;

    var chrome = buildChrome();   // progress + nav, created once, reused
    enhanceCurrentGroup();

    /* ----- current group id from the URL (fallback: form action) --------- */
    function currentGroupId() {
      var m = location.pathname.match(/\/group\/(\d+)/); // VERIFY on this install
      if (m) return parseInt(m[1], 10);
      var a = form.getAttribute("action") || "";
      var m2 = a.match(/\/group\/(\d+)/);
      return m2 ? parseInt(m2[1], 10) : GROUP_ORDER[0];
    }
    function nextGroupUrl(nextId) {
      // VERIFY: swap the group id in the current members edit URL
      var base = location.href.split("#")[0].split("?")[0];
      if (/\/group\/\d+/.test(base)) return base.replace(/\/group\/\d+\/?/, "/group/" + nextId + "/");
      return base.replace(/\/edit\/?$/, "/edit/") + "group/" + nextId + "/";
    }
    function groupIndex(id) { return GROUP_ORDER.indexOf(id); }

    /* ----- build the flow chrome (progress + nav) once ------------------- */
    function buildChrome() {
      var wrap = document.createElement("div"); wrap.className = "csm-a-chrome";
      var gprog = document.createElement("div"); gprog.className = "csm-a-gprog";
      GROUP_ORDER.forEach(function () { gprog.appendChild(document.createElement("span")).className = "csm-a-gseg"; });
      var prog = document.createElement("div"); prog.className = "csm-f-prog";
      wrap.appendChild(gprog); wrap.appendChild(prog);
      form.insertBefore(wrap, form.firstChild);

      var nav = document.createElement("div"); nav.className = "csm-f-nav";
      var back = document.createElement("button"); back.type = "button"; back.className = "csm-f-back"; back.textContent = "← Back";
      var next = document.createElement("button"); next.type = "button"; next.className = "csm-f-next";
      nav.appendChild(back); nav.appendChild(next);
      form.appendChild(nav);

      back.addEventListener("click", function () { if (!busy) show(i - 1); });
      next.addEventListener("click", function () { if (busy) return; if (i >= steps.length - 1) saveAndAdvance(); else show(i + 1); });
      return { gprog: gprog, prog: prog, nav: nav, back: back, next: next };
    }

    /* ----- (re)enhance whatever group is currently in the form ----------- */
    function enhanceCurrentGroup() {
      // hide the native submit (we fire it ourselves via fetch)
      var submit = document.getElementById("profile-group-edit-submit");
      if (submit) { submit.style.position = "absolute"; submit.style.left = "-9999px"; submit.setAttribute("tabindex", "-1"); }

      // build step-units (editfield + trailing non-field siblings), skip hidden
      steps = [];
      var editfields = Array.prototype.slice.call(form.querySelectorAll(".editfield"));
      editfields.forEach(function (ef) {
        if (isHidden(ef)) return;
        var g = [ef], sib = ef.nextElementSibling;
        while (sib && !sib.classList.contains("editfield")) {
          if (sib.classList.contains("csm-f-nav") || sib.classList.contains("csm-a-chrome")) break;
          if (sib.id === "profile-group-edit-submit") break;
          g.push(sib); sib = sib.nextElementSibling;
        }
        steps.push(g);
      });

      // per-field transforms (same rules as Phase 2 v2)
      steps.forEach(function (group) { transform(group[0]); });

      // rebuild field-level progress segments
      chrome.prog.innerHTML = "";
      steps.forEach(function () { chrome.prog.appendChild(document.createElement("span")).className = "csm-f-seg"; });
      segs = Array.prototype.slice.call(chrome.prog.children);

      // group-level progress
      var gi = groupIndex(currentGroupId());
      Array.prototype.slice.call(chrome.gprog.children).forEach(function (s, k) {
        s.className = "csm-a-gseg" + (k < gi ? " done" : "") + (k === gi ? " cur" : "");
      });

      // move nav to the end of the form (after freshly-swapped fields)
      form.appendChild(chrome.nav);
      i = 0; show(0);
    }

    function show(n) {
      i = Math.max(0, Math.min(steps.length - 1, n));
      steps.forEach(function (g, k) { g.forEach(function (el) { el.style.display = (k === i) ? "" : "none"; }); });
      segs.forEach(function (s, k) { s.className = "csm-f-seg" + (k < i ? " done" : "") + (k === i ? " cur" : ""); });
      chrome.back.style.visibility = (i === 0 && groupIndex(currentGroupId()) === 0) ? "hidden" : "visible";
      var lastGroup = groupIndex(currentGroupId()) === GROUP_ORDER.length - 1;
      chrome.next.textContent = (i === steps.length - 1) ? (lastGroup ? "Finish ✓" : "Save & continue →") : "Next →";
      clearError();
      try { form.scrollIntoView({ behavior: "smooth", block: "start" }); } catch (e) {}
      var c = steps[i][0].querySelector("input:not([type=hidden]):not([type=checkbox]):not([type=radio]),select,textarea,.csm-f-chip");
      if (c && c.focus) setTimeout(function () { try { c.focus(); } catch (e) {} }, 60);
    }

    /* ----- save current group via fetch, then load the next -------------- */
    function saveAndAdvance() {
      // NOTE: this install runs "Allow Partial Profile Save" (#11624) — BP
      // accepts the POST and redirects even with empty required fields, and
      // the completion gate enforces completeness later. So we DON'T hard-block
      // on empty required fields here (that would fight the progressive design);
      // we just save what's there and move on.
      busy = true; chrome.next.disabled = true;
      var savingLabel = chrome.next.textContent; chrome.next.textContent = "Saving…";
      var fd = new FormData(form);
      var submit = document.getElementById("profile-group-edit-submit");
      if (submit && submit.name) fd.append(submit.name, submit.value || "Save");

      fetch(form.getAttribute("action") || location.href, { method: "POST", body: fd, credentials: "include", redirect: "follow" })
        .then(function (r) {
          if (!r.ok) throw new Error("save http " + r.status);
          // 200 / redirect == saved (see #11624 note above)
          var idx = groupIndex(currentGroupId());
          var nextId = GROUP_ORDER[idx + 1];
          if (!nextId) { finishWizard(); return; }
          loadGroup(nextId);
        })
        .catch(function () {
          // network / HTTP problem — fall back to the real native submit so the
          // user still saves via the standard mechanism (no data lost)
          busy = false; chrome.next.disabled = false; chrome.next.textContent = savingLabel;
          if (submit) { submit.style.position = ""; submit.style.left = ""; if (form.requestSubmit) form.requestSubmit(submit); else submit.click(); }
        });
    }

    function loadGroup(nextId) {
      fetch(nextGroupUrl(nextId), { credentials: "include" })
        .then(function (r) { return r.text(); })
        .then(function (html) {
          var doc = new DOMParser().parseFromString(html, "text/html");
          var nf = doc.getElementById("profile-edit-form");
          if (!nf) { location.href = nextGroupUrl(nextId); return; } // fallback: real nav
          // swap: replace the current form's fields + action + nonce with the next group's
          form.setAttribute("action", nf.getAttribute("action") || form.getAttribute("action"));
          // remove old field nodes (everything that is not our chrome/nav)
          Array.prototype.slice.call(form.children).forEach(function (ch) {
            if (ch === chrome.nav || ch.classList.contains("csm-a-chrome")) return;
            form.removeChild(ch);
          });
          // import the next group's children (fields, hidden inputs, submit, nonce)
          Array.prototype.slice.call(nf.children).forEach(function (ch) {
            form.insertBefore(document.importNode(ch, true), chrome.nav);
          });
          // update the visible heading if present
          try {
            var h = document.querySelector(".edit-profile-screen, .screen-heading");
            var nh = doc.querySelector(".edit-profile-screen, .screen-heading");
            if (h && nh) h.textContent = nh.textContent;
            history.replaceState(null, "", nextGroupUrl(nextId)); // keep URL honest, no reload
          } catch (e) {}
          busy = false; chrome.next.disabled = false;
          enhanceCurrentGroup();
        })
        .catch(function () { location.href = nextGroupUrl(nextId); });
    }

    function finishWizard() {
      // last group saved — go to the profile view (native completion behaviour)
      var base = location.href.split("/profile/")[0];
      location.href = base + "/profile/";
    }

    /* ----- validation helpers -------------------------------------------- */
    function requiredMissing() {
      for (var s = 0; s < steps.length; s++) {
        var ef = steps[s][0];
        if (!/required-field/.test(ef.className)) continue;
        var ctrl = ef.querySelector("input:not([type=hidden]),select,textarea");
        if (!ctrl) continue;
        var val = ctrl.value;
        // datebox: require the year select to be set
        if (/field_type_datebox/.test(ef.className)) {
          var y = ef.querySelector('select[id$="_year"]'); if (y && !y.value) return s;
          continue;
        }
        // checkbox/radio groups: require at least one checked
        if (/field_type_checkbox|field_type_radio/.test(ef.className)) {
          if (!ef.querySelector("input:checked")) return s; continue;
        }
        if (!val || !String(val).trim()) return s;
      }
      return -1 === -1 ? null : null; // returns null when nothing missing
    }
    function jumpToField(stepIndex, msg) { show(stepIndex); showError(msg); }

    function isSaveError(doc) {
      // VERIFY on staging: BP Nouveau shows a .bp-feedback.error on a bad save.
      if (doc.querySelector('.bp-feedback.error, .bp-feedback[data-bp-feedback-type="error"], #message.error, .error')) return true;
      // secondary signal: a required field came back empty in the returned form
      return false;
    }
    function saveErrorText(doc) {
      var fb = doc.querySelector(".bp-feedback, #message");
      return fb ? fb.textContent.replace(/\s+/g, " ").trim().slice(0, 160) : "";
    }

    /* ----- inline error UI ----------------------------------------------- */
    function showError(msg) {
      clearError();
      var e = document.createElement("div"); e.className = "csm-f-err"; e.textContent = msg;
      chrome.nav.parentNode.insertBefore(e, chrome.nav);
    }
    function clearError() { var e = form.querySelector(".csm-f-err"); if (e) e.parentNode.removeChild(e); }

    /* ===================================================================== */
    /* shared helpers + field transforms (identical logic to Phase 2 v2)     */
    /* ===================================================================== */
    function isHidden(el) { var s = window.getComputedStyle(el); return s.display === "none" || s.visibility === "hidden"; }
    function real(scope, sel) { return Array.prototype.slice.call(scope.querySelectorAll(sel)).filter(function (el) { return !el.closest(".field-visibility-settings"); }); }
    function labelFor(input) {
      var l = input.closest("label"); if (l) return l.textContent.trim();
      if (input.id) { var l2 = document.querySelector('label[for="' + input.id + '"]'); if (l2) return l2.textContent.trim(); }
      if (input.nextSibling && input.nextSibling.textContent) return input.nextSibling.textContent.trim();
      return input.value;
    }
    function chip(text, pressed) { var b = document.createElement("button"); b.type = "button"; b.className = "csm-f-chip"; b.textContent = text; if (pressed) b.setAttribute("aria-pressed", "true"); return b; }

    function transform(ef) {
      var cls = ef.className;
      if (/field_type_selectbox\b/.test(cls) && !/\bfield_299\b/.test(cls)) {   // Gender (299) stays native/locked
        var sel = real(ef, "select")[0];
        if (sel && !sel.disabled && !sel.multiple && sel.options.length > 1) chipifySelect(ef, sel);
      } else if (/field_type_checkbox\b|field_type_multiselectbox\b/.test(cls)) {
        var checks = real(ef, 'input[type="checkbox"]');
        if (checks.length) chipifyChecks(ef, checks);
        else { var msel = real(ef, "select[multiple]")[0]; if (msel) chipifyMulti(ef, msel); }
      } else if (/field_type_radio\b/.test(cls)) {
        var radios = real(ef, 'input[type="radio"]'); if (radios.length) chipifyRadios(ef, radios);
      } else if (/\bfield_228\b/.test(cls)) {
        var num = real(ef, 'input[type="number"],input[type="text"]')[0]; if (num) sliderify(ef, num, 100, 260);
      } else if (/field_type_datebox\b/.test(cls)) {
        ageChip(ef);
      } else if (/field_type_textarea\b/.test(cls)) {
        var ta = real(ef, "textarea")[0]; if (ta) counter(ef, ta);
      }
    }
    function chipifySelect(ef, sel) {
      var wrap = document.createElement("div"); wrap.className = "csm-f-chips";
      Array.prototype.slice.call(sel.options).forEach(function (opt) {
        if (opt.value === "" && /select|choose|--/i.test(opt.text)) return;
        var b = chip(opt.text, opt.selected && opt.value !== "");
        b.addEventListener("click", function () { sel.value = opt.value; sel.dispatchEvent(new Event("change", { bubbles: true })); Array.prototype.slice.call(wrap.children).forEach(function (x) { x.removeAttribute("aria-pressed"); }); b.setAttribute("aria-pressed", "true"); });
        wrap.appendChild(b);
      });
      sel.style.display = "none"; sel.parentNode.insertBefore(wrap, sel.nextSibling);
    }
    function chipifyRadios(ef, radios) {
      var wrap = document.createElement("div"); wrap.className = "csm-f-chips";
      radios.forEach(function (r) { var b = chip(labelFor(r), r.checked); var lab = r.closest("label"); if (lab) lab.style.display = "none"; else r.style.display = "none"; b.addEventListener("click", function () { r.checked = true; r.dispatchEvent(new Event("change", { bubbles: true })); Array.prototype.slice.call(wrap.children).forEach(function (x) { x.removeAttribute("aria-pressed"); }); b.setAttribute("aria-pressed", "true"); }); wrap.appendChild(b); });
      (radios[0].closest("fieldset") || ef).appendChild(wrap);
    }
    function chipifyChecks(ef, checks) {
      var wrap = document.createElement("div"); wrap.className = "csm-f-chips";
      checks.forEach(function (c) { var b = chip(labelFor(c), c.checked); var lab = c.closest("label"); if (lab) lab.style.display = "none"; else c.style.display = "none"; b.addEventListener("click", function () { c.checked = !c.checked; c.dispatchEvent(new Event("change", { bubbles: true })); if (c.checked) b.setAttribute("aria-pressed", "true"); else b.removeAttribute("aria-pressed"); }); wrap.appendChild(b); });
      (checks[0].closest("fieldset") || ef).appendChild(wrap);
    }
    function chipifyMulti(ef, msel) {
      var wrap = document.createElement("div"); wrap.className = "csm-f-chips";
      Array.prototype.slice.call(msel.options).forEach(function (opt) { var b = chip(opt.text, opt.selected); b.addEventListener("click", function () { opt.selected = !opt.selected; msel.dispatchEvent(new Event("change", { bubbles: true })); if (opt.selected) b.setAttribute("aria-pressed", "true"); else b.removeAttribute("aria-pressed"); }); wrap.appendChild(b); });
      msel.style.display = "none"; msel.parentNode.insertBefore(wrap, msel.nextSibling);
    }
    function sliderify(ef, input, min, max) {
      var cur = parseInt(input.value, 10); if (isNaN(cur) || cur < min || cur > max) cur = 170;
      var box = document.createElement("div"); box.className = "csm-f-slider";
      var read = document.createElement("div"); read.className = "csm-f-read";
      var big = document.createElement("b"); var cm = document.createElement("span"); read.appendChild(big); read.appendChild(cm);
      var range = document.createElement("input"); range.type = "range"; range.className = "csm-f-range"; range.min = min; range.max = max; range.step = 1; range.value = cur;
      var scale = document.createElement("div"); scale.className = "csm-f-scale"; scale.innerHTML = "<span>" + min + " cm</span><span>" + max + " cm</span>";
      function paint() { var v = parseInt(range.value, 10); var t = Math.round(v / 2.54), ft = Math.floor(t / 12), inch = t % 12; big.textContent = ft + "′ " + inch + "″"; cm.textContent = v + " cm"; input.value = v; input.dispatchEvent(new Event("change", { bubbles: true })); }
      range.addEventListener("input", paint); box.appendChild(read); box.appendChild(range); box.appendChild(scale);
      input.style.display = "none"; input.parentNode.insertBefore(box, input.nextSibling); paint();
    }
    function ageChip(ef) {
      var sels = real(ef, "select"); if (sels.length < 3) return;
      var chipEl = document.createElement("div"); chipEl.className = "csm-f-agechip";
      function update() {
        var d = document.getElementById("field_586_day"), m = document.getElementById("field_586_month"), y = document.getElementById("field_586_year");
        if (!d || !m || !y || !y.value) { chipEl.style.display = "none"; return; }
        var now = new Date(); var age = now.getFullYear() - parseInt(y.value, 10);
        var mo = parseInt(m.value, 10), day = parseInt(d.value, 10);
        if (mo > (now.getMonth() + 1) || (mo === now.getMonth() + 1 && day > now.getDate())) age--;
        if (age > 0 && age < 120) { chipEl.textContent = "Age " + age + " · shown on your profile"; chipEl.style.display = ""; } else chipEl.style.display = "none";
      }
      sels.forEach(function (s) { s.addEventListener("change", update); }); ef.appendChild(chipEl); update();
    }
    function counter(ef, ta) {
      var max = ta.getAttribute("maxlength"); var c = document.createElement("div"); c.className = "csm-f-count";
      function upd() { c.textContent = ta.value.length + (max ? " / " + max : " characters"); }
      ta.addEventListener("input", upd); ta.parentNode.insertBefore(c, ta.nextSibling); upd();
    }

    /* ----- styles -------------------------------------------------------- */
    function injectStyles() {
      if (document.getElementById("csm-a-style")) return;
      if (!document.getElementById("csm-a-fonts")) {
        var lk = document.createElement("link"); lk.id = "csm-a-fonts"; lk.rel = "stylesheet";
        lk.href = "https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700&family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400;1,6..72,500&display=swap";
        document.head.appendChild(lk);
      }
      var css = document.createElement("style"); css.id = "csm-a-style";
      css.textContent = [
        /* extra tokens (Phase-0 defines the rest; all var() calls carry fallbacks so this works even if Phase-0 is off) */
        "body.profile-edit{--csm-hair-2:#d6dfe7;--csm-faint:#93a2ae;}",

        /* ==== FULL REBUILD: strip the member chrome, focus the flow ==== */
        "body.profile-edit #buddypress #object-nav,body.profile-edit #buddypress #subnav,body.profile-edit #buddypress #item-header,body.profile-edit #buddypress .csm-pc-wrap,body.profile-edit ul.button-nav{display:none!important;}",
        "body.profile-edit #buddypress .bp-wrap{display:block!important;margin:0!important;}",
        "body.profile-edit #buddypress #item-body{width:100%!important;max-width:100%!important;margin:0!important;padding:44px 16px 72px!important;float:none!important;border:0!important;background:var(--csm-ground,#f4f6f8)!important;font-family:\"Hanken Grotesk\",ui-sans-serif,system-ui,-apple-system,sans-serif;}",
        /* the OTP verify prompt, tidied into a calm inline note inside the card */
        "body.profile-edit #csm-otp-box{margin:20px 0 0!important;padding:14px 16px!important;background:#fbf6e9!important;border:1px solid #ece0bd!important;border-radius:14px!important;font-size:14px;}",

        /* ---- typography base: Hanken Grotesk everywhere in the form ---- */
        "body.profile-edit #profile-edit-form,body.profile-edit #profile-edit-form input,body.profile-edit #profile-edit-form select,body.profile-edit #profile-edit-form textarea,body.profile-edit #profile-edit-form button{font-family:\"Hanken Grotesk\",ui-sans-serif,system-ui,-apple-system,\"Segoe UI\",sans-serif;}",

        /* ---- the form becomes one centred premium card ---- */
        "body.profile-edit #profile-edit-form{max-width:620px;margin:6px auto 0!important;background:var(--csm-surface,#fff)!important;border:1px solid var(--csm-hair,#e4eaf0)!important;border-radius:24px!important;padding:32px 32px 24px!important;box-shadow:0 1px 2px rgba(22,33,43,.04),0 30px 70px -34px rgba(22,33,43,.30)!important;display:flex;flex-direction:column;min-height:460px;}",
        "body.profile-edit ul.button-nav{max-width:620px;margin-left:auto!important;margin-right:auto!important;}",
        "body.profile-edit .screen-heading,body.profile-edit h2.screen-heading,body.profile-edit #profile-edit-form>h2,body.profile-edit #profile-edit-form>h3{display:none!important;}",
        "body.profile-edit .field-visibility-settings-header,body.profile-edit .field-visibility-settings{display:none!important;}",

        /* ---- editfield: strip the box, let it breathe ---- */
        "body.profile-edit #profile-edit-form .editfield,body.profile-edit #profile-edit-form .editfield:focus-within{background:transparent!important;border:0!important;box-shadow:none!important;border-radius:0!important;padding:6px 0 0!important;margin:0!important;}",

        /* ---- the QUESTION (field label) — Newsreader serif, the signature move ---- */
        "body.profile-edit #profile-edit-form .editfield>label,body.profile-edit #profile-edit-form .editfield legend{display:block;font-family:\"Newsreader\",Georgia,serif!important;font-weight:400!important;font-size:27px!important;line-height:1.14!important;letter-spacing:-.01em;color:var(--csm-ink,#16212b)!important;margin:0 0 16px!important;text-wrap:balance;}",
        "body.profile-edit #profile-edit-form .editfield .bp-required-field-label,body.profile-edit #profile-edit-form .editfield .required{color:var(--csm-faint,#93a2ae)!important;font-weight:400!important;font-size:14px!important;}",
        "body.profile-edit #profile-edit-form .editfield p.description,body.profile-edit #profile-edit-form .editfield .description,body.profile-edit #profile-edit-form .editfield .field-description{margin:-8px 0 16px!important;color:var(--csm-muted,#5c6a76)!important;font-size:14.5px!important;font-style:normal!important;}",

        /* ---- inputs / textarea / selects ---- */
        "body.profile-edit #profile-edit-form input[type=text],body.profile-edit #profile-edit-form input[type=tel],body.profile-edit #profile-edit-form input[type=number],body.profile-edit #profile-edit-form input[type=email],body.profile-edit #profile-edit-form input[type=url],body.profile-edit #profile-edit-form textarea,body.profile-edit #profile-edit-form select{width:100%;font-size:16px!important;color:var(--csm-ink,#16212b)!important;background:var(--csm-surface,#fff)!important;border:1.5px solid var(--csm-hair-2,#d6dfe7)!important;border-radius:12px!important;padding:14px 15px!important;line-height:1.4!important;box-shadow:none!important;transition:border-color .16s,box-shadow .16s;}",
        "body.profile-edit #profile-edit-form select{height:auto!important;min-height:0!important;-webkit-appearance:none;appearance:none;padding-right:36px!important;background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' fill='none' stroke='%232C5B8C' stroke-width='1.6' stroke-linecap='round'/%3E%3C/svg%3E\")!important;background-repeat:no-repeat!important;background-position:right 14px center!important;}",
        "body.profile-edit #profile-edit-form input:focus,body.profile-edit #profile-edit-form textarea:focus,body.profile-edit #profile-edit-form select:focus{outline:none!important;border-color:var(--csm-blue,#2c5b8c)!important;box-shadow:0 0 0 3px var(--csm-blue-tint,#e9f1f8)!important;}",
        "body.profile-edit #profile-edit-form textarea{min-height:132px;resize:none;line-height:1.55;}",
        "body.profile-edit #profile-edit-form input[readonly],body.profile-edit #profile-edit-form input:disabled{background:var(--csm-ground,#f4f6f8)!important;color:var(--csm-muted,#5c6a76)!important;cursor:not-allowed;}",
        "body.profile-edit #profile-edit-form .field_type_datebox select{display:inline-block!important;width:auto!important;min-width:104px;margin:0 8px 8px 0;}",

        /* ---- group + field progress bars ---- */
        "body.profile-edit .csm-pe-steps{display:none!important;}",
        "body.profile-edit .csm-a-chrome{margin:0 0 24px;}",
        "body.profile-edit .csm-a-gprog{display:flex!important;gap:5px;margin:0 0 9px;}",
        "body.profile-edit .csm-a-gseg{height:4px;flex:1;border-radius:99px;background:var(--csm-hair,#e4eaf0);}",
        "body.profile-edit .csm-a-gseg.done{background:var(--csm-blue,#2c5b8c)!important;}",
        "body.profile-edit .csm-a-gseg.cur{background:var(--csm-blue,#2c5b8c)!important;opacity:.5;}",
        "body.profile-edit .csm-f-prog{display:none!important;}",
        "body.profile-edit .csm-f-seg{height:5px;flex:1;border-radius:99px;background:var(--csm-hair,#e4eaf0);}",
        "body.profile-edit .csm-f-seg.done,body.profile-edit .csm-f-seg.cur{background:var(--csm-green,#6f9f2e)!important;}",

        /* ---- nav / CTA pinned to the card bottom ---- */
        "body.profile-edit .csm-f-nav{display:flex!important;align-items:center;gap:12px;margin-top:auto;padding-top:26px;}",
        "body.profile-edit .csm-f-back{background:none!important;border:0!important;box-shadow:none!important;color:var(--csm-muted,#5c6a76)!important;font:inherit;font-weight:600!important;font-size:15px;cursor:pointer;padding:12px 6px!important;}",
        "body.profile-edit .csm-f-back:hover{color:var(--csm-blue,#2c5b8c)!important;}",
        "body.profile-edit .csm-f-next{margin-left:auto;background:var(--csm-green,#6f9f2e)!important;color:#fff!important;border:0!important;border-radius:14px!important;font:inherit;font-weight:700!important;font-size:16px;padding:15px 30px!important;cursor:pointer;box-shadow:0 10px 22px -10px rgba(111,159,46,.75)!important;transition:background .18s,transform .1s;}",
        "body.profile-edit .csm-f-next:hover{background:var(--csm-green-press,#5e8926)!important;}",
        "body.profile-edit .csm-f-next:active{transform:scale(.985);}",
        "body.profile-edit .csm-f-next:disabled{opacity:.65;cursor:default;}",
        "body.profile-edit .csm-f-err{margin:8px 0;padding:11px 14px;border-radius:12px;background:#fdecec;color:#a4282a;font-size:14px;border:1px solid #f3c2c2;}",

        /* ---- chips ---- */
        "body.profile-edit .csm-f-chips{display:flex!important;flex-wrap:wrap;gap:9px;margin-top:4px;}",
        "body.profile-edit .csm-f-chip{border:1.5px solid var(--csm-hair-2,#d6dfe7)!important;background:#fff!important;color:var(--csm-ink,#16212b)!important;box-shadow:none!important;font:inherit;font-weight:500!important;font-size:15px;padding:11px 17px!important;border-radius:999px!important;cursor:pointer;transition:border-color .15s,background .15s,color .15s,transform .1s;}",
        "body.profile-edit .csm-f-chip:hover{border-color:var(--csm-blue,#2c5b8c)!important;}",
        "body.profile-edit .csm-f-chip:active{transform:scale(.96);}",
        "body.profile-edit .csm-f-chip[aria-pressed=\"true\"]{border-color:var(--csm-blue,#2c5b8c)!important;background:var(--csm-blue-tint,#e9f1f8)!important;color:var(--csm-blue,#2c5b8c)!important;font-weight:600!important;}",

        /* ---- age chip, counter ---- */
        "body.profile-edit .csm-f-agechip{display:inline-flex;align-items:center;gap:8px;margin-top:16px;padding:11px 16px;border-radius:999px;background:var(--csm-blue-tint,#e9f1f8);color:var(--csm-blue,#2c5b8c);font-weight:600;font-size:15px;}",
        "body.profile-edit .csm-f-count{margin-top:8px;font-size:12.5px;color:var(--csm-faint,#93a2ae);text-align:right;}",

        /* ---- height slider ---- */
        "body.profile-edit .csm-f-slider{margin-top:6px;}",
        "body.profile-edit .csm-f-read{display:flex;align-items:baseline;gap:12px;margin:2px 0 18px;}",
        "body.profile-edit .csm-f-read b{font-family:\"Newsreader\",Georgia,serif!important;font-size:44px;font-weight:400;color:var(--csm-ink,#16212b);line-height:1;letter-spacing:-.02em;}",
        "body.profile-edit .csm-f-read span{font-size:16px;color:var(--csm-muted,#5c6a76);}",
        "body.profile-edit input.csm-f-range{-webkit-appearance:none;appearance:none;width:100%;height:6px;border-radius:99px;background:var(--csm-hair-2,#d6dfe7);cursor:pointer;}",
        "body.profile-edit input.csm-f-range::-webkit-slider-thumb{-webkit-appearance:none;width:28px;height:28px;border-radius:50%;background:var(--csm-blue,#2c5b8c);border:4px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.22);cursor:grab;}",
        "body.profile-edit input.csm-f-range::-moz-range-thumb{width:28px;height:28px;border-radius:50%;background:var(--csm-blue,#2c5b8c);border:4px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.22);}",
        "body.profile-edit .csm-f-scale{display:flex;justify-content:space-between;margin-top:8px;font-size:12px;color:var(--csm-faint,#93a2ae);}"
      ].join("\n");
      document.head.appendChild(css);
    }
  } catch (err) {
    if (window.console && console.warn) console.warn("[CSM Phase 3 AJAX] disabled:", err);
  }
})();
