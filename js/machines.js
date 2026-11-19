(function () {
  const V = window.VASC;
  V.ready.then(() => {
  const stage = document.querySelector(".stage");
  if (!V.requireVasc(stage)) return;
  const groupUrl = "https://dev.azure.com/VCDS-VCEMD/VASC/_machinegroup";

  function hours(value) {
    return value == null ? "—" : String(value);
  }

  function paint(message, groups, items) {
    const waiting = groups == null;
    const machines = waiting ? [] : groups.flatMap((group) => group.machines.map((row) => Object.assign({ group: group.name }, row)));
    const tasks = items || [];
    const needed = tasks.reduce((sum, row) => sum + (row.hoursNeeded || 0), 0);
    const left = tasks.reduce((sum, row) => sum + (row.hoursLeft || 0), 0);
    const machineRows = machines.length
      ? machines.map((row) => `<tr><td>${V.esc(row.name)}</td><td>${V.esc(row.group)}</td><td>${V.esc(row.status)}</td><td>${V.esc((row.tags || []).join(", "))}</td></tr>`).join("")
      : `<tr><td colspan="4">${waiting ? "Syncing…" : "No machines returned from the VASC machine group."}</td></tr>`;
    const taskRows = tasks.length
      ? tasks.map((row) => `<tr><td><a href="${V.esc(row.url)}">${row.id}</a></td><td>${V.esc(row.title)}</td><td>${V.esc(row.type)}</td><td>${V.esc(row.state)}</td><td>${hours(row.hoursNeeded)}</td><td>${hours(row.hoursLeft)}</td><td>${hours(row.hoursUsed)}</td></tr>`).join("")
      : "";
    stage.innerHTML = `
      <section class="lede"><h1>Machine group</h1>
        <p>Machines come from <a href="${groupUrl}">dev.azure.com/VCDS-VCEMD/VASC/_machinegroup</a>. Hours and task ids come from the work items in that same VASC project.</p>
      </section>
      <div class="stats">
        <div class="stat"><b>${machines.length}</b><span>Machines</span></div>
        <div class="stat"><b>${tasks.length}</b><span>Work items</span></div>
        <div class="stat"><b>${needed}</b><span>Hours needed</span></div>
        <div class="stat"><b>${left}</b><span>Hours left</span></div>
      </div>
      <section class="panel">
        <h2>Machines</h2>
        <table>
          <thead><tr><th>Machine</th><th>Group</th><th>Status</th><th>Tags</th></tr></thead>
          <tbody>${machineRows}</tbody>
        </table>
        ${taskRows ? `<h2>Work items</h2><table><thead><tr><th>Id</th><th>Title</th><th>Type</th><th>State</th><th>Hours needed</th><th>Hours left</th><th>Hours used</th></tr></thead><tbody>${taskRows}</tbody></table>` : ""}
        <p><button class="btn" id="sync" type="button">Sync</button></p>
        <p id="sync-note" class="note">${V.esc(message || "")}</p>
      </section>`;
    document.getElementById("sync").addEventListener("click", sync);
  }

  function sync() {
    paint("Syncing the VASC machine group and work items…", null, null);
    Promise.all([V.devops("machines"), V.devops("tasks")]).then(([machines, tasks]) => {
      paint("Read from Azure DevOps.", machines.groups, tasks.items);
    }).catch((error) => {
      paint(error.message, [], []);
      if (error.login) V.promptLogin(sync);
    });
  }

  sync();
  });
})();
