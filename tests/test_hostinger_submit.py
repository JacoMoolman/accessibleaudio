from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def test_hostinger_submit_frontend_uses_php_api_and_client_side_analysis():
    html = read("submit/index.html")
    app_js = read("submit/app.js")

    assert 'href="./styles.css"' in html
    assert 'src="./app.js"' in html
    assert 'fetchJson("/api/config.php")' in app_js
    assert 'fetchJson("/api/process-file.php"' in app_js
    assert 'fetchJson("/api/files.php"' in app_js
    assert 'analyzeTextFile(file)' in app_js
    assert 'fetchJson("/analyze-file"' not in app_js
    assert 'fetchJson("/process-file"' not in app_js
    assert 'fetchJson("/files"' not in app_js


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

    assert "styles.css?v=20260714-voices2" in html
    assert ".voice-card-actions button:focus-visible" in styles
    assert "#fffdf7" not in styles
    assert "background: rgba(7, 61, 53, 0.72);" in styles
    assert "background: var(--soft);" in styles


def test_homepage_polish_is_deployed_and_respects_reduced_motion():
    html = read("index.html")
    styles = read("styles.css")
    motion = read("scripts/site-motion.js")
    deploy = read("scripts/deploy-hostinger.ps1")

    assert 'class="home-page"' in html
    assert "hero-signal" in html
    assert "styles.css?v=20260714-motion1" in html
    assert "scripts/site-motion.js?v=20260714-motion1" in html
    assert '"scripts/site-motion.js"' in deploy
    assert "IntersectionObserver" in motion
    assert "requestAnimationFrame" in motion
    assert "prefers-reduced-motion: reduce" in motion
    assert "@media (prefers-reduced-motion: reduce)" in styles


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
