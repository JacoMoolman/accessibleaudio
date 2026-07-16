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
const filesList = document.getElementById("files-list");
const playNarratorSampleButton = document.getElementById("play-narrator-sample");
const stopNarratorSampleButton = document.getElementById("stop-narrator-sample");
const VOICE_CATALOG = window.ACCESSIBLE_AUDIO_VOICES || [];
const VOICES_BY_LABEL = Object.fromEntries(
  VOICE_CATALOG.map((voice) => [voice.label, voice])
);
const VOICE_SAMPLE_URLS = Object.fromEntries(
  VOICE_CATALOG.map((voice) => [voice.label, voice.sampleUrl])
);
const OPTION_COSTS_CENTS = {
  also_wav: 2500,
};
let fileAnalysis = null;
let currentVoiceSampleAudio = null;

populateNarratorVoices();
init();

function populateNarratorVoices() {
  const select = document.getElementById("narrator-voice");
  ["local", "cloud"].forEach((type) => {
    const voices = VOICE_CATALOG.filter((voice) => voice.type === type);
    if (!voices.length) return;
    const group = document.createElement("optgroup");
    const rate = voices[0].costPerWordCents;
    group.label = `${voices[0].typeLabel} — ${formatCentsPerWord(rate)}`;
    voices.forEach((voice) => {
      const option = document.createElement("option");
      option.value = voice.label;
      option.textContent = voice.label;
      group.append(option);
    });
    select.append(group);
  });
}

