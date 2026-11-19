(function () {
  const V = window.VASC;
  const form = document.getElementById("feedback");
  const note = document.getElementById("feedback-note");
  if (!form) return;
  form.addEventListener("submit", (event) => {
    event.preventDefault();
    const message = form.message.value.trim();
    if (!message) {
      note.textContent = "Write a short comment.";
      return;
    }
    note.textContent = "Sending…";
    const button = form.querySelector("button[type=submit]");
    if (button) button.disabled = true;
    const page = (location.pathname.split("/").pop() || "feedback.html") + location.search;
    fetch("api/feedback.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: form.name.value.trim(),
        email: form.email.value.trim(),
        message: message,
        page: page,
        company: form.company.value
      })
    }).then(async (res) => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.ok === false) throw new Error(data.error || "The comment was not sent.");
      form.message.value = "";
      note.textContent = "Sent to DSG_DVCDS_PP_SA@service.forces.gc.ca.";
    }).catch((error) => {
      note.textContent = error.message;
    }).finally(() => {
      if (button) button.disabled = false;
    });
  });
  if (V && V.ready) V.ready.then(() => {});
})();
