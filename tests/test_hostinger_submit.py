import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_hostinger_submit_frontend_uses_php_api_and_client_side_analysis():
    html = read("submit/index.html")
    app_js = read("submit/app.js")

    assert 'href="./styles.css?v=20260715-payment2"' in html
    assert 'src="./app.js?v=20260715-payment2"' in html
    assert 'fetchJson("/api/config.php")' in app_js
    assert 'fetchJson("/api/process-file.php"' in app_js
    assert 'fetchJson("/api/files.php"' in app_js
    assert 'analyzeTextFile(file)' in app_js
    assert 'fetchJson("/analyze-file"' not in app_js
    assert 'fetchJson("/process-file"' not in app_js
    assert 'fetchJson("/files"' not in app_js
    assert 'redirectTo: `${window.location.origin}/submit/`' in app_js
    assert "onrender.com" not in app_js


def test_user_facing_copy_does_not_require_utf8():
    page_sources = [
        read("faq.html"),
        read("submit/index.html"),
        read("frontend/index.html"),
    ]

    for content in page_sources:
        assert "UTF-8" not in content

    error_sources = [
        read("api/lib.php"),
        read("backend/app.py"),
        read("backend/storage.py"),
    ]
    for content in error_sources:
        assert "must be UTF-8 text" not in content


def test_voice_sample_controls_use_the_dark_site_palette():
    html = read("voice-samples.html")
    styles = read("styles.css")

    assert "styles.css?v=20260715-voices4" in html
    assert ".voice-card-actions button:focus-visible" in styles
    assert "#fffdf7" not in styles
    assert "background: rgba(7, 61, 53, 0.72);" in styles
    assert "background: var(--soft);" in styles


def test_submit_narrator_sample_has_working_stop_control():
    page = read("submit/index.html")
    app_js = read("submit/app.js")
    submit_styles = read("submit/styles.css")

    assert 'id="play-narrator-sample"' in page
    assert 'id="stop-narrator-sample" disabled' in page
    assert "styles.css?v=20260715-payment2" in page
    assert "app.js?v=20260715-payment2" in page
    assert 'getElementById("stop-narrator-sample")' in app_js
    assert "stopNarratorSampleButton.disabled = false" in app_js
    assert "audio.pause()" in app_js
    assert "audio.currentTime = 0" in app_js
    assert 'setStatus("Voice sample stopped.")' in app_js
    assert ".sample-actions" in submit_styles


def test_uploads_can_be_deleted_and_status_badge_stays_readable():
    app_js = read("submit/app.js")
    submit_styles = read("submit/styles.css")
    delete_endpoint = read("api/delete-file.php")
    php_lib = read("api/lib.php")

    assert 'fetchJson("/api/delete-file.php"' in app_js
    assert "data-delete-upload" in app_js
    assert "window.confirm" in app_js
    assert "Delete book" in app_js
    assert '$_SERVER[\'REQUEST_METHOD\'] !== \'POST\'' in delete_endpoint
    assert "current_user($config)" in delete_endpoint
    assert "delete_upload_record" in delete_endpoint
    assert "delete_upload_record(string $uploadDir, string $userId, string $uploadId)" in php_lib
    assert "hash('sha256', $userId)" in php_lib
    assert "delete_private_tree" in php_lib
    assert ".submit-section .file-row .badge" in submit_styles
    assert "color: #052c27;" in submit_styles
    assert "button.danger" in submit_styles


def test_existing_uploads_can_recreate_server_signed_payfast_checkout():
    app_js = read("submit/app.js")
    payment_endpoint = read("api/payment.php")
    process_file = read("api/process-file.php")
    php_lib = read("api/lib.php")

    assert 'fetchJson("/api/payment.php"' in app_js
    assert "data-pay-upload" in app_js
    assert "Pay now" in app_js
    assert "renderPaymentCheckout(data.payment)" in app_js
    assert "current_user($config)" in payment_endpoint
    assert "find_upload_record" in payment_endpoint
    assert "build_payfast_checkout" in payment_endpoint
    assert "count_words($content)" in payment_endpoint
    assert "function find_upload_record" in php_lib
    assert "'word_count' => $wordCount" in process_file
    assert "'narrator_voice' => $options['narrator_voice']" in process_file


def test_upload_is_rejected_before_storage_when_payfast_is_not_configured():
    process_file = read("api/process-file.php")
    php_lib = read("api/lib.php")

    assert "if (!payfast_configured($config))" in process_file
    assert "No book was uploaded" in process_file
    assert process_file.index("payfast_configured($config)") < process_file.index("validate_upload(")
    assert "function payfast_configured(array $config): bool" in php_lib