async function init() {
  try {
    const config = await fetchJson("/api/config.php");
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
document.getElementById("narrator-voice").addEventListener("change", () => {
  stopVoiceSample();
  updateCostEstimate();
  renderPaymentCheckout(null);
});

fileInput.addEventListener("change", analyzeSelectedFile);

playNarratorSampleButton.addEventListener("click", () => {
  playVoiceSample(document.getElementById("narrator-voice").value);
});

stopNarratorSampleButton.addEventListener("click", () => {
  stopVoiceSample(true);
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
    const data = await fetchJson("/api/process-file.php", {
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

filesList.addEventListener("click", async (event) => {
  const paymentButton = event.target.closest("[data-pay-upload]");
  if (paymentButton && currentSession?.access_token) {
    const uploadId = paymentButton.dataset.payUpload;
    const filename = paymentButton.dataset.payFilename || "this book";
    paymentButton.disabled = true;
    setStatus(`Preparing payment for ${filename}...`);
    try {
      const data = await fetchJson("/api/payment.php", {
        method: "POST",
        headers: {
          Authorization: `Bearer ${currentSession.access_token}`,
          "Content-Type": "application/json",
        },
        body: JSON.stringify({ upload_id: uploadId }),
      });
      renderPaymentCheckout(data.payment);
      paymentPanel.scrollIntoView({ behavior: "smooth", block: "center" });
      setStatus(`PayFast checkout is ready for ${filename}.`);
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      paymentButton.disabled = false;
    }
    return;
  }

  const deleteButton = event.target.closest("[data-delete-upload]");
  if (!deleteButton || !currentSession?.access_token) {
    return;
  }

  const uploadId = deleteButton.dataset.deleteUpload;
  const filename = deleteButton.dataset.deleteFilename || "this book";
  if (!window.confirm(`Delete ${filename}? This cannot be undone.`)) {
    return;
  }

  deleteButton.disabled = true;
  setStatus(`Deleting ${filename}...`);
  try {
    await fetchJson("/api/delete-file.php", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${currentSession.access_token}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ upload_id: uploadId }),
    });
    if (paymentPanel.dataset.uploadId === uploadId) {
      renderPaymentCheckout(null);
    }
    await loadFiles();
    setStatus(`${filename} was deleted.`);
  } catch (error) {
    deleteButton.disabled = false;
    setStatus(error.message, true);
  }
});

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

  try {
    fileAnalysis = await analyzeTextFile(file);
    renderAnalysisResult();
    setProductionOptionsEnabled(Boolean(fileAnalysis));
  } catch (error) {
    fileAnalysis = null;
    setProductionOptionsEnabled(Boolean(fileAnalysis));
    renderAnalysisResult(error.message, true);
  }
}

async function analyzeTextFile(file) {
  const text = await file.text();
  if (!text.trim()) {
    throw new Error("Uploaded .txt file is empty.");
  }
  const wordCount = countWords(text);
  const estimatedCostCents = wordCount * 0.5;
  const normalizedCost = Number.isInteger(estimatedCostCents)
    ? estimatedCostCents
    : Number(estimatedCostCents.toFixed(2));
  const chapters = detectChapters(text);
  return {
    source_language: detectSourceLanguage(text),
    chapters,
    chapter_count: chapters.length,
    word_count: wordCount,
    cost_per_word_cents: 0.5,
    estimated_cost_cents: normalizedCost,
    estimated_cost_zar: formatZarFromCents(normalizedCost),
  };
}

const CHAPTER_HEADING_RE = /^\s*(chapter|hoofstuk|isahluko|isigaba|chapitre|cap[ií]tulo|capitulo|kapitel|capitolo|part|book|deel)\b[\s:.\-–—]*(.*)$/i;

function detectChapters(text) {
  const chapters = [];
  const seen = new Set();
  text.split(/\r?\n/).forEach((line) => {
    const heading = line.trim().replace(/\s+/g, " ");
    if (!heading || heading.length > 120 || !CHAPTER_HEADING_RE.test(heading)) {
      return;
    }
    const normalized = heading.toLowerCase();
    if (seen.has(normalized)) {
      return;
    }
    seen.add(normalized);
    chapters.push({ index: chapters.length + 1, title: heading });
  });
  return chapters.length ? chapters : [{ index: 1, title: "Full book" }];
}

const LANGUAGE_MARKERS = {
  English: ["the", "and", "of", "to", "in", "that", "with", "chapter"],
  Afrikaans: ["die", "en", "van", "is", "nie", "met", "hoofstuk", "het"],
  Zulu: ["isahluko", "futhi", "ukuthi", "ngoba", "kanye", "lapho", "abantu", "wakhe"],
  Xhosa: ["isahluko", "kwaye", "ukuba", "ngokuba", "apho", "abantu", "wakhe", "yakhe"],
  German: ["der", "die", "das", "und", "ist", "nicht", "mit", "kapitel", "ein", "eine"],
  French: ["le", "la", "les", "et", "est", "pas", "avec", "chapitre", "une", "des"],
  Spanish: ["el", "la", "los", "las", "y", "es", "no", "con", "capitulo", "capítulo"],
  Portuguese: ["o", "a", "os", "as", "e", "nao", "não", "com", "capitulo", "capítulo"],
  Italian: ["il", "la", "gli", "le", "e", "non", "con", "capitolo", "una", "del"],
  Dutch: ["de", "het", "een", "en", "niet", "met", "hoofdstuk", "van", "dat", "is"],
};

function detectSourceLanguage(text) {
  const words = text.toLowerCase().match(/[a-zà-ÿ]+/g) || [];
  if (!words.length) {
    return "Unknown";
  }
  const wordSet = new Set(words);
  let bestLanguage = "Unknown";
  let bestScore = 0;
  Object.entries(LANGUAGE_MARKERS).forEach(([language, markers]) => {
    const score = markers.reduce((sum, marker) => sum + (wordSet.has(marker) ? 1 : 0), 0);
    if (score > bestScore) {
      bestLanguage = language;
      bestScore = score;
    }
  });
  return bestScore ? bestLanguage : "Unknown";
}

function countWords(text) {
  return (text.toLowerCase().match(/[a-zà-ÿ]+/g) || []).length;
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
  analysisResult.textContent = `${fileAnalysis.source_language || "Unknown"} detected, ${fileAnalysis.chapter_count} chapter${fileAnalysis.chapter_count === 1 ? "" : "s"} found (${wordCount} words). Choose a Local or Cloud voice to calculate the production price.`;
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
  delete paymentPanel.dataset.uploadId;
  paymentPanel.hidden = true;
  if (!payment?.form_action || !payment?.fields) {
    return;
  }

  payfastForm.action = payment.form_action;
  paymentAmount.textContent = payment.amount_zar || payment.fields.amount || "R 0.00";
  paymentBook.textContent = payment.book_name || payment.fields.item_name || "";
  paymentPanel.dataset.uploadId = payment.fields.custom_str1 || "";
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
  const wordCount = Number(fileAnalysis.word_count || 0);
  const selectedVoice = VOICES_BY_LABEL[document.getElementById("narrator-voice").value];
  if (!selectedVoice) {
    const localVoice = VOICE_CATALOG.find((voice) => voice.type === "local");
    const cloudVoice = VOICE_CATALOG.find((voice) => voice.type === "cloud");
    costEstimateTotal.textContent = "Select a voice";
    costEstimateBreakdown.innerHTML = [localVoice, cloudVoice]
      .filter(Boolean)
      .map((voice) => {
        const cents = wordCount * voice.costPerWordCents;
        return `<li><span>${escapeHtml(voice.typeLabel)} (${formatCentsPerWord(voice.costPerWordCents)})</span><strong>${formatZarFromCents(cents)}</strong></li>`;
      })
      .join("");
    return;
  }
  const baseCents = wordCount * selectedVoice.costPerWordCents;
  const rows = [
    {
      label: `${selectedVoice.typeLabel} book conversion (${wordCount.toLocaleString()} words at ${formatCentsPerWord(selectedVoice.costPerWordCents)})`,
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

function formatCentsPerWord(cents) {
  return `${Number(cents).toFixed(2).replace(/0+$/, "").replace(/\.$/, "")}c/word`;
}

function setProductionOptionsEnabled(enabled) {
  productionOptions.disabled = !enabled;
}

async function playVoiceSample(voiceName) {
  if (!voiceName) {
    setStatus("Choose a narrator voice first.", true);
    return;
  }
  const sampleUrl = VOICE_SAMPLE_URLS[voiceName];
  if (!sampleUrl) {
    setStatus(`No sample file is configured for ${voiceName}.`, true);
    return;
  }

  stopVoiceSample();
  const audio = new Audio(sampleUrl);
  currentVoiceSampleAudio = audio;

  const reset = () => {
    if (currentVoiceSampleAudio === audio) {
      currentVoiceSampleAudio = null;
      resetVoiceSampleControls();
    }
  };
  audio.addEventListener("ended", reset, { once: true });
  audio.addEventListener("error", () => {
    reset();
    setStatus(`Could not play the ${voiceName} sample file.`, true);
  }, { once: true });

  try {
    const playPromise = audio.play();
    if (playPromise) {
      await playPromise;
    }
    if (currentVoiceSampleAudio === audio) {
      playNarratorSampleButton.textContent = "Playing sample";
      stopNarratorSampleButton.disabled = false;
      setStatus(`Playing the ${voiceName} sample.`);
    }
  } catch {
    const wasCurrentSample = currentVoiceSampleAudio === audio;
    reset();
    if (wasCurrentSample && !audio.error) {
      setStatus(`Could not play the ${voiceName} sample file.`, true);
    }
  }
}

function stopVoiceSample(announce = false) {
  const audio = currentVoiceSampleAudio;
  currentVoiceSampleAudio = null;
  if (audio) {
    audio.pause();
    try {
      audio.currentTime = 0;
    } catch {
      // The sample may not have loaded enough metadata to seek yet.
    }
  }
  resetVoiceSampleControls();
  if (announce && audio) {
    setStatus("Voice sample stopped.");
  }
}

function resetVoiceSampleControls() {
  playNarratorSampleButton.textContent = "Play sample";
  stopNarratorSampleButton.disabled = true;
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
  const files = await fetchJson("/api/files.php", {
    headers: {
      Authorization: `Bearer ${currentSession.access_token}`,
    },
  });
  if (!files.length) {
    filesList.innerHTML = `<p class="empty">No uploads yet.</p>`;
    return;
  }
  filesList.innerHTML = files.map(renderFile).join("");
}

function renderFile(file) {
  const created = file.created_at ? new Date(file.created_at).toLocaleString() : "";
  return `
    <article class="file-row">
      <div>
        <strong>${escapeHtml(file.filename)}</strong>
        <span>${escapeHtml(created)}</span>
      </div>
      <div class="file-row-actions">
        <span class="badge">${escapeHtml(file.status)}</span>
        <button
          type="button"
          data-pay-upload="${escapeHtml(file.id)}"
          data-pay-filename="${escapeHtml(file.filename)}"
          aria-label="Pay for ${escapeHtml(file.filename)}"
        >Pay now</button>
        <button
          type="button"
          class="danger"
          data-delete-upload="${escapeHtml(file.id)}"
          data-delete-filename="${escapeHtml(file.filename)}"
          aria-label="Delete ${escapeHtml(file.filename)}"
        >Delete book</button>
      </div>
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
