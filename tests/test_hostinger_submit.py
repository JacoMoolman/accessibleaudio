import json
import re
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_hostinger_submit_frontend_uses_php_api_and_client_side_analysis():
    html = read("submit/index.html")
    app_js = read("submit/app.js")

    assert 'href="./styles.css?v=20260718-payfast1"' in html
    assert 'src="./app.js?v=20260719-payfast3"' in html
    assert 'fetchJson("/api/config.php")' in app_js
    assert 'fetchJson("/api/process-file.php"' in app_js
    assert 'fetchJson("/api/files.php"' in app_js
    assert 'analyzeTextFile(file)' in app_js
    assert 'fetchJson("/analyze-file"' not in app_js
    assert 'fetchJson("/process-file"' not in app_js
    assert 'fetchJson("/files"' not in app_js
    assert 'redirectTo: `${window.location.origin}/submit/`' in app_js
    assert "onrender.com" not in app_js


def test_submit_login_is_google_only_and_does_not_collect_passwords():
    html = read("submit/index.html")
    app_js = read("submit/app.js")
    submit_styles = read("submit/styles.css")

    assert 'id="google-button"' in html
    assert "Continue with Google" in html
    assert "never receives or stores your Google password" in html
    assert 'id="email"' not in html
    assert 'id="password"' not in html
    assert 'id="login-button"' not in html
    assert 'id="signup-button"' not in html
    assert "turnstile/v0/api.js" not in html
    assert 'provider: "google"' in app_js
    assert 'queryParams: { prompt: "select_account" }' in app_js
    assert "signInWithPassword" not in app_js
    assert "auth.signUp" not in app_js
    assert "tryTestLogin" not in app_js
    assert ".google-auth-button" in submit_styles
    assert ".auth-assurance-dot" in submit_styles


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


def test_submit_uses_text_wording_and_omits_redundant_voice_page_link():
    for path in ("submit/index.html", "frontend/index.html"):
        page = read(path)
        assert "TXT" not in page
        assert "Upload an audiobook-ready text file." in page
        assert "voice sample page" not in page

    for path in ("submit/app.js", "frontend/app.js"):
        assert "TXT" not in read(path)


def test_voice_sample_controls_use_the_dark_site_palette():
    html = read("voice-samples.html")
    styles = read("styles.css")

    assert "styles.css?v=20260718-voicefit1" in html
    assert ".voice-card-actions button:focus-visible" in styles
    assert "#fffdf7" not in styles
    assert "background: rgba(7, 61, 53, 0.72);" in styles
    assert "background: var(--soft);" in styles
    assert "grid-template-columns: repeat(2, minmax(0, 1fr));" in styles
    assert ".voice-card-actions button {" in styles
    assert "min-width: 0;" in styles


def test_submit_narrator_sample_has_working_stop_control():
    page = read("submit/index.html")
    app_js = read("submit/app.js")
    submit_styles = read("submit/styles.css")

    assert 'id="play-narrator-sample"' in page
    assert 'id="stop-narrator-sample" disabled' in page
    assert "styles.css?v=20260718-payfast1" in page
    assert "app.js?v=20260719-payfast3" in page
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


def test_existing_upload_rows_restore_primary_checkout_without_a_secondary_button():
    app_js = read("submit/app.js")

    assert 'fetchJson("/api/payment.php"' in app_js
    assert 'files.find((file) => file.status === "uploaded")' in app_js
    assert "restorePendingPaymentCheckout" in app_js
    assert "data-pay-upload" not in app_js
    assert "Pay now" not in app_js
    assert "renderPaymentCheckout(data.payment)" in app_js
    assert "Pay with PayFast" in app_js


def test_primary_payfast_form_uses_native_navigation_and_keeps_terms_accepted():
    page = read("submit/index.html")
    app_js = read("submit/app.js")

    assert 'id="payfast-form" method="post" target="_self"' in page
    handler_start = app_js.index('payfastForm.addEventListener("submit"')
    handler_end = app_js.index("async function analyzeSelectedFile")
    payfast_handler = app_js[handler_start:handler_end]
    assert 'if (!payfastForm.getAttribute("action"))' in payfast_handler
    assert payfast_handler.count("event.preventDefault()") == 1
    assert "payfastForm.submit()" not in payfast_handler
    assert 'document.getElementById("terms-accepted").checked = true' in app_js


