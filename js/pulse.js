(function () {
  const V = window.VASC;
  V.ready.then(() => {
  const stage = document.querySelector(".stage");
  if (!V.requireVasc(stage)) return;

  function hours(value) {
    return value == null || value === "" ? "" : " · " + value + " h left";
  }

  function draw(data) {
    const people = data.people || [];
    const cards = people.map((person) => {
      const items = person.items || [];
      const list = items.length
        ? `<ul>${items.map((item) => `<li><a href="${V.esc(item.url)}">${V.esc(item.type)} ${item.id}</a> ${V.esc(item.title)} <em>${V.esc(item.state)}${hours(item.hoursLeft)}</em></li>`).join("")}</ul>`
        : `<p class="note">Nothing assigned right now.</p>`;
      const support = Number(person.supportCount) > 0
        ? ` · ${person.supportCount} client support · ${person.supportHours || 0} h recorded`
        : "";
      return `<article class="pulse-card"><h2>${V.esc(person.name)}</h2><p class="note">${V.esc(person.team || "")}${person.email ? " · " + V.esc(person.email) : ""} · ${items.length} open${support}</p>${list}</article>`;
    }).join("");
    stage.innerHTML = `
      <section class="lede"><h1>Team pulse</h1>
        <p>Open work and client-support hours come from what was saved today. A week view of who is in the office would use the Teams presence and calendar APIs: available, busy, in a meeting, or out of office. The Azure DevOps sign-in cannot call those. This page does not read location or anything outside the work items.</p>
        ${data.note ? `<p class="note">${V.esc(data.note)}</p>` : ""}
      </section>
      <div class="pulse-list">${cards || "<p>No team members were returned.</p>"}</div>`;
  }

  stage.innerHTML = `<section class="lede"><h1>Team pulse</h1><p>Reading the team and current assignments…</p></section>`;
  V.devops("pulse").then(draw).catch((error) => {
    if (error.login) {
      V.promptLogin();
      return;
    }
    stage.innerHTML = `<section class="panel"><h1>Team pulse</h1><p>${V.esc(error.message)}</p></section>`;
  });
  });
})();
