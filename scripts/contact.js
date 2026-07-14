(() => {
  const form = document.getElementById("contact-form");
  const submitButton = document.getElementById("contact-submit");
  const status = document.getElementById("contact-status");
  const captchaSlot = document.getElementById("contact-captcha");
  const startedAt = Date.now();
  let siteKey = "";
  let captchaReady = false;
  let widgetId = null;

  function setStatus(message, isError = false) {
    status.textContent = message;
    status.classList.toggle("error", isError);
  }

  function renderCaptcha() {
    if (!captchaReady || !siteKey || widgetId !== null) return;
    widgetId = window.grecaptcha.render(captchaSlot, {
      sitekey: siteKey,
      callback: () => {
        submitButton.disabled = false;
        setStatus("Human check complete. Your message is ready to send.");
      },
      "expired-callback": () => {
        submitButton.disabled = true;
        setStatus("The human check expired. Complete it again.", true);
      },
      "error-callback": () => {
        submitButton.disabled = true;
        setStatus("The human check could not load. Refresh and try again.", true);
      },
    });
    setStatus("Complete the human check, then send your message.");
  }

  window.contactRecaptchaReady = () => {
    captchaReady = true;
    renderCaptcha();
  };

  fetch("/api/config.php", { headers: { Accept: "application/json" } })
    .then((response) => {
      if (!response.ok) throw new Error("Contact configuration is unavailable.");
      return response.json();
    })
    .then((config) => {
      siteKey = config.recaptchaSiteKey || "";
      if (!siteKey) throw new Error("The protected contact form is not configured.");
      renderCaptcha();
    })
    .catch((error) => setStatus(error.message, true));

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const captchaToken = widgetId === null ? "" : window.grecaptcha.getResponse(widgetId);
    if (!captchaToken) {
      submitButton.disabled = true;
      setStatus("Complete the human check before sending.", true);
      return;
    }

    submitButton.disabled = true;
    setStatus("Sending your message…");
    const data = Object.fromEntries(new FormData(form).entries());
    data.captcha_token = captchaToken;
    data.started_at = startedAt;

    try {
      const response = await fetch("/api/contact.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify(data),
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(result.detail || "Your message could not be sent.");
      form.reset();
      window.grecaptcha.reset(widgetId);
      setStatus("Message sent. Accessible Audio will reply to the address you supplied.");
    } catch (error) {
      setStatus(error.message, true);
    }
  });
})();