def test_payfast_self_payment_asks_for_an_alternate_payer_email():
    page = read("submit/index.html")
    app_js = read("submit/app.js")
    lib_php = read("api/lib.php")

    assert 'id="payment-payer-note"' in page
    assert "PayFast does not allow the merchant account to pay itself" in page
    assert "payment.requires_alternate_payer_email" in app_js
    assert "requires_alternate_payer_email" in lib_php
    assert "if ($userEmail !== '' && !$requiresAlternatePayerEmail)" in lib_php


def test_submission_requires_versioned_terms_and_links_to_full_page():
    page = read("submit/index.html")
    app_js = read("submit/app.js")
    terms = read("terms.html")
    process_file = read("api/process-file.php")

    assert 'id="terms-accepted" type="checkbox" required' in page
    assert 'href="https://accessibleaudio.co.za/terms.html" target="_blank" rel="noopener"' in page
    assert 'formData.append("terms_accepted"' in app_js
    assert 'formData.append("terms_version", "2026-07-18")' in app_js
    assert "$termsVersion = '2026-07-18'" in process_file
    assert "Terms and conditions." in terms
    assert 'href="submit/"' in terms
    assert "bool_value('terms_accepted')" in process_file
    assert "hash_equals($termsVersion" in process_file
    assert process_file.index("terms_accepted") < process_file.index("validate_upload(")


def test_upload_is_rejected_before_storage_when_payfast_is_not_configured():
    process_file = read("api/process-file.php")
    php_lib = read("api/lib.php")

    assert "if (!payfast_configured($config))" in process_file
    assert "No book was uploaded" in process_file
    assert process_file.index("payfast_configured($config)") < process_file.index("validate_upload(")
    assert "function payfast_configured(array $config): bool" in php_lib


def test_unsigned_checkout_is_restricted_to_payfast_public_sandbox_account():
    php_lib = read("api/lib.php")
    example = read("api/config.local.example.php")

    assert "function payfast_uses_unsigned_shared_sandbox(array $config): bool" in php_lib
    assert "hash_equals('10000100'" in php_lib
    assert "hash_equals('46f0cd694581a'" in php_lib
    assert "if (!payfast_uses_unsigned_shared_sandbox($config))" in php_lib
    assert "PAYFAST_UNSIGNED_SANDBOX" in example
    assert "'PAYFAST_UNSIGNED_SANDBOX' => false" in example


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


def test_numbered_voice_catalog_is_cloud_only_and_priced_without_naming_vendors():
    page = read("voice-samples.html")
    page_js = read("scripts/voice-samples.js")
    catalog_js = read("scripts/voice-catalog.js")
    submit_html = read("submit/index.html")
    submit_js = read("submit/app.js")
    php_lib = read("api/lib.php")
    process_file = read("api/process-file.php")

    public_voice_interface = "\n".join((page, page_js, catalog_js)).lower()
    for provider_name in ("gemini", "google text", "omnivoice", "voice provider", "local ai", "grok"):
        assert provider_name not in public_voice_interface

    assert "const firstVoiceNumber = 1" in catalog_js
    assert "const voiceCount = 30" in catalog_js
    assert "const sampleFileOffset = 5" in catalog_js
    assert "const costPerWordCents = 0.75" in catalog_js
    assert 'typeLabel: "Voice narration"' in catalog_js
    assert "/assets/voice-samples/catalog/voice-" in catalog_js
    assert "window.ACCESSIBLE_AUDIO_VOICES" in page_js
    assert 'id="voice-list"' in page
    assert "Available voices" in page
    assert "0.75c" in page
    assert "voice-provider-button" not in page
    assert "../scripts/voice-catalog.js?v=20260719-voice1" in submit_html
    assert "The numbers match the" not in submit_html
    assert "Voice narration: 0.75c/word." in submit_html
    assert 'href="https://accessibleaudio.co.za/voice-samples.html">Voice samples</a>' in submit_html
    assert "VOICE_CATALOG.map" in submit_js
    assert "populateNarratorVoices" in submit_js
    assert 'document.createElement("optgroup")' in submit_js
    assert "Choose a voice to calculate the production price." in submit_js
    assert "selectedVoice.costPerWordCents" in submit_js
    assert "voice.availableForProduction" in submit_js
    assert "availableForProduction: true" in catalog_js
    assert "VOICE_COST_PER_WORD_CENTS = 0.75" in php_lib
    assert "1 => 'Zephyr'" in php_lib
    assert "30 => 'Sulafat'" in php_lib
    assert "^Voice ([1-9]|[12][0-9]|30)$" in php_lib
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
    assert "enforce_rate_limit($config, 'contact', 5, 3600)" in endpoint
    assert endpoint.index("enforce_rate_limit($config, 'contact'") < endpoint.index("verify_recaptcha(")
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


