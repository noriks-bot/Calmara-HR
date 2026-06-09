(() => {
  var s = document.createElement("script");
  s.src = "https://cdn.aimerce.ai/a.browser.shopify.umd.js";
  s.async = true;
  document.head.appendChild(s);

  if (window.item) {
    localStorage.setItem("__aim_viewed", JSON.stringify(window.item));
    try {
      const existing = localStorage.getItem("__aim_vi") || "[]";
      const viewed = JSON.parse(existing);
      const item = window.item;
      const index = viewed.findIndex((v) => v.ProductID === item.ProductID);
      if (index === -1) {
        viewed.push(item);
        localStorage.setItem("__aim_vi", JSON.stringify(viewed));
      }
    } catch (e) {
      console.warn(e);
    }
  }
})();
