(function () {
  "use strict";

  // Some browsers restore the previous scroll position on reload, which can
  // make the sticky nav overlap the hero text underneath it. Force a clean
  // top-of-page start every load.
  if ("scrollRestoration" in history) history.scrollRestoration = "manual";
  window.scrollTo(0, 0);

  const GRID_SIZE = 200;              // 200 x 200 = 40,000 sellable blocks
  const TOTAL_BLOCKS = GRID_SIZE * GRID_SIZE;
  // Each block represents a 5x5 patch of the 1,000,000-pixel wall (branding
  // only — there's no way to buy an individual pixel, the block is the
  // atomic sellable unit). Price reflects that: 25 pixels x Rs.85/pixel.
  const PRICE_PER_BLOCK_INR = 2125;
  // Shown to (and actually charged, via PayPal) visitors outside India
  // (25 pixels x ~$1/pixel, matching the original "$1 per pixel" branding
  // this site is patterned on). Must match PRICE_PER_BLOCK_USD_MINOR in
  // .private/config.php — this is only the display/running-total copy.
  const PRICE_PER_BLOCK_USD = 25;

  // Resolved after IP-based geolocation below. Defaults to India/INR, since
  // that's this site's primary market — a safe fallback if detection fails
  // or is blocked (ad blockers, privacy settings, offline preview, etc).
  let useUSD = false;

  const priceLabelEl = document.getElementById("priceLabel");

  const grid = document.getElementById("grid");
  const selectionBar = document.getElementById("selectionBar");
  const selCountEl = document.getElementById("selCount");
  const selPriceEl = document.getElementById("selPrice");
  const clearBtn = document.getElementById("clearSelection");
  const buyBtn = document.getElementById("buySelection");

  const modal = document.getElementById("checkoutModal");
  const closeBtn = modal.querySelector(".close");
  const checkoutForm = document.getElementById("checkoutForm");
  const checkoutSummary = document.getElementById("checkoutSummary");
  const checkoutError = document.getElementById("checkoutError");
  const checkoutSubmit = document.getElementById("checkoutSubmit");

  /** blockNumber (1..40000) -> { status: 'sold'|'pending', imgSrc, link, alt } */
  let blockData = {};
  /** Set of currently selected (not yet purchased) block numbers */
  const selected = new Set();

  function pricePerBlock() {
    return useUSD ? PRICE_PER_BLOCK_USD : PRICE_PER_BLOCK_INR;
  }

  function formatPrice(blockCount) {
    const amount = blockCount * pricePerBlock();
    return useUSD ? "$" + amount.toLocaleString("en-US") : "₹" + amount.toLocaleString("en-IN");
  }

  // ---------- Currency detection (display only) ----------
  // IP-based geolocation via a free, keyless API. Times out fast and falls
  // back to INR on any failure — never blocks the page for long.
  async function detectCurrency() {
    try {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 2500);
      const res = await fetch("https://ipwho.is/", { signal: controller.signal });
      clearTimeout(timeout);
      const data = await res.json();
      useUSD = data && data.success !== false && data.country_code !== "IN";
    } catch (err) {
      useUSD = false; // default to INR if detection fails
    }
    if (priceLabelEl) priceLabelEl.textContent = formatPrice(1);
  }

  function cellSelector(n) {
    return grid.querySelector('[data-n="' + n + '"]');
  }

  // ---------- Grid rendering ----------

  function buildGrid() {
    const fragment = document.createDocumentFragment();
    for (let n = 1; n <= TOTAL_BLOCKS; n++) {
      const info = blockData[n];
      if (info && info.status === "sold") {
        const link = document.createElement("a");
        link.className = "cell is-sold-wrap";
        link.dataset.n = String(n);
        link.href = info.link || "#";
        link.target = "_blank";
        link.rel = "noopener";
        link.title = info.caption || info.alt || "Block " + n;
        const img = document.createElement("img");
        img.src = info.imgSrc;
        img.alt = info.alt || "";
        img.loading = "lazy";
        link.appendChild(img);
        fragment.appendChild(link);
      } else {
        const cell = document.createElement("div");
        cell.className = "cell";
        cell.dataset.n = String(n);
        cell.tabIndex = 0;
        cell.setAttribute("role", "gridcell");
        if (info && info.status === "pending") {
          cell.classList.add("is-pending");
          cell.title = "Block " + n + " — reserved, checkout in progress";
        } else {
          cell.title = "Block " + n + " — " + formatPrice(1);
        }
        fragment.appendChild(cell);
      }
    }
    grid.innerHTML = "";
    grid.appendChild(fragment);
  }

  function isSelectable(n) {
    const info = blockData[n];
    return !info || info.status === undefined;
  }

  function toggleSelect(n) {
    if (!isSelectable(n)) return;
    const el = cellSelector(n);
    if (!el) return;
    if (selected.has(n)) {
      selected.delete(n);
      el.classList.remove("is-selected");
    } else {
      selected.add(n);
      el.classList.add("is-selected");
    }
    updateSelectionBar();
  }

  function clearSelection() {
    selected.forEach((n) => {
      const el = cellSelector(n);
      if (el) el.classList.remove("is-selected");
    });
    selected.clear();
    updateSelectionBar();
  }

  function updateSelectionBar() {
    const count = selected.size;
    selCountEl.textContent = String(count);
    selPriceEl.textContent = formatPrice(count);
    selectionBar.classList.toggle("is-visible", count > 0);
    buyBtn.disabled = count === 0;
    buyBtn.textContent = "Buy selected blocks";
  }

  // Event delegation: one listener instead of 40,000
  grid.addEventListener("click", (e) => {
    const target = e.target.closest(".cell");
    if (!target || target.classList.contains("is-sold-wrap")) return;
    const n = Number(target.dataset.n);
    if (!n) return;
    toggleSelect(n);
  });
  grid.addEventListener("keydown", (e) => {
    if (e.key !== "Enter" && e.key !== " ") return;
    const target = e.target.closest(".cell");
    if (!target || target.classList.contains("is-sold-wrap")) return;
    e.preventDefault();
    toggleSelect(Number(target.dataset.n));
  });

  clearBtn.addEventListener("click", clearSelection);

  // ---------- Checkout modal ----------

  let paypalSdkPromise = null;
  let currentOrderId = null;
  const paypalButtonContainer = document.getElementById("paypalButtonContainer");

  function loadPaypalSdk() {
    if (paypalSdkPromise) return paypalSdkPromise;
    paypalSdkPromise = fetch("api/public_config.php")
      .then((res) => res.json())
      .then((cfg) => new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = "https://www.paypal.com/sdk/js?client-id=" + encodeURIComponent(cfg.paypalClientId) + "&currency=USD";
        script.onload = resolve;
        script.onerror = () => reject(new Error("Could not load PayPal."));
        document.head.appendChild(script);
      }));
    return paypalSdkPromise;
  }

  function renderPaypalButtons() {
    paypalButtonContainer.innerHTML = "";
    window.paypal.Buttons({
      style: { layout: "vertical", label: "pay" },

      onClick: (data, actions) => {
        if (!checkoutForm.reportValidity()) return actions.reject();
        return actions.resolve();
      },

      createOrder: async () => {
        checkoutError.hidden = true;
        const formData = new FormData(checkoutForm);
        formData.set("blocks", JSON.stringify(Array.from(selected)));
        const res = await fetch("api/paypal/create_order.php", { method: "POST", body: formData });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || "Could not start checkout.");
        currentOrderId = data.orderId;
        return data.paypalOrderId;
      },

      onApprove: async (data) => {
        const body = new FormData();
        body.set("orderId", currentOrderId);
        body.set("paypalOrderId", data.orderID);
        const res = await fetch("api/paypal/capture_order.php", { method: "POST", body });
        const result = await res.json();
        if (!res.ok || result.error) throw new Error(result.error || "Payment could not be confirmed.");
        window.location.href = "/?success=1";
      },

      onError: (err) => {
        checkoutError.textContent = (err && err.message) || "PayPal checkout failed. Please try again.";
        checkoutError.hidden = false;
      },
    }).render(paypalButtonContainer);
  }

  function openModal() {
    checkoutError.hidden = true;
    checkoutError.textContent = "";
    checkoutForm.reset();
    const count = selected.size;
    checkoutSummary.textContent =
      count + " block" + (count === 1 ? "" : "s") + " selected · " + formatPrice(count) + " total";

    checkoutSubmit.hidden = useUSD;
    paypalButtonContainer.hidden = !useUSD;
    if (useUSD) {
      loadPaypalSdk().then(renderPaypalButtons).catch((err) => {
        checkoutError.textContent = err.message;
        checkoutError.hidden = false;
      });
    }

    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
  }

  function closeModal() {
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");
  }

  buyBtn.addEventListener("click", () => {
    if (selected.size === 0) return;
    openModal();
  });
  closeBtn.addEventListener("click", closeModal);
  modal.addEventListener("click", (e) => {
    if (e.target === modal) closeModal();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && modal.classList.contains("is-open")) closeModal();
  });

  // ---------- Razorpay checkout (India) ----------

  checkoutForm.addEventListener("submit", async (e) => {
    e.preventDefault();
    if (useUSD) return; // PayPal path is handled entirely by the PayPal Buttons callbacks above
    if (selected.size === 0) return;

    checkoutError.hidden = true;
    checkoutSubmit.disabled = true;
    checkoutSubmit.textContent = "Preparing checkout…";

    const formData = new FormData(checkoutForm);
    formData.set("blocks", JSON.stringify(Array.from(selected)));

    try {
      const res = await fetch("api/razorpay/create_order.php", { method: "POST", body: formData });
      const data = await res.json();
      if (!res.ok || data.error) throw new Error(data.error || "Something went wrong. Please try again.");

      const rzp = new Razorpay({
        key: data.razorpayKeyId,
        order_id: data.razorpayOrderId,
        amount: data.amount,
        currency: "INR",
        name: "Hall of Brands",
        description: "Pixel blocks — " + data.brand,
        prefill: { email: data.email },
        theme: { color: "#2a6ded" },
        handler: async (response) => {
          const verifyBody = new FormData();
          verifyBody.set("orderId", data.orderId);
          verifyBody.set("razorpay_order_id", response.razorpay_order_id);
          verifyBody.set("razorpay_payment_id", response.razorpay_payment_id);
          verifyBody.set("razorpay_signature", response.razorpay_signature);
          const verifyRes = await fetch("api/razorpay/verify_payment.php", { method: "POST", body: verifyBody });
          const verifyData = await verifyRes.json();
          if (!verifyRes.ok || verifyData.error) {
            checkoutError.textContent = verifyData.error || "Payment could not be confirmed.";
            checkoutError.hidden = false;
            return;
          }
          window.location.href = "/?success=1";
        },
        modal: {
          ondismiss: () => {
            checkoutSubmit.disabled = false;
            checkoutSubmit.textContent = "Continue to payment";
          },
        },
      });
      rzp.open();
      checkoutSubmit.disabled = false;
      checkoutSubmit.textContent = "Continue to payment";
    } catch (err) {
      checkoutError.textContent = err.message || "Couldn't start checkout. Please try again.";
      checkoutError.hidden = false;
      checkoutSubmit.disabled = false;
      checkoutSubmit.textContent = "Continue to payment";
    }
  });

  // ---------- Load real block state from the server ----------


  async function loadBlocks() {
    const blocksPromise = fetch("api/get_blocks.php", { cache: "no-store" })
      .then((res) => { if (!res.ok) throw new Error("bad response"); return res.json(); })
      .catch((err) => {
        console.warn("Could not load live block data, showing an empty grid.", err);
        return {};
      });

    const [data] = await Promise.all([blocksPromise, detectCurrency()]);
    blockData = data;
    buildGrid();
  }

  // ---------- Post-checkout return banner ----------

  function handleReturnParams() {
    const params = new URLSearchParams(window.location.search);
    if (params.get("success") === "1") {
      const note = document.createElement("div");
      note.className = "checkout-error";
      note.style.cssText =
        "max-width:640px;margin:16px auto;background:#e8f8ee;border-color:#bfe6cc;color:#1e6b3a;text-align:center;";
      note.textContent = "Payment received — your blocks will appear on the wall shortly.";
      document.querySelector(".brand-container").after(note);
    } else if (params.get("canceled") === "1") {
      const note = document.createElement("div");
      note.className = "checkout-error";
      note.style.cssText = "max-width:640px;margin:16px auto;text-align:center;";
      note.textContent = "Checkout was canceled — your blocks were not charged.";
      document.querySelector(".brand-container").after(note);
    }
  }

  document.getElementById("year").textContent = String(new Date().getFullYear());
  handleReturnParams();
  loadBlocks();
})();