def test_production_security_controls_are_fail_closed_and_deployed():
    root_htaccess = read(".htaccess")
    api_htaccess = read("api/.htaccess")
    private_htaccess = read("private_uploads/.htaccess")
    deploy = read("scripts/deploy-hostinger.ps1")
    php_lib = read("api/lib.php")

    for header in (
        "Strict-Transport-Security",
        "Content-Security-Policy",
        "X-Content-Type-Options",
        "X-Frame-Options",
        "Referrer-Policy",
        "Permissions-Policy",
    ):
        assert header in root_htaccess
    assert "frame-ancestors 'none'" in root_htaccess
    assert "object-src 'none'" in root_htaccess
    assert "script-src 'self'" in root_htaccess
    assert "script-src 'self' 'unsafe-inline'" not in root_htaccess
    assert "form-action 'self' https://*.payfast.co.za https://*.payfast.io" in root_htaccess
    assert "Options -Indexes" in root_htaccess
    assert "LimitRequestBody 11534336" in api_htaccess
    for private_source in (
        "config\\.local",
        "config\\.public\\.php",
        "lib\\.php",
        "smtp\\.php",
        "test-login\\.php",
    ):
        assert private_source in api_htaccess
    assert "Require all denied" in private_htaccess

    assert "$request.EnableSsl = $true" in deploy
    assert "[switch] $SkipUnchangedAssets" in deploy
    assert "[switch] $ChangedOnly" in deploy
    assert "RemoteCertificateNameMismatch" in deploy
    assert ".hstgr.io" in deploy
    assert '".htaccess"' in deploy
    assert '$_.Name -ne "test-login.php"' in deploy
    assert '"api/test-login.php"' in deploy

    assert "function enforce_rate_limit(" in php_lib
    assert "$_SERVER['REMOTE_ADDR']" in php_lib
    assert "function reject_large_request(" in php_lib
    assert "$_SERVER['HTTP_HOST']" not in php_lib
    assert "PUBLIC_BASE_URL" in php_lib


def test_costly_api_work_is_rate_limited_before_external_calls():
    endpoints = {
        "process-file": read("api/process-file.php"),
        "files": read("api/files.php"),
        "payment": read("api/payment.php"),
        "delete": read("api/delete-file.php"),
        "admin-delete": read("api/admin-delete.php"),
        "admin-files": read("api/admin-files.php"),
        "admin-download": read("api/admin-download.php"),
    }
    for name, endpoint in endpoints.items():
        assert "enforce_rate_limit($config, 'auth', 120, 60)" in endpoint, name
        assert endpoint.index("enforce_rate_limit(") < endpoint.index("current_user("), name

    process_file = endpoints["process-file"]
    assert "enforce_rate_limit($config, 'upload', 12, 600)" in process_file
    assert "reject_large_request($config['max_upload_bytes'] + 1024 * 1024)" in process_file

    payfast_notify = read("api/payfast-notify.php")
    assert "enforce_rate_limit($config, 'payfast-itn', 120, 60)" in payfast_notify
    assert payfast_notify.index("enforce_rate_limit(") < payfast_notify.index("payfast_server_validation(")


def test_external_supabase_script_is_exactly_pinned_with_sri():
    pages = [read("submit/index.html"), read("admin/index.html"), read("frontend/index.html")]
    expected = "@supabase/supabase-js@2.110.5"
    integrity = "sha384-Fntl9b+IRzm2GKZK0c129fQFknWsn8pyxDejLO4wwds1LF9DSob2K2QXlfw8EIXn"
    for page in pages:
        assert expected in page
        assert integrity in page
        assert 'crossorigin="anonymous"' in page
        assert "@supabase/supabase-js@2\"" not in page


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
        assert "styles.css?v=20260718-voicefit1" in page
        assert "scripts/site-motion.js?v=20260715-motion2" in page
    assert "./styles.css?v=20260718-payfast1" in submit
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
    assert "What can and cannot be uploaded?" in faq
    assert "Do not upload material that is illegal." in faq
    assert "excessively explicit" in faq
    assert "not automatically eligible for a refund" in faq
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


