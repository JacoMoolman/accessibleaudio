let supabaseClient = null;
let currentSession = null;
let turnstileSiteKey = null;
let turnstileWidgetId = null;
let captchaToken = null;

const authState = document.getElementById("auth-state");
const authControls = document.getElementById("auth-controls");
const emailInput = document.getElementById("email");
const passwordInput = document.getElementById("password");
const uploadPanel = document.getElementById("upload-panel");
const filesPanel = document.getElementById("files-panel");
const statusEl = document.getElementById("status");
const loginButton = document.getElementById("login-button");
const signupButton = document.getElementById("signup-button");
const googleButton = document.getElementById("google-button");
const logoutButton = document.getElementById("logout-button");
const uploadForm = document.getElementById("upload-form");
const fileInput = document.getElementById("book-file");
const refreshFilesButton = document.getElementById("refresh-files");
const translateCheckbox = document.getElementById("translate");
const translationOptions = document.getElementById("translation-options");
const productionOptions = document.getElementById("production-options");
const analysisResult = document.getElementById("analysis-result");
const chapterList = document.getElementById("chapter-list");
const playNarratorSampleButton = document.getElementById("play-narrator-sample");
const captchaSlot = document.getElementById("captcha-slot");
const VOICE_SAMPLE_URLS = {
  "English Female": "/assets/voice-samples/english-female.wav",
  "English Male": "/assets/voice-samples/english-male.mp3",
  "Afrikaans Male": "/assets/voice-samples/afrikaans-male.mp3",
  "Zulu Female": "/assets/voice-samples/zulu-female.wav",
  "Zulu Male": "/assets/voice-samples/zulu-male.mp3",
  "Xhosa Male": "/assets/voice-samples/xhosa-male.wav",
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
    turnstileSiteKey = config.turnstileSiteKey || null;
    renderCaptchaIfConfigured();

    const { data } = await supabaseClient.auth.getSession();
    setSession(data.session);

    supabaseClient.auth.onAuthStateChange((_event, session) => {
      setSession(session);
    });
  } catch (error) {
    setStatus(error.message, true);
  }
}

loginButton.addEventListener("click", async () => {
  const { email, password } = credentials();
  const testSession = await tryTestLogin(email, password);
  if (testSession) {
    setSession(testSession);
    setStatus("Logged in.");
    return;
  }

  const options = authOptionsWithCaptcha();
  if (options === null) return;
  const { error } = await supabaseClient.auth.signInWithPassword({
    email,
    password,
    ...(options ? { options } : {}),
  });
  resetCaptcha();
  if (error) {
    setStatus(error.message, true);
    return;
  }
  setStatus("Logged in.");
});

signupButton.addEventListener("click", async () => {
  const { email, password } = credentials();
  const options = authOptionsWithCaptcha();
  if (options === null) return;
  const { error } = await supabaseClient.auth.signUp({
    email,
    password,
    ...(options ? { options } : {}),
  });
  resetCaptcha();
  if (error) {
    setStatus(error.message, true);
    return;
  }
  setStatus("Signup started. Check email if confirmation is enabled.");
});

googleButton.addEventListener("click", async () => {
  const { error } = await supabaseClient.auth.signInWithOAuth({
    provider: "google",
    options: {
      redirectTo: `${window.location.origin}/submit`,
    },
  });
  if (error) {
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

translateCheckbox.addEventListener("change", () => {
  translationOptions.hidden = !translateCheckbox.checked;
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
  formData.append("translate", translateCheckbox.checked ? "true" : "false");
  formData.append("translation_languages", selectedTranslationLanguages().join(","));
  formData.append("translation_voices", JSON.stringify(selectedTranslationVoices()));
  formData.append("source_language", fileAnalysis.source_language || "");
  formData.append("chapter_titles", JSON.stringify(fileAnalysis.chapters.map((chapter) => chapter.title)));
  formData.append("make_video", document.getElementById("make-video").checked ? "true" : "false");

  setStatus("Uploading...");
  try {
    await fetchJson("/process-file", {
      method: "POST",
      headers: {
        Authorization: `Bearer ${currentSession.access_token}`,
      },
      body: formData,
    });
    uploadForm.reset();
    fileAnalysis = null;
    renderAnalysisResult();
    setProductionOptionsEnabled(Boolean(fileAnalysis));
    translationOptions.hidden = true;
    setStatus("Uploaded. Status is saved as uploaded for manual processing.");
    await loadFiles();
  } catch (error) {
    setStatus(error.message, true);
  }
});

refreshFilesButton.addEventListener("click", loadFiles);

function credentials() {
  return {
    email: document.getElementById("email").value.trim(),
    password: document.getElementById("password").value,
  };
}

async function tryTestLogin(email, password) {
  try {
    const data = await fetchJson("/test-login", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ email, password }),
    });
    return {
      access_token: data.access_token,
      user: data.user,
    };
  } catch (_error) {
    return null;
  }
}

