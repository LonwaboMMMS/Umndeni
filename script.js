(() => {
  "use strict";

  // Set a numeric price here when Umndeni's wholesale pricing is confirmed.
  // Keep null to show "Price on request" throughout the app.
  const CONFIG = {
    productId: "umndeni-petroleum-jelly",
    productName: "Umndeni Petroleum Jelly",
    unitLabel: "bulk box",
    unitPrice: null,
    supportEmail: "info@umndenihealth.com"
  };

  const STORAGE = {
    cart: "umndeni_shop_cart_v1",
    orders: "umndeni_shop_orders_v1",
    customer: "umndeni_shop_customer_v1"
  };

  const state = {
    page: "home",
    productQty: 1,
    cart: readStorage(STORAGE.cart, []),
    orders: readStorage(STORAGE.orders, []),
    customer: readStorage(STORAGE.customer, {}),
    orderFilter: "all",
    latestOrder: null,
    toastTimer: null
  };

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const money = (amount) => new Intl.NumberFormat("en-ZA", { style: "currency", currency: "ZAR" }).format(amount);
  const escapeHTML = (value = "") => String(value).replace(/[&<>"']/g, char => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[char]));
  const readStorage = (key, fallback) => {
    try {
      const value = JSON.parse(localStorage.getItem(key));
      return value ?? fallback;
    } catch (error) {
      return fallback;
    }
  };
  const saveStorage = (key, value) => {
    try { localStorage.setItem(key, JSON.stringify(value)); }
    catch (error) { showToast("Unable to save on this device. Check your browser storage settings."); }
  };
  const totalBoxes = () => state.cart.reduce((sum, item) => sum + item.quantity, 0);
  const orderTotal = (items) => CONFIG.unitPrice === null ? null : items.reduce((sum, item) => sum + item.quantity * CONFIG.unitPrice, 0);
  const formatDate = (date) => new Intl.DateTimeFormat("en-ZA", { day: "2-digit", month: "short", year: "numeric" }).format(new Date(date));
  const orderNumber = () => "UMD-" + Date.now().toString().slice(-6) + Math.floor(Math.random() * 9 + 1);
  const priceLabel = () => CONFIG.unitPrice === null ? "Price on request" : money(CONFIG.unitPrice);
  const currentInitial = () => (state.customer.fullName || "Guest").trim().charAt(0).toUpperCase() || "G";

  function showToast(message) {
    const toast = $("#toast");
    toast.textContent = message;
    toast.classList.add("show");
    clearTimeout(state.toastTimer);
    state.toastTimer = setTimeout(() => toast.classList.remove("show"), 2600);
  }

  function navigate(page) {
    if (!$("#page-" + page)) return;
    state.page = page;
    $$(".page-view").forEach(view => view.classList.toggle("active", view.id === "page-" + page));
    $$(".nav-link[data-page], .mobile-nav-link[data-page]").forEach(button => button.classList.toggle("active", button.dataset.page === page));
    const labels = { home: "Overview", shop: "Shop products", orders: "My orders", checkout: "Checkout", confirmation: "Order confirmation", account: "My account", help: "Help & support" };
    $("#pageCrumb").textContent = labels[page] || "Overview";
    $("#sidebar").classList.remove("mobile-open");
    $("#mobileOverlay").classList.remove("open");
    window.scrollTo({ top: 0, behavior: "smooth" });
    if (page === "orders") renderOrders();
    if (page === "checkout") renderCheckout();
    if (page === "account") renderAccount();
    renderDashboard();
  }

  function renderDashboard() {
    const pending = state.orders.filter(order => order.status === "Awaiting confirmation").length;
    $("#statTotalOrders").textContent = state.orders.length;
    $("#statPendingOrders").textContent = pending;
    $("#statCartItems").textContent = totalBoxes();
    $("#ordersNavCount").textContent = state.orders.length;
    $("#accountOrderCount").textContent = state.orders.length;
    $("#welcomeName").textContent = state.customer.fullName ? ", " + state.customer.fullName.split(" ")[0] : "";
    $("#cartBadge").textContent = totalBoxes();
    $("#mobileCartBadge").textContent = totalBoxes();
    $("#drawerItemCount").textContent = "(" + totalBoxes() + ")";
    $("#drawerOrderTotal").textContent = CONFIG.unitPrice === null ? "Price on request" : money(orderTotal(state.cart));
    $("#currentYear").textContent = new Date().getFullYear();
    $(".avatar-button").textContent = currentInitial();
    $(".price-request").forEach(element => { element.textContent = priceLabel(); });
    renderRecentOrders();
  }

  function renderRecentOrders() {
    const container = $("#recentOrders");
    if (!state.orders.length) {
      container.innerHTML = '<div class="empty-inline"><span class="empty-icon">▤</span><p>No orders yet.</p><small>Your recent orders will appear here.</small></div>';
      return;
    }
    container.innerHTML = state.orders.slice(0, 3).map(order => `
      <div class="recent-order-row">
        <div><strong class="order-ref">${escapeHTML(order.number)}</strong><small class="recent-date">${formatDate(order.createdAt)}</small></div>
        <span class="status-pill">${escapeHTML(order.status)}</span>
      </div>`).join("");
  }

  function renderCart() {
    const contents = $("#cartContents");
    const footer = $("#drawerFooter");
    if (!state.cart.length) {
      contents.innerHTML = '<div class="empty-state drawer-empty"><div class="empty-state-icon">▣</div><h3>Your cart is empty</h3><p>Add bulk boxes from the shop to get started.</p><button class="button button-primary" data-page="shop" id="emptyCartShop">Browse products</button></div>';
      footer.style.display = "none";
    } else {
      contents.innerHTML = state.cart.map(item => `
        <div class="cart-line-item">
          <div class="cart-line-art">U</div>
          <div class="cart-line-info">
            <strong>${escapeHTML(item.name)}</strong><small>Bulk box · ${priceLabel()} each</small>
            <div class="cart-line-controls">
              <button data-cart-action="decrease" aria-label="Decrease quantity">−</button><span>${item.quantity}</span><button data-cart-action="increase" aria-label="Increase quantity">＋</button>
              <button class="remove-item" data-cart-action="remove" aria-label="Remove item">Remove</button>
            </div>
          </div>
        </div>`).join("");
      footer.style.display = "block";
    }
    saveStorage(STORAGE.cart, state.cart);
    renderDashboard();
  }

  function openCart() {
    renderCart();
    $("#cartDrawer").classList.add("open");
    $("#drawerBackdrop").classList.add("open");
    $("#cartDrawer").setAttribute("aria-hidden", "false");
  }

  function closeCart() {
    $("#cartDrawer").classList.remove("open");
    $("#drawerBackdrop").classList.remove("open");
    $("#cartDrawer").setAttribute("aria-hidden", "true");
  }

  function addToCart(quantity) {
    const existing = state.cart.find(item => item.id === CONFIG.productId);
    if (existing) existing.quantity += quantity;
    else state.cart.push({ id: CONFIG.productId, name: CONFIG.productName, quantity });
    saveStorage(STORAGE.cart, state.cart);
    renderCart();
    showToast(quantity + (quantity === 1 ? " box added" : " boxes added") + " to your cart.");
  }

  function updateCart(action) {
    const item = state.cart.find(entry => entry.id === CONFIG.productId);
    if (!item) return;
    if (action === "increase") item.quantity += 1;
    if (action === "decrease") item.quantity -= 1;
    if (action === "remove" || item.quantity <= 0) state.cart = state.cart.filter(entry => entry.id !== CONFIG.productId);
    saveStorage(STORAGE.cart, state.cart);
    renderCart();
  }

  function renderCheckout() {
    if (!state.cart.length) {
      navigate("shop");
      showToast("Add at least one box before checkout.");
      return;
    }
    $("#checkoutItems").innerHTML = state.cart.map(item => `
      <div class="checkout-item"><div class="checkout-item-art">U</div><div class="checkout-item-info"><strong>${escapeHTML(item.name)}</strong><small>${item.quantity} bulk box(es)</small></div><span class="checkout-item-price">${CONFIG.unitPrice === null ? priceLabel() : money(item.quantity * CONFIG.unitPrice)}</span></div>`).join("");
    $("#checkoutBoxCount").textContent = totalBoxes() + (totalBoxes() === 1 ? " box" : " boxes");
    $("#checkoutOrderTotal").textContent = CONFIG.unitPrice === null ? "Price on request" : money(orderTotal(state.cart));
    const form = $("#checkoutForm");
    ["fullName", "email", "phone", "business", "address", "city", "province", "postalCode"].forEach(name => {
      if (form.elements[name] && state.customer[name]) form.elements[name].value = state.customer[name];
    });
  }

  function submitOrder(event) {
    event.preventDefault();
    if (!state.cart.length) return;
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const formData = new FormData(form);
    const customer = Object.fromEntries(formData.entries());
    state.customer = { ...state.customer, ...customer };
    saveStorage(STORAGE.customer, state.customer);
    const items = state.cart.map(item => ({ ...item }));
    const order = {
      number: orderNumber(),
      createdAt: new Date().toISOString(),
      status: "Awaiting confirmation",
      customer: { ...customer },
      items,
      boxes: items.reduce((sum, item) => sum + item.quantity, 0),
      total: orderTotal(items)
    };
    state.orders.unshift(order);
    state.latestOrder = order;
    state.cart = [];
    saveStorage(STORAGE.orders, state.orders);
    saveStorage(STORAGE.cart, state.cart);
    closeCart();
    $("#confirmationOrderNumber").textContent = order.number;
    navigate("confirmation");
    showToast("Your order request has been submitted.");
  }

  function renderOrders() {
    const orders = state.orderFilter === "all" ? state.orders : state.orders.filter(order => order.status === "Awaiting confirmation");
    $("#ordersCount").textContent = orders.length + (orders.length === 1 ? " order" : " orders");
    if (!orders.length) {
      $("#ordersList").innerHTML = '<div class="empty-state"><div class="empty-state-icon">▤</div><h3>' + (state.orders.length ? "No orders in this view" : "No orders just yet") + '</h3><p>' + (state.orders.length ? "Try viewing all orders." : "Once you submit an order, it will appear here.") + '</p><button class="button button-primary" data-page="shop">Browse products</button></div>';
      return;
    }
    $("#ordersList").innerHTML = orders.map(order => `
      <div class="order-row">
        <strong class="order-ref">${escapeHTML(order.number)}</strong>
        <span class="order-date">${formatDate(order.createdAt)}</span>
        <span class="order-items">${order.boxes} ${order.boxes === 1 ? "box" : "boxes"}</span>
        <span class="status-pill">${escapeHTML(order.status)}</span>
        <button class="order-open" data-order-id="${escapeHTML(order.number)}" aria-label="View order details">→</button>
      </div>`).join("");
  }

  function openOrderDetails(number) {
    const order = state.orders.find(entry => entry.number === number);
    if (!order) return;
    const customer = order.customer || {};
    $("#orderModalContent").innerHTML = `
      <span class="section-kicker">ORDER DETAILS</span>
      <h2 id="orderModalTitle">${escapeHTML(order.number)}</h2>
      <span class="status-pill">${escapeHTML(order.status)}</span>
      <div class="modal-detail-grid">
        <div class="modal-detail"><small>ORDER DATE</small><strong>${formatDate(order.createdAt)}</strong></div>
        <div class="modal-detail"><small>QUANTITY</small><strong>${order.boxes} bulk box(es)</strong></div>
        <div class="modal-detail"><small>CONTACT PERSON</small><strong>${escapeHTML(customer.fullName || "—")}</strong></div>
        <div class="modal-detail"><small>BUSINESS</small><strong>${escapeHTML(customer.business || "Not provided")}</strong></div>
        <div class="modal-detail"><small>EMAIL</small><strong>${escapeHTML(customer.email || "—")}</strong></div>
        <div class="modal-detail"><small>PHONE</small><strong>${escapeHTML(customer.phone || "—")}</strong></div>
      </div>
      <div class="modal-section-title">Items ordered</div>
      ${order.items.map(item => '<div class="summary-line"><span>' + escapeHTML(item.name) + ' × ' + item.quantity + '</span><strong>' + (order.total === null ? "Price on request" : money(order.total)) + '</strong></div>').join("")}
      <div class="modal-section-title">Delivery address</div>
      <div class="modal-notes">${escapeHTML([customer.address, customer.city, customer.province, customer.postalCode].filter(Boolean).join(", ") || "No delivery address provided.")}</div>
      ${customer.notes ? '<div class="modal-section-title">Order notes</div><div class="modal-notes">' + escapeHTML(customer.notes) + '</div>' : ""}
      <div class="summary-info" style="margin-top:16px"><span>i</span><p>This request is awaiting confirmation. Umndeni will contact you to confirm pricing and delivery.</p></div>`;
    $("#orderModalBackdrop").classList.add("open");
  }

  function closeOrderDetails() {
    $("#orderModalBackdrop").classList.remove("open");
  }

  function renderAccount() {
    const form = $("#accountForm");
    ["fullName", "email", "phone", "business"].forEach(name => {
      if (form.elements[name]) form.elements[name].value = state.customer[name] || "";
    });
    $("#accountDisplayName").textContent = state.customer.fullName || "Guest account";
    $("#accountAvatar").textContent = currentInitial();
    $("#accountOrderCount").textContent = state.orders.length;
  }

  function saveAccount(event) {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(event.currentTarget).entries());
    state.customer = { ...state.customer, ...values };
    saveStorage(STORAGE.customer, state.customer);
    renderAccount();
    renderDashboard();
    $("#accountFeedback").textContent = "Your details have been saved.";
    showToast("Account details saved.");
  }

  function bindEvents() {
    document.addEventListener("click", event => {
      const pageButton = event.target.closest("[data-page]");
      if (pageButton) {
        event.preventDefault();
        navigate(pageButton.dataset.page);
      }
      if (event.target.closest("#openCart") || event.target.closest("#mobileCart")) openCart();
      if (event.target.closest("#closeCart") || event.target.closest("#drawerBackdrop")) closeCart();
      if (event.target.closest("#goCheckout")) {
        closeCart();
        if (!state.cart.length) { navigate("shop"); showToast("Your cart is empty."); }
        else navigate("checkout");
      }
      if (event.target.closest("#productQtyMinus")) state.productQty = Math.max(1, state.productQty - 1);
      if (event.target.closest("#productQtyPlus")) state.productQty = Math.min(999, state.productQty + 1);
      if (event.target.closest("#productQtyMinus") || event.target.closest("#productQtyPlus")) $("#productQty").textContent = state.productQty;
      if (event.target.closest("#addToCart")) addToCart(state.productQty);
      const cartAction = event.target.closest("[data-cart-action]");
      if (cartAction) updateCart(cartAction.dataset.cartAction);
      if (event.target.closest("#backToCart")) openCart();
      const filter = event.target.closest("[data-filter]");
      if (filter) {
        state.orderFilter = filter.dataset.filter;
        $$(".order-filter").forEach(button => button.classList.toggle("active", button === filter));
        renderOrders();
      }
      const orderButton = event.target.closest("[data-order-id]");
      if (orderButton) openOrderDetails(orderButton.dataset.orderId);
      if (event.target.closest("#closeOrderModal")) closeOrderDetails();
      if (event.target.id === "orderModalBackdrop") closeOrderDetails();
      if (event.target.closest("#copyOrderNumber")) {
        const text = $("#confirmationOrderNumber").textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(() => showToast("Order number copied.")).catch(() => showToast(text));
        else showToast(text);
      }
      if (event.target.closest("#mobileMenu")) {
        $("#sidebar").classList.add("mobile-open");
        $("#mobileOverlay").classList.add("open");
      }
      if (event.target.closest("#mobileOverlay")) {
        $("#sidebar").classList.remove("mobile-open");
        $("#mobileOverlay").classList.remove("open");
      }
    });

    $("#checkoutForm").addEventListener("submit", submitOrder);
    $("#accountForm").addEventListener("submit", saveAccount);
    document.addEventListener("keydown", event => {
      if (event.key === "Escape") {
        closeCart();
        closeOrderDetails();
        $("#sidebar").classList.remove("mobile-open");
        $("#mobileOverlay").classList.remove("open");
      }
    });
  }

  function init() {
    $("#currentYear").textContent = new Date().getFullYear();
    $("#checkoutForm").elements.fullName.value = state.customer.fullName || "";
    renderDashboard();
    renderCart();
    bindEvents();
  }

  document.addEventListener("DOMContentLoaded", init);
})();