def test_public_search_indexing_signals_are_canonical_and_complete():
    homepage = read("index.html")
    submit = read("submit/index.html")
    sitemap = read("sitemap.xml")
    htaccess = read(".htaccess")

    json_ld_match = re.search(
        r'<script type="application/ld\+json">\s*(.*?)\s*</script>',
        homepage,
        flags=re.DOTALL,
    )
    assert json_ld_match is not None
    structured_data = json.loads(json_ld_match.group(1))
    types = {item["@type"] for item in structured_data["@graph"]}
    assert types == {"WebSite", "Organization"}
    assert structured_data["@graph"][0]["url"] == "https://accessibleaudio.co.za/"

    assert '<link rel="canonical" href="https://accessibleaudio.co.za/submit/">' in submit
    assert '<meta name="description"' in submit
    assert '<meta property="og:url" content="https://accessibleaudio.co.za/submit/">' in submit
    assert '<meta name="twitter:card" content="summary_large_image">' in submit
    assert "<loc>https://accessibleaudio.co.za/submit/</loc>" in sitemap
    assert "RewriteRule ^index\\.html$ https://accessibleaudio.co.za/ [R=301,L]" in htaccess
    assert "RewriteCond %{HTTP_HOST} ^www\\.accessibleaudio\\.co\\.za$ [NC]" in htaccess

    homepage = read("index.html")
    assert '<a href="submit/">Submit</a>' not in homepage

    for page_path in (
        "audiobooks.html",
        "contact.html",
        "faq.html",
        "terms.html",
        "voice-samples.html",
    ):
        page = read(page_path)
        assert 'href="index.html' not in page
        assert '<a href="submit/">Submit</a>' in page


def test_all_public_headers_label_the_library_as_sample_audiobooks():
    relative_nav_pages = [
        read("index.html"),
        read("audiobooks.html"),
        read("contact.html"),
        read("faq.html"),
        read("voice-samples.html"),
    ]
    for page in relative_nav_pages:
        assert ">Sample AudioBooks</a>" in page
        assert ">Audiobooks</a>" not in page

    absolute_nav_pages = [read("submit/index.html"), read("frontend/index.html")]
    for page in absolute_nav_pages:
        assert '<a href="https://accessibleaudio.co.za/audiobooks.html">Sample AudioBooks</a>' in page
        assert ">Audiobooks</a>" not in page


def test_paid_payfast_notifications_are_verified_and_idempotent():
    endpoint = read("api/payfast-notify.php")
    smtp = read("api/smtp.php")
    php_lib = read("api/lib.php")
    process_file = read("api/process-file.php")

    assert "payfast_notification_signature" in endpoint
    assert "!payfast_uses_unsigned_shared_sandbox($config)" in endpoint
    assert "if ($key === 'signature')" in endpoint
    assert "continue;" in endpoint[endpoint.index("function payfast_notification_signature"):]
    assert "payfast_server_validation" in endpoint
    assert "payfast_itn_audit" in endpoint
    assert "signature_mismatch" in endpoint
    assert "server_validation_failed" in endpoint
    assert "payfast-itn-audit.jsonl" in endpoint
    assert "/eng/query/validate" in endpoint
    assert "payment_status" in endpoint and "COMPLETE" in endpoint
    assert "Payment amount does not match the upload" in endpoint
    assert "total_cost_cents" in endpoint
    assert "admin_notification_claim" in endpoint
    assert "admin_notified_at" in endpoint
    assert "aa_send_smtp_email" in endpoint
    assert "AUTH LOGIN" in smtp
    assert "function update_upload_record" in php_lib
    assert "function find_upload_record_any" in php_lib
    assert "'user_email' => strtolower" in process_file
    assert "$record['status'] = 'queued'" in endpoint
    assert "['uploaded', 'paid']" in endpoint


