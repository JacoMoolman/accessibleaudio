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
const refreshFilesButton = document.getElementById("refresh-files");
const translateCheckbox = document.getElementById("translate");
const translationOptions = document.getElementById("translation-options");
const captchaSlot = document.getElementById("captcha-slot");

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
  await supabaseClient.auth.signOut();
  setStatus("Logged out.");
});

translateCheckbox.addEventListener("change", () => {
  translationOptions.hidden = !translateCheckbox.checked;
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

  const formData = new FormData();
  formData.append("file", file);
  formData.append("narrator_voice", document.getElementById("narrator-voice").value);
  formData.append("output_format", "mp3");
  formData.append("also_wav", document.getElementById("also-wav").checked ? "true" : "false");
  formData.append("translate", translateCheckbox.checked ? "true" : "false");
  formData.append("translation_languages", selectedTranslationLanguages().join(","));
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

function selectedTranslationLanguages() {
  return Array.from(
    document.querySelectorAll("[name='translation-language']:checked")
  ).map((input) => input.value);
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
