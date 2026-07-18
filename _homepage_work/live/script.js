// Falcon of Codes — domain check & interactions
(function () {
  const form = document.getElementById("domainForm");
  const input = document.getElementById("domain");
  const result = document.getElementById("domainResult");
  const noDomainBtn = document.getElementById("noDomainBtn");
  const nav = document.querySelector(".nav");
  const navToggle = document.querySelector(".nav-toggle");
  const header = document.querySelector(".header");

  const domainRe = /^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

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
      Pick a free subdomain — yours for 24 hours to try the editor.
      <div style="margin-top:12px;display:flex;align-items:center;gap:6px;background:var(--bg);border:1px solid var(--border);border-radius:999px;padding:6px 6px 6px 14px">
        <input id="subInput" placeholder="mybrand" style="flex:1;background:transparent;border:0;outline:none;color:var(--text);font:500 15px var(--font);padding:8px 0;min-width:0" />
        <span style="color:var(--text-muted);font-size:14px">.falcon.codes</span>
        <button type="button" class="btn btn-primary" id="subClaimBtn">Pick a theme →</button>
      </div>
      <div style="margin-top:10px;font-size:12.5px;color:var(--text-muted)">
        Free trial sites are removed after 24h unless upgraded ($1/mo and up).
      </div>
    `;
    setTimeout(() => {
      document.getElementById("subInput")?.focus();
      document.getElementById("subClaimBtn")?.addEventListener("click", () => {
        document.getElementById("themes")?.scrollIntoView({ behavior: "smooth", block: "start" });
      });
    }, 30);
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

  // Theme demos
  const themeCards = document.querySelectorAll(".theme-card");
  const previewPanel = document.getElementById("themePreviewPanel");
  const previewFrame = document.getElementById("themePreviewFrame");
  const previewUrl = document.getElementById("themePreviewUrl");
  const previewName = document.getElementById("themePreviewName");
  const selectedNote = document.getElementById("themeSelectedNote");
  const selectedName = document.getElementById("themeSelectedName");

  function setThemePreview(card) {
    const theme = card.dataset.theme;
    const label = card.dataset.label;
    const url = card.dataset.url;

    themeCards.forEach((c) => c.classList.remove("is-previewing"));
    card.classList.add("is-previewing");

    previewPanel.hidden = false;
    previewFrame.className = `theme-preview-frame theme-frame-${theme}`;
    previewUrl.textContent = url;
    previewName.textContent = label;

    previewPanel.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function chooseTheme(card) {
    const label = card.dataset.label;

    themeCards.forEach((c) => c.classList.remove("is-selected"));
    card.classList.add("is-selected");
    setThemePreview(card);

    selectedName.textContent = label;
    selectedNote.hidden = false;
  }

  themeCards.forEach((card) => {
    card.querySelector(".theme-preview-btn")?.addEventListener("click", () => {
      setThemePreview(card);
    });
    card.querySelector(".theme-choose-btn")?.addEventListener("click", () => {
      chooseTheme(card);
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
    scrollNext.disabled = themeScroll.scrollLeft >= maxScroll - 4;
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
