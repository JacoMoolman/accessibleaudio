let supabaseClient = null;
let currentSession = null;
let jobs = [];

const authCard = document.getElementById("auth-card");
const authState = document.getElementById("auth-state");
const googleButton = document.getElementById("google-button");
const logoutButton = document.getElementById("logout-button");
const queue = document.getElementById("queue");
const refreshButton = document.getElementById("refresh-button");
const searchInput = document.getElementById("search-input");
const statusEl = document.getElementById("status");
const jobList = document.getElementById("job-list");

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
  await supabaseClient.auth.signOut();
});
refreshButton.addEventListener("click", loadJobs);
searchInput.addEventListener("input", renderJobs);

jobList.addEventListener("click", async (event) => {
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
  jobs = [];
  if (!session) {
    googleButton.disabled = false;
    authState.textContent = "Sign in with the authorised Accessible Audio administrator account.";
    return;
  }
  await loadJobs();
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
      audioButton.textContent = `Download ${output.title} WAV`;
      downloadList.append(audioButton);
    });
    jobList.append(card);
  });
}

function setField(root, field, value) {
  root.querySelector(`[data-field="${field}"]`).textContent = value;
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
