// Falcon of Codes — domain check, subdomain reservation & interactions
(function () {
  const form = document.getElementById("domainForm");
  const input = document.getElementById("domain");
  const result = document.getElementById("domainResult");
  const noDomainBtn = document.getElementById("noDomainBtn");
  const nav = document.querySelector(".nav");
  const navToggle = document.querySelector(".nav-toggle");
  const header = document.querySelector(".header");

  const domainRe = /^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

  // ---- Phase 4: subdomain reservation + order contract -------------------
  const API_BASE = "/api/subdomain";
  const CATALOG_API = "/api/themes/catalog.php";
  const ORDER_URL = "https://falcon.codes/portal/order/";
  const SLUG_RE = /^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/;
  const SS = { sub: "falcon_subdomain", theme: "falcon_theme", token: "falcon_token" };

  const store = {
    get: (k) => {
      try { return sessionStorage.getItem(k) || ""; } catch (e) { return ""; }
    },
    set: (k, v) => {
      try { sessionStorage.setItem(k, v); } catch (e) {}
    },
  };

  function sanitizeSlug(v) {
    return (v || "").toLowerCase().trim().replace(/[^a-z0-9-]/g, "");
  }

  async function apiCheck(name) {
    const r = await fetch(`${API_BASE}/check?name=${encodeURIComponent(name)}`, {
      headers: { Accept: "application/json" },
    });
    return r.json();
  }

  async function apiReserve(name) {
    const body = new URLSearchParams({ name });
    const r = await fetch(`${API_BASE}/reserve`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
      body: body.toString(),
    });
    return r.json();
  }

  // Best-effort carrier so the Blesta poller can map the completed order back
  // to {subdomain, theme}. Fire-and-forget; never blocks navigation.
  function apiBridge(token, subdomain, theme) {
    if (!token || !subdomain) return Promise.resolve();
    const body = new URLSearchParams({ token, subdomain, theme: theme || "default" });
    return fetch(`${API_BASE}/bridge`, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body.toString(),
      keepalive: true,
    }).catch(() => {});
  }

  function buildOrderUrl() {
    const sub = store.get(SS.sub);
    const theme = store.get(SS.theme);
    const token = store.get(SS.token);
    const qs = new URLSearchParams();
    if (sub) qs.set("subdomain", sub);
    if (theme) qs.set("theme", theme);
    if (token) qs.set("token", token);
    const q = qs.toString();
    return q ? `${ORDER_URL}?${q}` : ORDER_URL;
  }

  // Navigate to the Blesta order form, carrying the reservation + theme.
  // If nothing chosen yet, guide the user into the picker instead.
  function goToOrder(opts) {
    opts = opts || {};
    const sub = store.get(SS.sub);
    const token = store.get(SS.token);
    const theme = store.get(SS.theme);

    if (opts.requireChoice && (!sub || !token)) {
      // No subdomain reserved yet — send them to the picker.
      document.getElementById("top")?.scrollIntoView({ behavior: "smooth", block: "start" });
      showFreeFlow();
      setTimeout(() => document.getElementById("subInput")?.focus(), 400);
      return;
    }

    if (token && sub) apiBridge(token, sub, theme);
    window.location.href = buildOrderUrl();
  }

  function showOwnedFlow(domain) {
    result.hidden = false;
    result.className = "domain-result ok";
    result.innerHTML = `
      <div style="margin-bottom:8px;font-weight:600">
        Nice — let's verify <span style="color:var(--purple)">${domain}</span>
      </div>
      Add this record at your domain registrar, then click verify:
      <div style="margin-top:10px;display:grid;gap:6px;font-family:ui-monospace,monospace;font-size:12.5px">
        <div>TYPE  <code>CNAME</code></div>
        <div>HOST  <code>www</code></div>
        <div>VALUE <code>connect.falcon.codes</code></div>
      </div>
      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn btn-primary" href="https://falcon.codes/portal/order/">Continue to sign up →</a>
        <button type="button" class="btn btn-outline" onclick="document.getElementById('domainResult').hidden=true">Cancel</button>
      </div>
    `;
  }

  function showInvalid() {
    result.hidden = false;
    result.className = "domain-result";
    result.innerHTML = `That doesn't look like a valid domain. Try <code>yourbrand.com</code>.`;
  }

  function showFreeFlow() {
    result.hidden = false;
    result.className = "domain-result ok";
    result.innerHTML = `
      <div style="margin-bottom:8px;font-weight:600">No domain? No stress.</div>
      Pick a free subdomain — check it's available, then reserve it while you choose a theme.
      <div class="sub-picker" style="margin-top:12px;display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--border);border-radius:999px;padding:6px 6px 6px 14px">
        <input id="subInput" autocomplete="off" spellcheck="false" placeholder="mybrand" aria-label="Your subdomain" style="flex:1;background:transparent;border:0;outline:none;color:var(--text);font:500 15px var(--font);padding:8px 0;min-width:0" />
        <span style="color:var(--text-muted);font-size:14px">.falcon.codes</span>
        <button type="button" class="btn btn-primary" id="subClaimBtn" data-mode="check">Check</button>
      </div>
      <div id="subMsg" class="sub-msg" role="status" aria-live="polite" style="margin-top:10px;font-size:13px;min-height:18px"></div>
      <div style="margin-top:6px;font-size:12.5px;color:var(--text-muted)">
        Free trial sites run for the trial period; a name is held for 45 minutes while you sign up.
      </div>
    `;
    setTimeout(initSubPicker, 30);
  }

  function initSubPicker() {
    const subInput = document.getElementById("subInput");
    const btn = document.getElementById("subClaimBtn");
    const msg = document.getElementById("subMsg");
    if (!subInput || !btn || !msg) return;
    subInput.focus();

    function setMsg(text, kind) {
      msg.textContent = text;
      msg.className = "sub-msg" + (kind ? " sub-msg-" + kind : "");
    }
    function resetToCheck() {
      btn.dataset.mode = "check";
      btn.textContent = "Check";
      btn.disabled = false;
    }

    subInput.addEventListener("input", () => {
      const clean = sanitizeSlug(subInput.value);
      if (subInput.value !== clean) subInput.value = clean;
      if (btn.dataset.mode === "reserve") {
        resetToCheck();
        setMsg("", "");
      }
    });

    subInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") { e.preventDefault(); btn.click(); }
    });

    btn.addEventListener("click", async () => {
      const name = sanitizeSlug(subInput.value);
      if (!name) { subInput.focus(); return; }
      if (!SLUG_RE.test(name)) {
        setMsg("Use 3–32 chars: letters, numbers, hyphens (not at the ends).", "err");
        return;
      }

      // Step 2: reserve.
      if (btn.dataset.mode === "reserve") {
        btn.disabled = true;
        btn.textContent = "Reserving…";
        try {
          const res = await apiReserve(name);
          if (res.ok && res.available && res.reservation_token) {
            store.set(SS.sub, res.subdomain);
            store.set(SS.token, res.reservation_token);
            setMsg(`Reserved ${res.subdomain}.falcon.codes ✓ — now pick a theme below.`, "ok");
            btn.textContent = "Reserved ✓";
            document.getElementById("themes")?.scrollIntoView({ behavior: "smooth", block: "start" });
          } else {
            setMsg(res.message || "That name just became unavailable. Try another.", "err");
            resetToCheck();
          }
        } catch (e) {
          setMsg("Network error — please try again.", "err");
          resetToCheck();
        }
        return;
      }

      // Step 1: check.
      btn.disabled = true;
      btn.textContent = "Checking…";
      try {
        const res = await apiCheck(name);
        if (res.ok && res.available) {
          setMsg(`${name}.falcon.codes is available!`, "ok");
          btn.dataset.mode = "reserve";
          btn.textContent = "Reserve & pick a theme →";
          btn.disabled = false;
        } else {
          setMsg(res.message || "That subdomain isn't available. Try another.", "err");
          resetToCheck();
        }
      } catch (e) {
        setMsg("Network error — please try again.", "err");
        resetToCheck();
      }
    });
  }

  form?.addEventListener("submit", (e) => {
    e.preventDefault();
    const val = input.value.trim().toLowerCase().replace(/^https?:\/\//, "").replace(/\/.*$/, "");
    if (!val) { input.focus(); return; }
    if (!domainRe.test(val)) { showInvalid(); return; }
    showOwnedFlow(val);
  });

  noDomainBtn?.addEventListener("click", showFreeFlow);

  // Mobile nav toggle
  navToggle?.addEventListener("click", () => {
    const open = nav.classList.toggle("nav-open");
    navToggle.setAttribute("aria-expanded", open);
    if (open) header?.classList.remove("is-hidden");
  });

  // Hide header on scroll down, show on scroll up
  (function initHeaderScroll() {
    if (!header) return;

    let lastY = window.scrollY;
    let ticking = false;
    const TOP_OFFSET = 72;
    const DELTA = 6;

    function updateHeader() {
      const y = window.scrollY;

      if (nav?.classList.contains("nav-open")) {
        header.classList.remove("is-hidden");
        lastY = y;
        ticking = false;
        return;
      }

      if (y <= TOP_OFFSET) {
        header.classList.remove("is-hidden");
      } else if (y > lastY + DELTA) {
        header.classList.add("is-hidden");
      } else if (y < lastY - DELTA) {
        header.classList.remove("is-hidden");
      }

      lastY = y;
      ticking = false;
    }

    window.addEventListener(
      "scroll",
      () => {
        if (!ticking) {
          requestAnimationFrame(updateHeader);
          ticking = true;
        }
      },
      { passive: true }
    );
  })();

  // Smooth scroll
  document.querySelectorAll('a[href^="#"]').forEach((a) => {
    a.addEventListener("click", (e) => {
      const id = a.getAttribute("href").slice(1);
      if (!id) return;
      const el = document.getElementById(id);
      if (el) {
        e.preventDefault();
        nav.classList.remove("nav-open");
        header?.classList.remove("is-hidden");
        el.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  });

  // Theme demos (catalog-driven)
  const themeGrid = document.getElementById("themeGrid");
  const themeCatalogLoading = document.getElementById("themeCatalogLoading");
  const themeCatalogEmpty = document.getElementById("themeCatalogEmpty");
  const themeScrollHint = document.getElementById("themeScrollHint");
  const previewPanel = document.getElementById("themePreviewPanel");
  const previewFrame = document.getElementById("themePreviewFrame");
  const previewUrl = document.getElementById("themePreviewUrl");
  const previewName = document.getElementById("themePreviewName");
  const selectedNote = document.getElementById("themeSelectedNote");
  const selectedName = document.getElementById("themeSelectedName");
  const launchLink = document.getElementById("launchLink");
  let themeCards = [];

  function escapeHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function catalogTag(name) {
    const n = (name || "").toUpperCase();
    if (n.includes("REST")) return "RESTAURANT";
    if (n.includes("CAFE")) return "RESTAURANT";
    return "TEMPLATE";
  }

  function renderThemeCard(t) {
    const slug = t.slug || "";
    const name = t.name || slug;
    const desc = t.description || "Professional website template ready to launch on your subdomain.";
    const preview = t.preview_url || (t.staging_domain ? `https://${t.staging_domain}` : "");
    const thumb = t.thumbnail_url || "";
    const host = preview ? preview.replace(/^https?:\/\//, "") : `${slug}-demo.falcon.codes`;
    const thumbHtml = thumb
      ? `<img class="theme-thumb-img" src="${escapeHtml(thumb)}" alt="" loading="lazy" />`
      : `<div class="theme-thumb theme-thumb-studio" aria-hidden="true">
           <span class="theme-thumb-nav"></span>
           <span class="theme-thumb-hero"></span>
           <span class="theme-thumb-line"></span>
           <span class="theme-thumb-line short"></span>
         </div>`;

    return `
      <article class="theme-card" data-theme="${escapeHtml(slug)}" data-label="${escapeHtml(name)}"
               data-url="${escapeHtml(host)}" data-preview="${escapeHtml(preview)}">
        ${thumbHtml}
        <div class="theme-body">
          <span class="theme-tag">${escapeHtml(catalogTag(name))}</span>
          <h3>${escapeHtml(name)}</h3>
          <p>${escapeHtml(desc)}</p>
          <div class="theme-actions">
            ${preview ? `<a class="btn btn-outline btn-sm theme-preview-link" href="${escapeHtml(preview)}" target="_blank" rel="noopener">Preview Demo</a>` : ""}
            <button type="button" class="btn btn-outline btn-sm theme-preview-btn">Quick Preview</button>
            <button type="button" class="btn btn-primary btn-sm theme-choose-btn">Choose This</button>
          </div>
        </div>
      </article>`;
  }

  function bindThemeCards() {
    themeCards = document.querySelectorAll(".theme-card");
    themeCards.forEach((card) => {
      card.querySelector(".theme-preview-btn")?.addEventListener("click", () => setThemePreview(card));
      card.querySelector(".theme-choose-btn")?.addEventListener("click", () => chooseTheme(card));
    });
    updateThemeScrollButtons();
  }

  function showCatalogEmpty() {
    themeCatalogLoading?.setAttribute("hidden", "");
    themeCatalogEmpty?.removeAttribute("hidden");
    themeScrollHint?.setAttribute("hidden", "");
    document.querySelector(".theme-carousel")?.setAttribute("hidden", "");
  }

  function showCatalogLoaded(count) {
    themeCatalogLoading?.setAttribute("hidden", "");
    themeCatalogEmpty?.setAttribute("hidden", "");
    if (count > 0) {
      themeScrollHint?.removeAttribute("hidden");
      document.querySelector(".theme-carousel")?.removeAttribute("hidden");
    } else {
      showCatalogEmpty();
    }
  }

  async function loadThemeCatalog() {
    try {
      const r = await fetch(CATALOG_API, { headers: { Accept: "application/json" } });
      const data = await r.json();
      const templates = (data && data.ok && Array.isArray(data.templates)) ? data.templates : [];
      if (!themeGrid) return;
      if (templates.length === 0) {
        showCatalogEmpty();
        return;
      }
      themeGrid.innerHTML = templates.map(renderThemeCard).join("");
      bindThemeCards();
      showCatalogLoaded(templates.length);
    } catch (e) {
      showCatalogEmpty();
    }
  }

  function setThemePreview(card) {
    const theme = card.dataset.theme;
    const label = card.dataset.label;
    const url = card.dataset.url;
    const livePreview = card.dataset.preview;

    themeCards.forEach((c) => c.classList.remove("is-previewing"));
    card.classList.add("is-previewing");

    previewPanel.hidden = false;
    previewFrame.className = `theme-preview-frame theme-frame-${theme}`;
    if (livePreview && previewFrame) {
      previewFrame.innerHTML = `<iframe src="${escapeHtml(livePreview)}" title="${escapeHtml(label)} preview" loading="lazy"></iframe>`;
    }
    previewUrl.textContent = url;
    previewName.textContent = label;

    previewPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function chooseTheme(card) {
    const label = card.dataset.label;
    const theme = card.dataset.theme;

    themeCards.forEach((c) => c.classList.remove("is-selected"));
    card.classList.add("is-selected");
    setThemePreview(card);

    store.set(SS.theme, theme);

    selectedName.textContent = label;
    selectedNote.hidden = false;
    if (launchLink) launchLink.href = buildOrderUrl();
  }

  loadThemeCatalog();

  // "Continue to launch →" — carry subdomain+theme+token to the order form.
  launchLink?.addEventListener("click", (e) => {
    e.preventDefault();
    goToOrder({ requireChoice: true });
  });

  // Header "Start free" + hero "Start Free Trial" route through the flow.
  document.querySelectorAll("[data-order-cta]").forEach((el) => {
    el.addEventListener("click", (e) => {
      e.preventDefault();
      goToOrder({ requireChoice: true });
    });
  });

  // Theme carousel scroll
  const themeScroll = document.getElementById("themeScroll");
  const scrollPrev = document.querySelector(".theme-scroll-prev");
  const scrollNext = document.querySelector(".theme-scroll-next");

  function getThemeScrollStep() {
    const card = themeScroll?.querySelector(".theme-card");
    if (!card || !themeScroll) return 320;
    const gap = 24;
    return card.offsetWidth + gap;
  }

  function updateThemeScrollButtons() {
    if (!themeScroll || !scrollPrev || !scrollNext) return;
    const maxScroll = themeScroll.scrollWidth - themeScroll.clientWidth;
    scrollPrev.disabled = themeScroll.scrollLeft <= 4;
    scrollNext.disabled = maxScroll <= 4 || themeScroll.scrollLeft >= maxScroll - 4;
  }

  scrollPrev?.addEventListener("click", () => {
    themeScroll?.scrollBy({ left: -getThemeScrollStep(), behavior: "smooth" });
  });

  scrollNext?.addEventListener("click", () => {
    themeScroll?.scrollBy({ left: getThemeScrollStep(), behavior: "smooth" });
  });

  themeScroll?.addEventListener("scroll", updateThemeScrollButtons, { passive: true });
  window.addEventListener("resize", updateThemeScrollButtons);
  updateThemeScrollButtons();
})();
