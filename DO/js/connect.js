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
  const teamsNote = document.getElementById("connect-teams-note");
  const teamsCode = document.getElementById("connect-teams-code");
  const teamsGo = document.getElementById("connect-teams-go");
  let teamsPoll = 0;
  const withCalendar = new URLSearchParams(location.search).get("calendar") === "1";
  if (withCalendar && teamsGo) {
    teamsGo.textContent = "Connect Teams and calendar";
    const why = document.getElementById("connect-calendar");
    if (why) why.hidden = false;
  }

  function teamsSession() {
    return fetch("api/teams.php?action=session", { cache: "no-store" }).then((res) => res.json()).then((data) => {
      if (data && data.signedIn) {
        teamsNote.textContent = "Teams is connected. Portraits on the org chart show presence and in-office.";
        teamsGo.textContent = "Connect Teams again";
      } else if (!teamsCode.textContent) {
        teamsNote.textContent = "Teams is waiting for the Microsoft account. Connect Teams opens it with the code filled in.";
      }
    }).catch(() => {
      teamsNote.textContent = "The Teams check did not answer.";
    });
  }

  function pollTeams(interval) {
    clearTimeout(teamsPoll);
    teamsPoll = setTimeout(() => {
      fetch("api/teams.php?action=loginpoll", { cache: "no-store" }).then((res) => res.json().then((data) => ({ ok: res.ok, data: data }))).then((result) => {
        if (result.data && result.data.signedIn) {
          teamsCode.textContent = "";
          teamsNote.textContent = "Teams is connected. Portraits on the org chart show presence and in-office.";
          teamsGo.disabled = false;
          teamsGo.textContent = "Connect Teams again";
          return;
        }
        if (result.data && result.data.pending) {
          pollTeams(interval);
          return;
        }
        teamsGo.disabled = false;
        teamsNote.textContent = (result.data && result.data.error) || "Teams did not accept this sign-in.";
      }).catch(() => {
        teamsGo.disabled = false;
        teamsNote.textContent = "The Teams sign-in did not answer.";
      });
    }, interval * 1000);
  }

  if (teamsGo) {
    teamsGo.addEventListener("click", () => {
      const popup = window.open("", "_blank");
      teamsGo.disabled = true;
      teamsCode.textContent = "";
      teamsNote.textContent = "Opening the Microsoft account already in this browser…";
      fetch("api/teams.php?action=login" + (withCalendar ? "&calendar=1" : ""), { cache: "no-store" }).then((res) => res.json().then((data) => ({ ok: res.ok, data: data }))).then((result) => {
        if (!result.ok || result.data.ok === false || !result.data.user_code) {
          if (popup) popup.close();
          teamsGo.disabled = false;
          teamsNote.textContent = (result.data && result.data.error) || "Teams sign-in did not start.";
          return;
        }
        const url = result.data.complete || result.data.url || "https://login.microsoftonline.com/common/oauth2/deviceauth";
        if (popup) popup.location = url;
        teamsNote.innerHTML = `Confirm the Microsoft account in the window that opened. If it did not open, use <a href="${V.esc(url)}" target="_blank" rel="noopener">this Microsoft page</a>. The code is filled in.`;
        teamsCode.textContent = result.data.user_code;
        pollTeams(Math.max(5, Number(result.data.interval) || 5));
      }).catch(() => {
        if (popup) popup.close();
        teamsGo.disabled = false;
        teamsNote.textContent = "Teams sign-in did not start.";
      });
    });
  }

  V.ready.then(() => {
    load();
    teamsSession();
  });
})();
