let supabaseClient = null;
let currentSession = null;
let jobs = [];
let auditedSessionToken = null;

const authCard = document.getElementById("auth-card");
const authState = document.getElementById("auth-state");
const googleButton = document.getElementById("google-button");
const logoutButton = document.getElementById("logout-button");
const queue = document.getElementById("queue");
const refreshButton = document.getElementById("refresh-button");
const searchInput = document.getElementById("search-input");
const statusEl = document.getElementById("status");
const jobList = document.getElementById("job-list");
const auditSection = document.getElementById("audit-section");
const auditList = document.getElementById("audit-list");
const auditStatus = document.getElementById("audit-status");
const auditSearchInput = document.getElementById("audit-search-input");
const auditRefreshButton = document.getElementById("audit-refresh-button");
let auditEvents = [];

init();

async function init() {
  try {
    const config = await fetchJson("/api/config.php");
    supabaseClient = window.supabase.createClient(config.supabaseUrl, config.supabaseAnonKey);
    const { data } = await supabaseClient.auth.getSession();
    await setSession(data.session);
    supabaseClient.auth.onAuthStateChange((_event, session) => setSession(session));
  } catch (error) {
    authState.textContent = error.message;
    authState.classList.add("error");
  }
}

googleButton.addEventListener("click", async () => {
  if (!supabaseClient) return;
  googleButton.disabled = true;
  authState.textContent = "Opening secure Google sign-in...";
  const { error } = await supabaseClient.auth.signInWithOAuth({
    provider: "google",
    options: {
      redirectTo: `${window.location.origin}/admin/`,
      queryParams: { prompt: "select_account" },
    },
  });
  if (error) {
    googleButton.disabled = false;
    authState.textContent = error.message;
    authState.classList.add("error");
  }
});

logoutButton.addEventListener("click", async () => {
  await recordAuditEvent("admin.logout");
  auditedSessionToken = null;
  await supabaseClient.auth.signOut();
});
refreshButton.addEventListener("click", loadJobs);
searchInput.addEventListener("input", renderJobs);
auditRefreshButton.addEventListener("click", loadAuditEvents);
auditSearchInput.addEventListener("input", renderAuditEvents);

