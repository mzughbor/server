// Falcon of Codes — subdomain reservation, theme pick, Blesta order handoff
(function () {
  const nav = document.querySelector(".nav");
  const navToggle = document.querySelector(".nav-toggle");
  const header = document.querySelector(".header");

  const API_BASE = "/api/subdomain";
  const CATALOG_API = "/api/themes/catalog.php";
  const PACKAGES_URL = "./packages.json";
  const ORDER_ROOT = "https://falcon.codes/portal/order";
  const SLUG_RE = /^[a-z0-9](?:[a-z0-9-]{1,30}[a-z0-9])$/;
  const SS = { sub: "falcon_subdomain", theme: "falcon_theme", token: "falcon_token" };

  /** @type {{ order_form: string, group_id: number, config_options: {subdomain:number,theme:number}, packages: Record<string,{pricing_id:number,price:number,name:string}> }} */
  let pkgConfig = {
    order_form: "trial",
    group_id: 2,
    config_options: { subdomain: 67, theme: 68 },
    packages: {
      trial: { pricing_id: 4, price: 0, name: "Free Trial" },
      starter: { pricing_id: 5, price: 5, name: "Starter" },
      maker: { pricing_id: 6, price: 9, name: "Maker" },
      pro: { pricing_id: 7, price: 13, name: "Pro" },
    },
  };

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

  async function loadPackages() {
    try {
      const r = await fetch(PACKAGES_URL, { headers: { Accept: "application/json" } });
      if (r.ok) pkgConfig = await r.json();
    } catch (e) { /* defaults above */ }
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

  /** Direct-to-config URL — skips package selection; auto=1 triggers cart add on config page. */
  function buildOrderUrl(planKey) {
    planKey = planKey || "trial";
    const pkg = pkgConfig.packages[planKey] || pkgConfig.packages.trial;
    const sub = store.get(SS.sub);
    const theme = store.get(SS.theme);
    const token = store.get(SS.token);
    const qs = new URLSearchParams();
    qs.set("group_id", String(pkgConfig.group_id));
    qs.set("pricing_id", String(pkg.pricing_id));
    if (sub) qs.set("subdomain", sub);
    if (theme) qs.set("theme", theme);
    if (token) qs.set("token", token);
    if (sub && theme) qs.set("auto", "1");
    return `${ORDER_ROOT}/config/index/${pkgConfig.order_form}/?${qs}`;
  }

  function updateCtaState() {
    const sub = store.get(SS.sub);
    const theme = store.get(SS.theme);
    const themeLabel = document.querySelector(".theme-card.is-selected")?.dataset.label || "";

    document.querySelectorAll("[data-order-cta]").forEach((el) => {
      el.href = buildOrderUrl(el.dataset.plan || "trial");
      const label = el.querySelector("[data-cta-label]") || el;
      if (sub && theme) {
        label.textContent = el.dataset.plan
          ? `Get ${pkgConfig.packages[el.dataset.plan]?.name || "Plan"} →`
          : `Launch ${sub}.falcon.codes →`;
        el.title = `Theme: ${themeLabel || theme}`;
      } else if (sub) {
        label.textContent = el.dataset.plan ? label.textContent : "Pick a theme below →";
        el.title = `${sub}.falcon.codes reserved — choose a theme next`;
      } else {
        label.textContent = el.dataset.plan
          ? label.textContent
          : (el.classList.contains("btn-nav") ? "Start free" : "Start Free Trial");
        el.title = "Reserve a subdomain, pick a theme, then launch";
      }
    });

    const bar = document.getElementById("launchCtaBar");
    const subEl = document.getElementById("launchCtaSub");
    const themeEl = document.getElementById("launchCtaTheme");
    const showBar = Boolean(sub);
    const useFixedBar = showBar && window.matchMedia("(max-width: 768px)").matches;

    if (bar && subEl && themeEl) {
      bar.hidden = !showBar;
      if (showBar) {
        subEl.textContent = `${sub}.falcon.codes`;
        themeEl.textContent = theme ? (themeLabel || theme) : "pick one below ↑";
      }
      bar.classList.toggle("is-fixed", useFixedBar);
    }
    document.body.classList.toggle("has-launch-bar", useFixedBar);
  }

  function goToOrder(opts) {
    opts = opts || {};
    const plan = opts.plan || "trial";
    const sub = store.get(SS.sub);
    const token = store.get(SS.token);
    const theme = store.get(SS.theme);

    if (!sub || !token) {
      document.getElementById("top")?.scrollIntoView({ behavior: "smooth", block: "start" });
      document.getElementById("subInput")?.focus();
      setSubMsg("Enter a subdomain and click Check first.", "err");
      return;
    }
    if (!theme && opts.requireTheme !== false) {
      document.getElementById("themes")?.scrollIntoView({ behavior: "smooth", block: "start" });
      setSubMsg(`Reserved ${sub}.falcon.codes ✓ — now pick a theme below.`, "ok");
      return;
    }

    if (token && sub) apiBridge(token, sub, theme);
    window.location.href = buildOrderUrl(plan);
  }

  // ---- Subdomain picker (hero) -------------------------------------------
  const subInput = document.getElementById("subInput");
  const subClaimBtn = document.getElementById("subClaimBtn");
  const subMsg = document.getElementById("subMsg");

  function setSubMsg(text, kind) {
    if (!subMsg) return;
    subMsg.textContent = text;
    subMsg.className = "sub-msg" + (kind ? " sub-msg-" + kind : "");
  }

  function resetSubBtn() {
    if (!subClaimBtn) return;
    subClaimBtn.dataset.mode = "check";
    subClaimBtn.textContent = "Check";
    subClaimBtn.disabled = false;
  }

  function initSubPicker() {
    if (!subInput || !subClaimBtn) return;

    const saved = store.get(SS.sub);
    if (saved) {
      subInput.value = saved;
      if (store.get(SS.token)) {
        subClaimBtn.dataset.mode = "reserved";
        subClaimBtn.textContent = "Reserved ✓";
        setSubMsg(`${saved}.falcon.codes reserved.`, "ok");
      }
    }

    subInput.addEventListener("input", () => {
      const clean = sanitizeSlug(subInput.value);
      if (subInput.value !== clean) subInput.value = clean;
      if (subClaimBtn.dataset.mode === "reserve" || subClaimBtn.dataset.mode === "reserved") {
        resetSubBtn();
        store.set(SS.sub, "");
        store.set(SS.token, "");
        setSubMsg("", "");
        updateCtaState();
      }
    });

    subInput.addEventListener("keydown", (e) => {
      if (e.key === "Enter") { e.preventDefault(); subClaimBtn.click(); }
    });

    subClaimBtn.addEventListener("click", async () => {
      const name = sanitizeSlug(subInput.value);
      if (!name) { subInput.focus(); return; }
      if (!SLUG_RE.test(name)) {
        setSubMsg("Use 3–32 chars: letters, numbers, hyphens (not at the ends).", "err");
        return;
      }

      if (subClaimBtn.dataset.mode === "reserve") {
        subClaimBtn.disabled = true;
        subClaimBtn.textContent = "Reserving…";
        try {
          const res = await apiReserve(name);
          if (res.ok && res.available && res.reservation_token) {
            store.set(SS.sub, res.subdomain);
            store.set(SS.token, res.reservation_token);
            setSubMsg(`Reserved ${res.subdomain}.falcon.codes ✓ — pick a theme below.`, "ok");
            subClaimBtn.dataset.mode = "reserved";
            subClaimBtn.textContent = "Reserved ✓";
            updateCtaState();
            document.getElementById("themes")?.scrollIntoView({ behavior: "smooth", block: "start" });
          } else {
            setSubMsg(res.message || "That name just became unavailable. Try another.", "err");
            resetSubBtn();
          }
        } catch (e) {
          setSubMsg("Network error — please try again.", "err");
          resetSubBtn();
        }
        return;
      }

      if (subClaimBtn.dataset.mode === "reserved") {
        document.getElementById("themes")?.scrollIntoView({ behavior: "smooth", block: "start" });
        return;
      }

      subClaimBtn.disabled = true;
      subClaimBtn.textContent = "Checking…";
      try {
        const res = await apiCheck(name);
        if (res.ok && res.available) {
          setSubMsg(`${name}.falcon.codes is available!`, "ok");
          subClaimBtn.dataset.mode = "reserve";
          subClaimBtn.textContent = "Reserve →";
          subClaimBtn.disabled = false;
        } else {
          setSubMsg(res.message || "That subdomain isn't available. Try another.", "err");
          resetSubBtn();
        }
      } catch (e) {
        setSubMsg("Network error — please try again.", "err");
        resetSubBtn();
      }
    });
  }

  // Mobile nav
  navToggle?.addEventListener("click", () => {
    const open = nav.classList.toggle("nav-open");
    navToggle.setAttribute("aria-expanded", open);
    navToggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");
    if (open) header?.classList.remove("is-hidden");
  });

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
        lastY = y; ticking = false; return;
      }
      if (y <= TOP_OFFSET) header.classList.remove("is-hidden");
      else if (y > lastY + DELTA) header.classList.add("is-hidden");
      else if (y < lastY - DELTA) header.classList.remove("is-hidden");
      lastY = y; ticking = false;
    }
    window.addEventListener("scroll", () => {
      if (!ticking) { requestAnimationFrame(updateHeader); ticking = true; }
    }, { passive: true });
  })();

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

  // ---- Themes ------------------------------------------------------------
  const themeGrid = document.getElementById("themeGrid");
  const themeCatalogLoading = document.getElementById("themeCatalogLoading");
  const themeCatalogEmpty = document.getElementById("themeCatalogEmpty");
  const themeScrollHint = document.getElementById("themeScrollHint");
  const previewPanel = document.getElementById("themePreviewPanel");
  const previewFrame = document.getElementById("themePreviewFrame");
  const previewUrl = document.getElementById("themePreviewUrl");
  const previewName = document.getElementById("themePreviewName");
  let themeCards = [];

  function escapeHtml(s) {
    return String(s || "")
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
  }

  function catalogTag(name) {
    const n = (name || "").toUpperCase();
    if (n.includes("REST") || n.includes("CAFE")) return "RESTAURANT";
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
           <span class="theme-thumb-nav"></span><span class="theme-thumb-hero"></span>
           <span class="theme-thumb-line"></span><span class="theme-thumb-line short"></span>
         </div>`;

    return `
      <article class="theme-card" data-theme="${escapeHtml(slug)}" data-label="${escapeHtml(name)}"
               data-url="${escapeHtml(host)}" data-preview="${escapeHtml(preview)}">
        ${thumbHtml}
        <div class="theme-body">
          <span class="theme-tag">${escapeHtml(catalogTag(name))}</span>
          <h3>${escapeHtml(name)}</h3>
          <p>${escapeHtml(desc)}</p>
          <div class="theme-actions theme-actions-single">
            ${preview ? `<a class="btn btn-outline btn-sm theme-preview-link" href="${escapeHtml(preview)}" target="_blank" rel="noopener">Preview</a>` : ""}
            <button type="button" class="btn btn-primary btn-sm theme-choose-btn">Choose &amp; Launch →</button>
          </div>
        </div>
      </article>`;
  }

  function bindThemeCards() {
    themeCards = document.querySelectorAll(".theme-card");
    themeCards.forEach((card) => {
      card.querySelector(".theme-preview-link")?.addEventListener("click", (e) => e.stopPropagation());
      card.addEventListener("click", (e) => {
        if (e.target.closest(".theme-preview-link")) return;
        chooseTheme(card);
      });
      card.querySelector(".theme-choose-btn")?.addEventListener("click", (e) => {
        e.stopPropagation();
        chooseTheme(card, true);
      });
    });
    const savedTheme = store.get(SS.theme);
    if (savedTheme) {
      const match = document.querySelector(`.theme-card[data-theme="${CSS.escape(savedTheme)}"]`);
      if (match) chooseTheme(match, false);
    }
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
    } else showCatalogEmpty();
  }

  async function loadThemeCatalog() {
    try {
      const r = await fetch(CATALOG_API, { headers: { Accept: "application/json" } });
      const data = await r.json();
      const templates = (data?.ok && Array.isArray(data.templates)) ? data.templates : [];
      if (!themeGrid) return;
      if (templates.length === 0) { showCatalogEmpty(); return; }
      themeGrid.innerHTML = templates.map(renderThemeCard).join("");
      bindThemeCards();
      showCatalogLoaded(templates.length);
    } catch (e) { showCatalogEmpty(); }
  }

  function setThemePreview(card) {
    const theme = card.dataset.theme;
    const label = card.dataset.label;
    const url = card.dataset.url;
    const livePreview = card.dataset.preview;
    themeCards.forEach((c) => c.classList.remove("is-previewing"));
    card.classList.add("is-previewing");
    if (previewPanel) previewPanel.hidden = false;
    if (previewFrame) {
      previewFrame.className = `theme-preview-frame theme-frame-${theme}`;
      previewFrame.innerHTML = livePreview
        ? `<iframe src="${escapeHtml(livePreview)}" title="${escapeHtml(label)} preview" loading="lazy"></iframe>`
        : "";
    }
    if (previewUrl) previewUrl.textContent = url;
    if (previewName) previewName.textContent = label;
  }

  function chooseTheme(card, launchNow) {
    const label = card.dataset.label;
    const theme = card.dataset.theme;
    themeCards.forEach((c) => c.classList.remove("is-selected"));
    card.classList.add("is-selected");
    setThemePreview(card);
    store.set(SS.theme, theme);
    updateCtaState();
    if (launchNow) goToOrder({ requireTheme: false });
  }

  // Order CTAs
  document.querySelectorAll("[data-order-cta]").forEach((el) => {
    el.addEventListener("click", (e) => {
      e.preventDefault();
      goToOrder({ plan: el.dataset.plan || "trial", requireTheme: !el.dataset.plan });
    });
  });

  document.getElementById("launchCtaBtn")?.addEventListener("click", (e) => {
    e.preventDefault();
    goToOrder({ requireTheme: false });
  });

  const themeScroll = document.getElementById("themeScroll");
  const scrollPrev = document.querySelector(".theme-scroll-prev");
  const scrollNext = document.querySelector(".theme-scroll-next");

  function getThemeScrollStep() {
    const card = themeScroll?.querySelector(".theme-card");
    return card && themeScroll ? card.offsetWidth + 24 : 320;
  }

  function updateThemeScrollButtons() {
    if (!themeScroll || !scrollPrev || !scrollNext) return;
    const maxScroll = themeScroll.scrollWidth - themeScroll.clientWidth;
    scrollPrev.disabled = themeScroll.scrollLeft <= 4;
    scrollNext.disabled = maxScroll <= 4 || themeScroll.scrollLeft >= maxScroll - 4;
  }

  scrollPrev?.addEventListener("click", () => themeScroll?.scrollBy({ left: -getThemeScrollStep(), behavior: "smooth" }));
  scrollNext?.addEventListener("click", () => themeScroll?.scrollBy({ left: getThemeScrollStep(), behavior: "smooth" }));
  themeScroll?.addEventListener("scroll", updateThemeScrollButtons, { passive: true });
  window.addEventListener("resize", () => {
    updateThemeScrollButtons();
    updateCtaState();
  });

  // Boot
  loadPackages().then(() => {
    initSubPicker();
    updateCtaState();
    loadThemeCatalog();
  });
})();