def test_paid_orders_are_processed_server_side_into_downloadable_mp3_chapters():
    production = read("api/production.php")
    worker = read("api/process-queue.php")
    download = read("api/download-audio.php")
    user_files = read("api/files.php")
    user_app = read("submit/app.js")
    admin_files = read("api/admin-files.php")

    assert "PHP_SAPI !== 'cli'" in worker
    assert "run_production_worker" in worker
    assert "split_book_into_chapters" in production
    assert "chunk_speech_text" in production
    assert "generate_tts_chunk" in production
    assert "google/gemini-3.1-flash-tts-preview" in read("api/lib.php")
    assert "join_pcm_chunks_as_mp3" in production
    assert "encode_pcm_as_mp3" in production
    assert "proc_open($command" in production
    assert "'lame_binary'" in read("api/lib.php")
    assert "response_format' => 'pcm'" in production
    assert "completion_email_sent_at" in production
    assert "audio/mpeg" in download
    assert "current_user($config)" in download
    assert "download_url" in user_files
    assert "data-download-audio" in user_app
    assert "outputs" in admin_files


def test_mp3_is_the_only_delivery_format_and_is_explained_publicly():
    submit_html = read("submit/index.html")
    submit_js = read("submit/app.js")
    faq = read("faq.html")
    terms = read("terms.html")

    assert "Output Format" not in submit_html
    assert 'name="output-format"' not in submit_html
    assert "chapter-ready MP3 files" in submit_html
    assert 'formData.append("output_format", "mp3")' in submit_js
    assert 'formData.append("also_wav", "false")' in submit_js
    assert '"chapter.mp3"' in submit_js
    assert "audioFormat(output.filename)" in submit_js
    assert "Which audio format will I receive?" in faq
    assert "there is no separate output-format choice" in faq
    assert "Completed audiobooks are delivered as chapter-ready <strong>MP3 files</strong>" in terms
    assert "Effective 18 July 2026" in terms


def test_private_admin_queue_requires_configured_google_admin_and_secure_download():
    html = read("admin/index.html")
    app_js = read("admin/app.js")
    styles = read("admin/styles.css")
    endpoint = read("api/admin-files.php")
    admin_delete = read("api/admin-delete.php")
    download = read("api/admin-download.php")
    php_lib = read("api/lib.php")
    deploy = read("scripts/deploy-hostinger.ps1")
    sitemap = read("sitemap.xml")

    assert 'content="noindex,nofollow,noarchive"' in html
    assert "Continue with Google" in html
    assert 'provider: "google"' in app_js
    assert 'redirectTo: `${window.location.origin}/admin/`' in app_js
    assert 'fetchJson("/api/admin-files.php"' in app_js
    assert "Authorization: `Bearer ${currentSession.access_token}`" in app_js
    assert "response.blob()" in app_js
    assert 'fetchJson("/api/admin-delete.php"' in app_js
    assert "data-delete-job" in app_js
    assert "all generated audio? This cannot be undone." in app_js
    assert "require_admin($config, $user)" in endpoint
    assert "list_production_records" in endpoint
    assert "require_admin($config, $user)" in download
    assert "find_upload_record_any" in download
    assert "require_admin($config, $user)" in admin_delete
    assert "find_upload_record_any" in admin_delete
    assert "delete_upload_record($uploadDir, $ownerId, $uploadId)" in admin_delete
    assert "Wait for production to finish" in admin_delete
    assert "Content-Disposition: attachment" in download
    assert "str_starts_with($realPath, $root . DIRECTORY_SEPARATOR)" in download
    assert "function require_admin" in php_lib
    assert "hash_equals($adminEmail, $userEmail)" in php_lib
    assert "@media (prefers-reduced-motion: reduce)" in styles
    assert '"admin/index.html"' in deploy
    assert '"admin/app.js"' in deploy
    assert '"admin/styles.css"' in deploy
    assert "/admin/" not in sitemap


def test_admin_job_layout_keeps_metadata_readable_beside_multiple_downloads():
    html = read("admin/index.html")
    app_js = read("admin/app.js")
    styles = read("admin/styles.css")

    assert 'styles.css?v=20260719-delete1' in html
    assert 'app.js?v=20260719-delete1' in html
    assert "grid-template-columns: minmax(220px, .7fr) minmax(0, 1.4fr);" in styles
    assert ".download-list { display: flex; grid-column: 1 / -1;" in styles
    assert "dl div:last-child { grid-column: 1 / -1; }" in styles
    assert "dl div:last-child { grid-column: auto; }" in styles
    assert "minmax(0, 1.4fr) auto" not in styles
    assert "audioFormat(output.filename)" in app_js
    assert "function audioFormat(filename)" in app_js
    assert 'deleteButton.textContent = "Delete book and audio"' in app_js
    assert ".delete-button" in styles


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
    assert '"api/bin/.htaccess"' in deploy_script
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
