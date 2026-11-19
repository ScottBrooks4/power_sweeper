(function () {
  const V = window.VASC;
  const summary = document.getElementById("connect-summary");
  const list = document.getElementById("connect-steps");
  const sample = document.getElementById("connect-sample");
  const word = { ok: "Reached", wait: "Waiting", stop: "Stopped" };

  function load() {
    summary.textContent = "Checking the connection…";
    list.innerHTML = "";
    sample.innerHTML = "";
    fetch("api/devops.php?action=status", { cache: "no-store" }).then((res) => res.json()).then((data) => {
      summary.textContent = data.summary || data.error || "The check did not finish.";
      list.innerHTML = (data.steps || []).map((step) => {
        const state = word[step.state] ? step.state : "stop";
        return `<li><b class="step-${state}">${word[state]}</b><span><strong>${V.esc(step.label)}</strong><br>${V.esc(step.detail)}</span></li>`;
      }).join("");
      const rows = data.sample || [];
      if (!rows.length || V.role() !== "vasc") return;
      const org = encodeURIComponent(data.org || "");
      const project = encodeURIComponent(data.project || "");
      sample.innerHTML = `<h2>Recent work items</h2><ul class="connect-sample">${rows.map((row) => {
        const href = `https://dev.azure.com/${org}/${project}/_workitems/edit/${Number(row.id) || 0}`;
        return `<li><a href="${href}">${V.esc(row.type)} ${Number(row.id) || ""}</a> ${V.esc(row.title)}</li>`;
      }).join("")}</ul>`;
    }).catch(() => {
      summary.textContent = "The connection check did not answer.";
    });
  }

  const scope = document.getElementById("connect-scope");
  if (scope && new URLSearchParams(location.search).get("write") === "1") {
    scope.innerHTML = '<a href="https://dev.azure.com/VCDS-VCEMD/_usersSettings/tokens" target="_blank" rel="noopener">Create a token in Azure DevOps</a> for VCDS-VCEMD. Choose Work Items, Read &amp; write. Adding and moving circles needs that.';
  }
  document.getElementById("connect-again").addEventListener("click", load);
  document.getElementById("connect-token").addEventListener("submit", (event) => {
    event.preventDefault();
    const field = document.getElementById("connect-pat");
    const note = document.getElementById("connect-token-note");
    const pat = field.value.trim();
    if (!pat) {
      note.textContent = "Paste the Azure DevOps token first.";
      field.focus();
      return;
    }
    note.textContent = "Checking the token with the VASC project…";
    V.useDevopsToken(pat).then(() => {
      field.value = "";
      const back = new URLSearchParams(location.search).get("return") || "";
      if (/^[A-Za-z0-9]+\.html(\?[A-Za-z0-9._~%=:&+:-]*)?$/.test(back)) {
        location.href = back;
        return;
      }
      location.reload();
    }).catch((error) => {
      note.textContent = error.message;
    });
  });
  V.ready.then(load);
})();