def test_test_login_tokens_are_signed_and_expire():
    php_lib = read("api/lib.php")
    test_login = read("api/test-login.php")

    assert "issue_test_token($config['test_login_password'])" in test_login
    assert "function issue_test_token(string $secret): string" in php_lib
    assert "function verify_test_token(string $token, string $secret): bool" in php_lib
    assert "hash_hmac('sha256'" in php_lib
    assert "hash_equals($expected" in php_lib
    assert "$expiresAt < time()" in php_lib
    assert "str_starts_with($token, 'test-')" not in php_lib


def test_numbered_voice_catalog_is_grouped_and_priced_without_naming_vendors():
    page = read("voice-samples.html")
    page_js = read("scripts/voice-samples.js")
    catalog_js = read("scripts/voice-catalog.js")
    submit_html = read("submit/index.html")
    submit_js = read("submit/app.js")
    php_lib = read("api/lib.php")
    process_file = read("api/process-file.php")

    public_voice_interface = "\n".join((page, page_js, catalog_js)).lower()
    for provider_name in ("gemini", "google text", "omnivoice", "voice provider", "local ai"):
        assert provider_name not in public_voice_interface

    assert "const voiceCount = 35" in catalog_js
    assert "const localVoiceCount = 5" in catalog_js
    assert "localCostPerWordCents = 0.5" in catalog_js
    assert "cloudCostPerWordCents = localCostPerWordCents * 1.5" in catalog_js
    assert 'type === "local" ? "Local voices" : "Cloud voices"' in catalog_js
    assert "/assets/voice-samples/catalog/voice-" in catalog_js
    assert "window.ACCESSIBLE_AUDIO_VOICES" in page_js
    assert 'id="local-voice-list"' in page
    assert 'id="cloud-voice-list"' in page
    assert "0.5c" in page
    assert "0.75c" in page
    assert "voice-provider-button" not in page
    assert "../scripts/voice-catalog.js?v=20260715-voices4" in submit_html
    assert "The numbers match the" in submit_html
    assert "Local: 0.5c/word." in submit_html
    assert "Cloud: 0.75c/word." in submit_html
    assert 'href="https://accessibleaudio.co.za/voice-samples.html">Voice samples</a>' in submit_html
    assert "VOICE_CATALOG.map" in submit_js
    assert "populateNarratorVoices" in submit_js
    assert 'document.createElement("optgroup")' in submit_js
    assert '["local", "cloud"]' in submit_js
    assert "selectedVoice.costPerWordCents" in submit_js
    assert "LOCAL_COST_PER_WORD_CENTS = 0.5" in php_lib
    assert "CLOUD_COST_PER_WORD_CENTS = LOCAL_COST_PER_WORD_CENTS * 1.5" in php_lib
    assert "narrator_voice_pricing" in php_lib
    assert "total_cost_cents(int $wordCount, string $narratorVoice" in php_lib
    assert "'voice_type' => $voicePricing['type']" in process_file
    assert "'cost_per_word_cents' => $voicePricing['cost_per_word_cents']" in process_file

    for number in range(1, 6):
        assert (ROOT / f"assets/voice-samples/catalog/voice-{number:02}.wav").stat().st_size > 10_000
    for number in range(6, 36):
        assert (ROOT / f"assets/voice-samples/catalog/voice-{number:02}.mp3").stat().st_size > 10_000


def test_public_contact_form_hides_direct_address_and_requires_recaptcha():
    public_pages = [
        read("index.html"),
        read("audiobooks.html"),
        read("faq.html"),
        read("contact.html"),
        read("submit/index.html"),
        read("frontend/index.html"),
    ]
    for page in public_pages:
        assert "mailto:" not in page.lower()
        assert "@accessibleaudio.co.za" not in page.lower()

    contact = read("contact.html")
    contact_js = read("scripts/contact.js")
    endpoint = read("api/contact.php")
    config = read("api/config.php")
    deploy = read("scripts/deploy-hostinger.ps1")

    assert 'id="contact-form"' in contact
    assert 'id="contact-captcha"' in contact
    assert "www.google.com/recaptcha/api.js" in contact
    assert "scripts/contact.js?v=20260714-contact1" in contact
    assert 'fetch("/api/contact.php"' in contact_js
    assert "window.grecaptcha.getResponse" in contact_js
    assert "captcha_token" in contact_js
    assert "www.google.com/recaptcha/api/siteverify" in endpoint
    assert "enforce_contact_rate_limit" in endpoint
    assert "count($timestamps) >= 5" in endpoint
    assert "send_contact_email" in endpoint
    assert "AUTH LOGIN" in endpoint
    assert "stream_socket_client" in endpoint
    assert "smtp_expect($socket, [250])" in endpoint
    assert not re.search(r"(?<![A-Za-z0-9_])mail\(", endpoint)
    assert "'contact_smtp_password'" in read("api/lib.php")
    assert "EMAIL_PASSWORD" in read("api/lib.php")
    assert "recaptchaSiteKey" in config
    assert "smtp" not in config.lower()
    assert "email_password" not in config.lower()
    assert '"scripts/contact.js"' in deploy


