(function () {
  const V = window.VASC;
  const l2 = document.getElementById("l2-list");
  const l3 = document.getElementById("l3-list");
  l2.innerHTML = V.orgs.filter((org) => org.level === "L2").map((org) => `<option value="${V.esc(org.short)}"></option>`).join("");
  l3.innerHTML = V.orgs.filter((org) => org.level === "L3").map((org) => `<option value="${V.esc(org.short)}"></option>`).join("");
  const form = document.getElementById("intake-form");
  const error = document.getElementById("intake-error");
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const button = form.querySelector("button");
    button.disabled = true;
    error.hidden = true;
    const body = Object.fromEntries(new FormData(form).entries());
    fetch("api/intake.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    }).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) throw new Error(data.error || "Mail was not accepted.");
      form.hidden = true;
      document.getElementById("intake-done").hidden = false;
    }).catch((err) => {
      button.disabled = false;
      error.textContent = err.message || "Mail was not accepted.";
      error.hidden = false;
    });
  });
})();
