document.addEventListener("DOMContentLoaded", () => {
  initEventMap();
  initEventParticipation();
});

/*
|--------------------------------------------------------------------------
| Event map
|--------------------------------------------------------------------------
*/

function initEventMap() {
  const mapEl = document.getElementById("eventShowMap");

  if (!mapEl || typeof L === "undefined") {
    return;
  }

  const lat = Number(mapEl.dataset.lat);
  const lng = Number(mapEl.dataset.lng);
  const title = mapEl.dataset.title || "Evento";
  const location = mapEl.dataset.location || "";

  if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
    return;
  }

  const map = L.map(mapEl, {
    zoomControl: true,
    scrollWheelZoom: true,
  }).setView([lat, lng], 14);

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap",
  }).addTo(map);

  const marker = L.marker([lat, lng]).addTo(map);

  marker.bindPopup(`
    <strong>${escapeHTML(title)}</strong>
    ${location ? `<br><span>${escapeHTML(location)}</span>` : ""}
  `);

  window.setTimeout(() => {
    map.invalidateSize();
  }, 250);
}

/*
|--------------------------------------------------------------------------
| Join / Leave event without page refresh
|--------------------------------------------------------------------------
*/

function initEventParticipation() {
  const actionsWrap = document.querySelector("[data-event-actions]");
  const participantsEls = document.querySelectorAll("[data-participants-count]");
  const joinedPill = document.querySelector("[data-joined-pill]");

  if (!actionsWrap) {
    return;
  }

  document.addEventListener("submit", async (event) => {
    const form = event.target.closest("[data-event-participation-form]");

    if (!form) {
      return;
    }

    event.preventDefault();

    const button = form.querySelector("button[type='submit']");

    if (button?.disabled) {
      return;
    }

    const actionType = form.dataset.actionType || "join";

    setButtonLoading(button, actionType);

    try {
      const response = await fetch(form.action, {
        method: "POST",
        headers: {
          "X-CSRF-TOKEN": getCsrfToken(form),
          "Accept": "application/json",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: new FormData(form),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || "Não foi possível atualizar a participação.");
      }

      updateParticipants(
        participantsEls,
        Number(data.participants_count || 0),
        Number(data.max_participants || actionsWrap.dataset.maxParticipants || 0)
      );

      updateJoinedPill(joinedPill, Boolean(data.is_joined));
      updateActionButton(actionsWrap, data);
      showMiniFeedback(actionsWrap, data.message || "Participação atualizada.");
    } catch (error) {
      resetButton(button, actionType);
      showMiniFeedback(actionsWrap, error.message || "Ocorreu um erro.", true);
    }
  });
}

function updateParticipants(elements, current, max) {
  elements.forEach((el) => {
    el.textContent = `${current} / ${max}`;
  });
}

function updateJoinedPill(joinedPill, isJoined) {
  if (!joinedPill) {
    return;
  }

  joinedPill.hidden = !isJoined;
}

function updateActionButton(actionsWrap, data) {
  if (data.is_creator || !data.is_active) {
    return;
  }

  const joinUrl = actionsWrap.dataset.joinUrl;
  const leaveUrl = actionsWrap.dataset.leaveUrl;

  if (data.is_joined) {
    actionsWrap.innerHTML = `
      <form
        method="POST"
        action="${escapeAttribute(leaveUrl)}"
        data-event-participation-form
        data-action-type="leave"
      >
        <input type="hidden" name="_token" value="${escapeAttribute(getCsrfToken())}">

        <button type="submit" class="wj-btn wj-btn-danger">
          <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
          <span>Sair do evento</span>
        </button>
      </form>
    `;
  } else {
    actionsWrap.innerHTML = `
      <form
        method="POST"
        action="${escapeAttribute(joinUrl)}"
        data-event-participation-form
        data-action-type="join"
      >
        <input type="hidden" name="_token" value="${escapeAttribute(getCsrfToken())}">

        <button type="submit" class="wj-btn wj-btn-primary">
          <i class="bi bi-check2-circle" aria-hidden="true"></i>
          <span>Participar no evento</span>
        </button>
      </form>
    `;
  }
}

function setButtonLoading(button, type) {
  if (!button) {
    return;
  }

  button.disabled = true;
  button.classList.add("is-loading");

  const text = type === "leave" ? "A sair..." : "A participar...";

  button.innerHTML = `
    <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
    <span>${text}</span>
  `;
}

function resetButton(button, type) {
  if (!button) {
    return;
  }

  button.disabled = false;
  button.classList.remove("is-loading");

  if (type === "leave") {
    button.innerHTML = `
      <i class="bi bi-box-arrow-left" aria-hidden="true"></i>
      <span>Sair do evento</span>
    `;
  } else {
    button.innerHTML = `
      <i class="bi bi-check2-circle" aria-hidden="true"></i>
      <span>Participar no evento</span>
    `;
  }
}

function showMiniFeedback(container, message, isError = false) {
  const old = container.querySelector(".event-feedback");

  if (old) {
    old.remove();
  }

  const feedback = document.createElement("div");
  feedback.className = `event-feedback ${isError ? "is-error" : "is-success"}`;
  feedback.textContent = message;

  container.appendChild(feedback);

  window.setTimeout(() => {
    feedback.remove();
  }, 2600);
}

function getCsrfToken(form = null) {
  const meta = document.querySelector('meta[name="csrf-token"]')?.content;

  if (meta) {
    return meta;
  }

  const input = form?.querySelector('input[name="_token"]')?.value;

  if (input) {
    return input;
  }

  return document.querySelector('input[name="_token"]')?.value || "";
}

function escapeHTML(value) {
  const div = document.createElement("div");
  div.textContent = String(value ?? "");

  return div.innerHTML;
}

function escapeAttribute(value) {
  return escapeHTML(value).replace(/"/g, "&quot;");
}