def test_sitewide_polish_is_deployed_and_respects_reduced_motion():
    public_pages = {
        "homepage": read("index.html"),
        "audiobook library": read("audiobooks.html"),
        "contact page": read("contact.html"),
        "voice samples page": read("voice-samples.html"),
        "FAQ page": read("faq.html"),
    }
    submit = read("submit/index.html")
    styles = read("styles.css")
    submit_styles = read("submit/styles.css")
    motion = read("scripts/site-motion.js")
    deploy = read("scripts/deploy-hostinger.ps1")

    assert 'class="home-page"' in public_pages["homepage"]
    assert "hero-signal" in public_pages["homepage"]
    for page in public_pages.values():
        assert "styles.css?v=20260715-voices4" in page
        assert "scripts/site-motion.js?v=20260715-motion2" in page
    assert "./styles.css?v=20260715-payment2" in submit
    assert "../scripts/site-motion.js?v=20260715-motion2" in submit
    assert '"scripts/site-motion.js"' in deploy
    assert "document.body" in motion
    assert 'hero.classList.add("motion-hero")' in motion
    assert '"animationend"' in motion
    assert 'classList.remove("motion-hero-item")' in motion
    assert "IntersectionObserver" in motion
    assert "requestAnimationFrame" in motion
    assert "prefers-reduced-motion: reduce" in motion
    assert "@media (prefers-reduced-motion: reduce)" in styles
    assert "@media (prefers-reduced-motion: reduce)" in submit_styles


def test_faq_is_public_and_linked_from_the_site():
    faq = read("faq.html")
    sitemap = read("sitemap.xml")

    assert "noindex" not in faq.lower()
    assert '<a href="faq.html" aria-current="page">FAQ</a>' in faq
    assert "<loc>https://accessibleaudio.co.za/faq.html</loc>" in sitemap

    public_pages = [
        read("index.html"),
        read("audiobooks.html"),
        read("contact.html"),
        read("voice-samples.html"),
    ]
    for page in public_pages:
        assert '<a href="faq.html">FAQ</a>' in page

    submit_pages = [read("submit/index.html"), read("frontend/index.html")]
    for page in submit_pages:
        assert '<a href="https://accessibleaudio.co.za/faq.html">FAQ</a>' in page


def test_hostinger_php_backend_stores_uploads_locally_without_aws_or_s3_keys():
    api_files = list((ROOT / "api").glob("*.php"))
    assert api_files

    combined = "\n".join(path.read_text(encoding="utf-8") for path in api_files)
    forbidden = [
        "AWS_ACCESS_KEY_ID",
        "AWS_SECRET_ACCESS_KEY",
        "S3_BUCKET_NAME",
        "boto3",
        "aws_secret",
    ]
    for value in forbidden:
        assert value not in combined

    assert "private_uploads" in combined
    assert "hostinger-local" in combined
    assert "SUPABASE_ANON_KEY" in combined
    assert "SUPABASE_SERVICE_ROLE_KEY" not in combined
    assert "REDIRECT_HTTP_AUTHORIZATION" in combined
    assert "getallheaders" in combined


def test_hostinger_local_upload_directory_is_not_publicly_readable():
    htaccess = read("private_uploads/.htaccess")
    assert "Require all denied" in htaccess
    assert "Deny from all" in htaccess


def test_hostinger_deploy_script_uploads_submit_and_api_but_not_private_secrets():
    deploy_script = read("scripts/deploy-hostinger.ps1")

    assert '"submit/index.html"' in deploy_script
    assert '"submit/app.js"' in deploy_script
    assert '"submit/styles.css"' in deploy_script
    assert 'Get-ChildItem -LiteralPath "api"' in deploy_script
    assert '"api/.htaccess"' in deploy_script
    assert '"private_uploads/.htaccess"' in deploy_script

    assert '"api/config.local.php"' not in deploy_script
    assert '"api/config.local.example.php"' not in deploy_script
    assert "AWS_ACCESS_KEY_ID" not in deploy_script
    assert "AWS_SECRET_ACCESS_KEY" not in deploy_script


def test_hostinger_setup_docs_and_env_template_do_not_point_at_render_or_s3():
    env_example = read(".env.example")
    setup_doc = read("docs/submit-audiobook-setup.md")

    for content in (env_example, setup_doc):
        assert "accessible-audio-submit.onrender.com" not in content
        assert "AWS_ACCESS_KEY_ID" not in content
        assert "AWS_SECRET_ACCESS_KEY" not in content
        assert "S3_BUCKET_NAME" not in content

    assert "Hostinger" in setup_doc
    assert "private_uploads" in setup_doc
    assert not (ROOT / "render.yaml").exists()