function selectedTranslationLanguages() {
  return Array.from(
    document.querySelectorAll("[name='translation-language']:checked")
  ).map((input) => input.value);
}

function selectedTranslationVoices() {
  return selectedTranslationLanguages().reduce((voices, language) => {
    const select = document.querySelector(`[name="translation-voice-${cssEscape(language)}"]`);
    if (select?.value) {
      voices[language] = select.value;
    }
    return voices;
  }, {});
}

async function analyzeSelectedFile() {
  fileAnalysis = null;
  setProductionOptionsEnabled(Boolean(fileAnalysis));
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
    analysisResult.textContent = message || "Choose a TXT file to detect the source language and chapters.";
    analysisResult.classList.toggle("error", isError);
    chapterList.innerHTML = "";
    return;
  }

  const estimatedCost = fileAnalysis.estimated_cost_zar || "R 0.00";
  const wordCount = Number(fileAnalysis.word_count || 0).toLocaleString();
  analysisResult.textContent = `${fileAnalysis.source_language || "Unknown"} detected, ${fileAnalysis.chapter_count} chapter${fileAnalysis.chapter_count === 1 ? "" : "s"} found. Estimated cost: ${estimatedCost} (${wordCount} words at 1c/word).`;
  analysisResult.classList.remove("error");
  chapterList.innerHTML = fileAnalysis.chapters
    .map((chapter) => `<li>${escapeHtml(chapter.title)}</li>`)
    .join("");
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

function authOptionsWithCaptcha() {
  if (!turnstileSiteKey) {
    return undefined;
  }
  if (!captchaToken) {
    setStatus("Complete the human check first.", true);
    return null;
  }
  return { captchaToken };
}

function renderCaptchaIfConfigured() {
  if (!turnstileSiteKey) {
    captchaSlot.hidden = true;
    return;
  }
  captchaSlot.hidden = false;
  const renderWhenReady = () => {
    if (!window.turnstile) {
      window.setTimeout(renderWhenReady, 100);
      return;
    }
    turnstileWidgetId = window.turnstile.render(captchaSlot, {
      sitekey: turnstileSiteKey,
      callback: (token) => {
        captchaToken = token;
        setStatus("");
      },
      "expired-callback": () => {
        captchaToken = null;
      },
      "error-callback": () => {
        captchaToken = null;
        setStatus("Human check failed. Try again.", true);
      },
    });
  };
  renderWhenReady();
}

function resetCaptcha() {
  captchaToken = null;
  if (window.turnstile && turnstileWidgetId !== null) {
    window.turnstile.reset(turnstileWidgetId);
  }
}

async function setSession(session) {
  currentSession = session;
  const loggedIn = Boolean(session?.user);
  uploadPanel.hidden = !loggedIn;
  filesPanel.hidden = !loggedIn;
  if (authControls) {
    authControls.hidden = loggedIn;
  }
  hideWhenLoggedIn(emailInput?.closest("label"), loggedIn);
  hideWhenLoggedIn(passwordInput?.closest("label"), loggedIn);
  hideWhenLoggedIn(captchaSlot, loggedIn);
  logoutButton.hidden = !loggedIn;
  loginButton.hidden = loggedIn;
  signupButton.hidden = loggedIn;
  googleButton.hidden = loggedIn;
  authState.textContent = loggedIn
    ? `Logged in as ${session.user.email || session.user.id}`
    : "Sign up or log in to upload a book.";
  if (loggedIn) {
    await loadFiles();
  }
}

function hideWhenLoggedIn(element, loggedIn) {
  if (element) {
    element.hidden = loggedIn;
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
