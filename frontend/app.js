let supabaseClient = null;
let currentSession = null;

const authState = document.getElementById("auth-state");
const authControls = document.getElementById("auth-controls");
const authPanel = document.getElementById("auth-panel");
const uploadPanel = document.getElementById("upload-panel");
const filesPanel = document.getElementById("files-panel");
const statusEl = document.getElementById("status");
const googleButton = document.getElementById("google-button");
const logoutButton = document.getElementById("logout-button");
const uploadForm = document.getElementById("upload-form");
const fileInput = document.getElementById("book-file");
const refreshFilesButton = document.getElementById("refresh-files");
const productionOptions = document.getElementById("production-options");
const analysisResult = document.getElementById("analysis-result");
const chapterList = document.getElementById("chapter-list");
const costEstimatePanel = document.getElementById("cost-estimate-panel");
const costEstimateTotal = document.getElementById("cost-estimate-total");
const costEstimateBreakdown = document.getElementById("cost-estimate-breakdown");
const paymentPanel = document.getElementById("payment-panel");
const paymentAmount = document.getElementById("payment-amount");
const paymentBook = document.getElementById("payment-book");
const payfastForm = document.getElementById("payfast-form");
const playNarratorSampleButton = document.getElementById("play-narrator-sample");
const VOICE_SAMPLE_URLS = {
  "English Female": "/assets/voice-samples/english-female.wav",
  "English Male": "/assets/voice-samples/english-male.mp3",
  "Afrikaans Male": "/assets/voice-samples/afrikaans-male.mp3",
  "Zulu Female": "/assets/voice-samples/zulu-female.wav",
  "Zulu Male": "/assets/voice-samples/zulu-male.mp3",
  "Xhosa Male": "/assets/voice-samples/xhosa-male.wav",
};
const OPTION_COSTS_CENTS = {
  also_wav: 2500,
};
let fileAnalysis = null;
let currentVoiceSampleAudio = null;

init();

async function init() {
  try {
    const config = await fetchJson("/config/public");
    supabaseClient = window.supabase.createClient(
      config.supabaseUrl,
      config.supabaseAnonKey
    );
    const { data } = await supabaseClient.auth.getSession();
    setSession(data.session);

    supabaseClient.auth.onAuthStateChange((_event, session) => {
      setSession(session);
    });
  } catch (error) {
    setStatus(error.message, true);
  }
}

googleButton.addEventListener("click", async () => {
  if (!supabaseClient) {
    setStatus("Google sign-in is still loading. Try again in a moment.", true);
    return;
  }
  googleButton.disabled = true;
  googleButton.classList.add("is-loading");
  setStatus("Opening secure Google sign-in...");
  const { error } = await supabaseClient.auth.signInWithOAuth({
    provider: "google",
    options: {
      redirectTo: `${window.location.origin}/submit/`,
      queryParams: { prompt: "select_account" },
    },
  });
  if (error) {
    googleButton.disabled = false;
    googleButton.classList.remove("is-loading");
    setStatus(error.message, true);
  }
});

logoutButton.addEventListener("click", async () => {
  if (currentSession?.access_token?.startsWith("test-")) {
    setSession(null);
  } else {
    await supabaseClient.auth.signOut();
  }
  setStatus("Logged out.");
});

document.getElementById("also-wav").addEventListener("change", () => {
  updateCostEstimate();
  renderPaymentCheckout(null);
});

fileInput.addEventListener("change", analyzeSelectedFile);

playNarratorSampleButton.addEventListener("click", () => {
  playVoiceSample(document.getElementById("narrator-voice").value);
});

uploadForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!currentSession?.access_token) {
    setStatus("Log in before uploading.", true);
    return;
  }

  const fileInput = document.getElementById("book-file");
  const file = fileInput.files[0];
  if (!file) {
    setStatus("Choose a .txt file first.", true);
    return;
  }
  if (!file.name.toLowerCase().endsWith(".txt")) {
    setStatus("Only .txt files are accepted.", true);
    return;
  }
  if (!fileAnalysis) {
    setStatus("Wait for automatic language and chapter detection before uploading.", true);
    return;
  }

  const formData = new FormData();
  formData.append("file", file);
  formData.append("narrator_voice", document.getElementById("narrator-voice").value);
  formData.append("output_format", "mp3");
  formData.append("also_wav", document.getElementById("also-wav").checked ? "true" : "false");
  formData.append("translate", "false");
  formData.append("translation_languages", "");
  formData.append("translation_voices", "{}");
  formData.append("source_language", fileAnalysis.source_language || "");
  formData.append("chapter_titles", JSON.stringify(fileAnalysis.chapters.map((chapter) => chapter.title)));
  formData.append("make_video", "false");

  setStatus("Uploading...");
  try {
    const data = await fetchJson("/process-file", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${currentSession.access_token}`,
      },
      body: formData,
    });
    renderPaymentCheckout(data.payment);
    uploadForm.reset();
    fileAnalysis = null;
    renderAnalysisResult();
    renderCostEstimate();
    setProductionOptionsEnabled(Boolean(fileAnalysis));
    setStatus(
      paymentPanel.hidden
        ? "Uploaded. Status is saved as uploaded for manual processing."
        : "Uploaded. PayFast checkout is ready."
    );
    await loadFiles();
  } catch (error) {
    setStatus(error.message, true);
  }
});

refreshFilesButton.addEventListener("click", loadFiles);

payfastForm.addEventListener("submit", (event) => {
  if (!payfastForm.getAttribute("action")) {
    event.preventDefault();
    setStatus("Upload a book before paying.", true);
    return;
  }
  event.preventDefault();
  payfastForm.submit();
});

async function analyzeSelectedFile() {
  fileAnalysis = null;
  setProductionOptionsEnabled(Boolean(fileAnalysis));
  renderPaymentCheckout(null);
  renderAnalysisResult("Detecting source language and chapters...");
  const file = fileInput.files[0];
  if (!file) {
    renderAnalysisResult();
    return;
  }
  if (!file.name.toLowerCase().endsWith(".txt")) {
    renderAnalysisResult("Only .txt files are accepted.", true);
    return;
  }
  if (!currentSession?.access_token) {
    renderAnalysisResult("Log in before automatic detection.", true);
    return;
  }

  const formData = new FormData();
  formData.append("file", file);
  try {
    fileAnalysis = await fetchJson("/analyze-file", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${currentSession.access_token}`,
      },
      body: formData,
    });
    renderAnalysisResult();
    setProductionOptionsEnabled(Boolean(fileAnalysis));
  } catch (error) {
    fileAnalysis = null;
    setProductionOptionsEnabled(Boolean(fileAnalysis));
    renderAnalysisResult(error.message, true);
  }
}

function renderAnalysisResult(message = "", isError = false) {
  if (!fileAnalysis) {
    analysisResult.textContent = message || "Choose a text file to detect the source language and chapters.";
    analysisResult.classList.toggle("error", isError);
    chapterList.innerHTML = "";
    renderCostEstimate();
    return;
  }

  const wordCount = Number(fileAnalysis.word_count || 0).toLocaleString();
  analysisResult.textContent = `${fileAnalysis.source_language || "Unknown"} detected, ${fileAnalysis.chapter_count} chapter${fileAnalysis.chapter_count === 1 ? "" : "s"} found (${wordCount} words at 0.5c/word).`;
  analysisResult.classList.remove("error");
  chapterList.innerHTML = fileAnalysis.chapters
    .map((chapter) => `<li>${escapeHtml(chapter.title)}</li>`)
    .join("");
  renderCostEstimate();
}

function renderCostEstimate() {
  if (!fileAnalysis) {
    costEstimatePanel.hidden = true;
    costEstimateTotal.textContent = "R 0.00";
    costEstimateBreakdown.innerHTML = "";
    return;
  }
  costEstimatePanel.hidden = false;
  updateCostEstimate();
}

function renderPaymentCheckout(payment) {
  payfastForm.innerHTML = `<button type="submit">Pay with PayFast</button>`;
  payfastForm.removeAttribute("action");
  paymentAmount.textContent = "R 0.00";
  paymentBook.textContent = "";
  paymentPanel.hidden = true;
  if (!payment?.form_action || !payment?.fields) {
    return;
  }

  payfastForm.action = payment.form_action;
  paymentAmount.textContent = payment.amount_zar || payment.fields.amount || "R 0.00";
  paymentBook.textContent = payment.book_name || payment.fields.item_name || "";
  Object.entries(payment.fields).forEach(([name, value]) => {
    const input = document.createElement("input");
    input.type = "hidden";
    input.name = name;
    input.value = String(value ?? "");
    payfastForm.appendChild(input);
  });
  paymentPanel.hidden = false;
}