jobList.addEventListener("click", async (event) => {
  const deleteButton = event.target.closest("[data-delete-job]");
  if (deleteButton && currentSession?.access_token) {
    const filename = deleteButton.dataset.filename || "this book";
    const confirmed = window.confirm(`Delete ${filename}, its manuscript, and all generated audio? This cannot be undone.`);
    if (!confirmed) return;

    deleteButton.disabled = true;
    setStatus(`Deleting ${filename} and all of its files...`);
    try {
      await fetchJson("/api/admin-delete.php", {
        method: "POST",
        headers: {
          Authorization: `Bearer ${currentSession.access_token}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ upload_id: deleteButton.dataset.deleteJob }),
      });
      await loadJobs();
      setStatus(`${filename}, its manuscript, and all generated audio were deleted.`);
    } catch (error) {
      deleteButton.disabled = false;
      setStatus(error.message, true);
    }
    return;
  }

  const button = event.target.closest("[data-download]");
  if (!button || !currentSession?.access_token) return;
  button.disabled = true;
  setStatus("Preparing secure download...");
  try {
    const response = await fetch(button.dataset.download, {
      headers: { Authorization: `Bearer ${currentSession.access_token}` },
    });
    if (!response.ok) {
      const payload = await response.json().catch(() => ({}));
      throw new Error(payload.detail || `Download failed (${response.status})`);
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement("a");
    anchor.href = url;
    anchor.download = button.dataset.filename || "manuscript.txt";
    document.body.append(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
    setStatus("Download started.");
  } catch (error) {
    setStatus(error.message, true);
  } finally {
    button.disabled = false;
  }
});

async function setSession(session) {
  currentSession = session;
  logoutButton.hidden = !session;
  authCard.hidden = Boolean(session);
  queue.hidden = true;
  auditSection.hidden = true;
  jobs = [];
  auditEvents = [];
  if (!session) {
    googleButton.disabled = false;
    authState.textContent = "Sign in with the authorised Accessible Audio administrator account.";
    return;
  }
  if (auditedSessionToken !== session.access_token) {
    auditedSessionToken = session.access_token;
    await recordAuditEvent("admin.login");
  }
  await Promise.all([loadJobs(), loadAuditEvents()]);
}

async function recordAuditEvent(event) {
  if (!currentSession?.access_token) return;
  try {
    await fetchJson("/api/audit-event.php", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${currentSession.access_token}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ event }),
    });
  } catch (_error) {
    // Audit delivery must never block authentication or logout.
  }
}

async function loadJobs() {
  if (!currentSession?.access_token) return;
  refreshButton.disabled = true;
  setStatus("Loading audiobook production jobs...");
  try {
    jobs = await fetchJson("/api/admin-files.php", {
      headers: { Authorization: `Bearer ${currentSession.access_token}` },
    });
    queue.hidden = false;
    renderSummary();
    renderJobs();
    setStatus(jobs.length ? `${jobs.length} production ${jobs.length === 1 ? "job" : "jobs"}.` : "No audiobook jobs yet.");
  } catch (error) {
    authCard.hidden = false;
    authState.textContent = error.message.includes("restricted")
      ? "This Google account is signed in, but it is not authorised for the admin queue."
      : error.message;
    authState.classList.add("error");
  } finally {
    refreshButton.disabled = false;
  }
}

async function loadAuditEvents() {
  if (!currentSession?.access_token) return;
  auditRefreshButton.disabled = true;
  auditStatus.textContent = "Loading recent audit events...";
  try {
    auditEvents = await fetchJson("/api/admin-audit.php?limit=200", {
      headers: { Authorization: `Bearer ${currentSession.access_token}` },
    });
    auditSection.hidden = false;
    renderAuditEvents();
    auditStatus.textContent = `${auditEvents.length} recent audit ${auditEvents.length === 1 ? "event" : "events"}.`;
  } catch (error) {
    auditSection.hidden = false;
    auditStatus.textContent = error.message;
    auditStatus.classList.add("error");
  } finally {
    auditRefreshButton.disabled = false;
  }
}

function renderAuditEvents() {
  const query = auditSearchInput.value.trim().toLowerCase();
  const visibleEvents = auditEvents.filter((entry) => JSON.stringify(entry).toLowerCase().includes(query));
  auditList.innerHTML = "";
  if (!visibleEvents.length) {
    const empty = document.createElement("p");
    empty.className = "empty-state";
    empty.textContent = auditEvents.length ? "No audit events match that search." : "No audit events have been recorded yet.";
    auditList.append(empty);
    return;
  }
  visibleEvents.forEach((entry) => {
    const card = document.getElementById("audit-template").content.cloneNode(true);
    const actor = entry.actor || {};
    const request = entry.request || {};
    setField(card, "audit_event", entry.event || "unknown");
    setField(card, "audit_outcome", entry.outcome || "unknown");
    setField(card, "audit_time", formatDate(entry.timestamp));
    setField(card, "audit_actor", actor.email || actor.user_id || "Anonymous/system");
    setField(card, "audit_request", [request.method, request.path, request.ip].filter(Boolean).join(" · ") || "System event");
    setField(card, "audit_request_id", entry.request_id || "Unavailable");
    setField(card, "audit_details", compactAuditDetails(entry.details));
    auditList.append(card);
  });
}

function compactAuditDetails(details) {
  if (!details || !Object.keys(details).length) return "No additional details";
  return Object.entries(details)
    .map(([key, value]) => `${key}: ${typeof value === "object" ? JSON.stringify(value) : value}`)
    .join(" · ");
}

function renderSummary() {
  document.getElementById("paid-count").textContent = jobs.length.toLocaleString();
  document.getElementById("word-count").textContent = jobs.reduce((sum, job) => sum + Number(job.word_count || 0), 0).toLocaleString();
  const total = jobs.reduce((sum, job) => sum + Number(job.payment_amount_zar || 0), 0);
  document.getElementById("paid-total").textContent = `R ${total.toLocaleString("en-ZA", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function renderJobs() {
  const query = searchInput.value.trim().toLowerCase();
  const visibleJobs = jobs.filter((job) => JSON.stringify(job).toLowerCase().includes(query));
  jobList.innerHTML = "";
  if (!visibleJobs.length) {
    const empty = document.createElement("p");
    empty.className = "empty-state";
    empty.textContent = jobs.length ? "No paid books match that search." : "New verified payments will appear here.";
    jobList.append(empty);
    return;
  }
  visibleJobs.forEach((job, index) => {
    const card = document.getElementById("job-template").content.cloneNode(true);
    card.querySelector(".job-card").style.setProperty("--order", index);
    setField(card, "filename", job.filename || "Untitled manuscript");
    setField(card, "status", job.status || "unknown");
    setField(card, "payer", [job.payer_first_name, job.payer_last_name].filter(Boolean).join(" ") || "Payer name unavailable");
    setField(card, "paid_at", formatDate(job.paid_at));
    setField(card, "user_email", job.user_email || "Unavailable");
    setField(card, "payer_email", job.payer_email || "Unavailable");
    setField(card, "payment", `R ${job.payment_amount_zar || "0.00"}`);
    setField(card, "payfast_payment_id", job.payfast_payment_id || "Unavailable");
    setField(card, "production", productionText(job));
    setField(card, "storage_key", job.storage_key || "Unavailable");
    const download = card.querySelector("[data-download]");
    download.dataset.download = job.download_url;
    download.dataset.filename = job.filename;
    const downloadList = card.querySelector('[data-field="downloads"]');
    (job.outputs || []).forEach((output) => {
      const audioButton = document.createElement("button");
      audioButton.className = "download-button";
      audioButton.type = "button";
      audioButton.dataset.download = output.download_url;
      audioButton.dataset.filename = output.filename;
      audioButton.textContent = `Download ${output.title} ${audioFormat(output.filename)}`;
      downloadList.append(audioButton);
    });
    const deleteButton = document.createElement("button");
    deleteButton.className = "delete-button";
    deleteButton.type = "button";
    deleteButton.dataset.deleteJob = job.id;
    deleteButton.dataset.filename = job.filename || "this book";
    deleteButton.textContent = "Delete book and audio";
    downloadList.append(deleteButton);
    jobList.append(card);
  });
}

function setField(root, field, value) {
  root.querySelector(`[data-field="${field}"]`).textContent = value;
}

function audioFormat(filename) {
  const extension = String(filename || "").match(/\.([a-z0-9]+)$/i)?.[1];
  return extension ? extension.toUpperCase() : "audio";
}

function productionText(job) {
  const extras = [job.also_wav && "WAV", job.translate && "translation", job.make_video && "video"].filter(Boolean);
  return `${Number(job.word_count || 0).toLocaleString()} words · ${job.narrator_voice || "voice unavailable"}${extras.length ? ` · ${extras.join(", ")}` : ""}`;
}

function formatDate(value) {
  if (!value) return "Payment time unavailable";
  return new Intl.DateTimeFormat("en-ZA", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

function setStatus(message, isError = false) {
  statusEl.textContent = message;
  statusEl.classList.toggle("error", isError);
}

async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.detail || `Request failed (${response.status})`);
  return payload;
}