function updateCostEstimate() {
  if (!fileAnalysis) {
    return;
  }
  const baseCents = Number(fileAnalysis.estimated_cost_cents || 0);
  const rows = [
    {
      label: `Book conversion (${Number(fileAnalysis.word_count || 0).toLocaleString()} words at 0.5c/word)`,
      cents: baseCents,
    },
  ];
  if (document.getElementById("also-wav").checked) {
    rows.push({ label: "WAV output", cents: OPTION_COSTS_CENTS.also_wav });
  }
  const totalCents = rows.reduce((sum, row) => sum + row.cents, 0);
  costEstimateTotal.textContent = formatZarFromCents(totalCents);
  costEstimateBreakdown.innerHTML = rows
    .map((row) => `<li><span>${escapeHtml(row.label)}</span><strong>${formatZarFromCents(row.cents)}</strong></li>`)
    .join("");
}

function formatZarFromCents(cents) {
  return `R ${(Number(cents || 0) / 100).toFixed(2)}`;
}

function setProductionOptionsEnabled(enabled) {
  productionOptions.disabled = !enabled;
}

function playVoiceSample(voiceName) {
  if (!voiceName) {
    setStatus("Choose a narrator voice first.", true);
    return;
  }
  const sampleUrl = VOICE_SAMPLE_URLS[voiceName];
  if (!sampleUrl) {
    setStatus(`No sample file is configured for ${voiceName}.`, true);
    return;
  }

  if (currentVoiceSampleAudio) {
    currentVoiceSampleAudio.pause();
    currentVoiceSampleAudio.currentTime = 0;
  }
  currentVoiceSampleAudio = new Audio(sampleUrl);
  currentVoiceSampleAudio.addEventListener("error", () => {
    setStatus(`Could not play the ${voiceName} sample file.`, true);
  });
  const playPromise = currentVoiceSampleAudio.play();
  if (playPromise) {
    playPromise.catch(() => {
      setStatus(`Could not play the ${voiceName} sample file.`, true);
    });
  }
}

function cssEscape(value) {
  if (window.CSS?.escape) {
    return window.CSS.escape(value);
  }
  return String(value).replaceAll('"', '\\"');
}

async function setSession(session) {
  currentSession = session;
  const loggedIn = Boolean(session?.user);
  uploadPanel.hidden = !loggedIn;
  filesPanel.hidden = !loggedIn;
  if (authControls) {
    authControls.hidden = loggedIn;
  }
  authPanel.classList.toggle("is-authenticated", loggedIn);
  logoutButton.hidden = !loggedIn;
  googleButton.hidden = loggedIn;
  authState.textContent = loggedIn
    ? `Logged in as ${session.user.email || session.user.id}`
    : "Use your Google account to keep your manuscripts and production choices together.";
  if (loggedIn) {
    await loadFiles();
  }
}

async function loadFiles() {
  if (!currentSession?.access_token) return;
  const files = await fetchJson("/files", {
    headers: {
      Authorization: `Bearer ${currentSession.access_token}`,
    },
  });
  const list = document.getElementById("files-list");
  if (!files.length) {
    list.innerHTML = `<p class="empty">No uploads yet.</p>`;
    return;
  }
  list.innerHTML = files.map(renderFile).join("");
}

function renderFile(file) {
  const created = file.created_at ? new Date(file.created_at).toLocaleString() : "";
  return `
    <article class="file-row">
      <div>
        <strong>${escapeHtml(file.filename)}</strong>
        <span>${escapeHtml(created)}</span>
      </div>
      <span class="badge">${escapeHtml(file.status)}</span>
    </article>
  `;
}

async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const text = await response.text();
  const data = text ? JSON.parse(text) : null;
  if (!response.ok) {
    throw new Error(data?.detail || data?.message || `Request failed: ${response.status}`);
  }
  return data;
}

function setStatus(message, isError = false) {
  statusEl.textContent = message;
  statusEl.classList.toggle("error", isError);
}

function escapeHtml(value) {
  return String(value || "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}
