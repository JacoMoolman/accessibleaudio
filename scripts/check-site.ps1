$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$indexPath = Join-Path $root "index.html"
$audiobooksPath = Join-Path $root "audiobooks.html"
$contactPath = Join-Path $root "contact.html"
$faqPath = Join-Path $root "faq.html"
$voiceSamplesPath = Join-Path $root "voice-samples.html"
$robotsPath = Join-Path $root "robots.txt"
$sitemapPath = Join-Path $root "sitemap.xml"
$stylesPath = Join-Path $root "styles.css"
$faviconPath = Join-Path $root "favicon.svg"
$logoSvgPath = Join-Path $root "assets\accessible-audio-logo-round.svg"
$logoPngPath = Join-Path $root "assets\accessible-audio-logo-round-1024.png"
$deployPath = Join-Path $root "scripts\deploy-hostinger.ps1"
$videoScriptPath = Join-Path $root "scripts\video-embeds.js"
$siteMotionScriptPath = Join-Path $root "scripts\site-motion.js"
$contactScriptPath = Join-Path $root "scripts\contact.js"
$voiceCatalogScriptPath = Join-Path $root "scripts\voice-catalog.js"
$voiceSamplesScriptPath = Join-Path $root "scripts\voice-samples.js"
$submitIndexPath = Join-Path $root "submit\index.html"
$submitAppPath = Join-Path $root "submit\app.js"
$adminIndexPath = Join-Path $root "admin\index.html"
$adminAppPath = Join-Path $root "admin\app.js"
$adminStylesPath = Join-Path $root "admin\styles.css"
$contactEndpointPath = Join-Path $root "api\contact.php"
$apiLibPath = Join-Path $root "api\lib.php"
$apiConfigPath = Join-Path $root "api\config.php"

function Assert-FileExists {
  param([string] $Path)
  if (-not (Test-Path -LiteralPath $Path)) {
    throw "Missing required file: $Path"
  }
}

function Assert-Contains {
  param(
    [string] $Content,
    [string] $Needle,
    [string] $Label
  )
  if ($Content -notlike "*$Needle*") {
    throw "Expected $Label to contain '$Needle'"
  }
}

function Assert-NotContains {
  param(
    [string] $Content,
    [string] $Needle,
    [string] $Label
  )
  if ($Content -like "*$Needle*") {
    throw "Expected $Label not to contain '$Needle'"
  }
}

function Assert-NotMatch {
  param(
    [string] $Content,
    [string] $Pattern,
    [string] $Label
  )
  if ($Content -match $Pattern) {
    throw "Expected $Label not to match '$Pattern'"
  }
}

Assert-FileExists $indexPath
Assert-FileExists $audiobooksPath
Assert-FileExists $contactPath
Assert-FileExists $faqPath
Assert-FileExists $voiceSamplesPath
Assert-FileExists $robotsPath
Assert-FileExists $sitemapPath
Assert-FileExists $stylesPath
Assert-FileExists $faviconPath
Assert-FileExists $logoSvgPath
Assert-FileExists $logoPngPath
Assert-FileExists $deployPath
Assert-FileExists $videoScriptPath
Assert-FileExists $siteMotionScriptPath
Assert-FileExists $contactScriptPath
Assert-FileExists $voiceCatalogScriptPath
Assert-FileExists $voiceSamplesScriptPath
Assert-FileExists $submitIndexPath
Assert-FileExists $submitAppPath
Assert-FileExists $adminIndexPath
Assert-FileExists $adminAppPath
Assert-FileExists $adminStylesPath
Assert-FileExists $contactEndpointPath
Assert-FileExists $apiLibPath
Assert-FileExists $apiConfigPath

$index = Get-Content -LiteralPath $indexPath -Raw
$audiobooks = Get-Content -LiteralPath $audiobooksPath -Raw
$contact = Get-Content -LiteralPath $contactPath -Raw
$faq = Get-Content -LiteralPath $faqPath -Raw
$voiceSamples = Get-Content -LiteralPath $voiceSamplesPath -Raw
$robots = Get-Content -LiteralPath $robotsPath -Raw
$sitemap = Get-Content -LiteralPath $sitemapPath -Raw
$styles = Get-Content -LiteralPath $stylesPath -Raw
$deploy = Get-Content -LiteralPath $deployPath -Raw
$videoScript = Get-Content -LiteralPath $videoScriptPath -Raw
$siteMotionScript = Get-Content -LiteralPath $siteMotionScriptPath -Raw
$contactScript = Get-Content -LiteralPath $contactScriptPath -Raw
$voiceCatalogScript = Get-Content -LiteralPath $voiceCatalogScriptPath -Raw
$voiceSamplesScript = Get-Content -LiteralPath $voiceSamplesScriptPath -Raw
$submitIndex = Get-Content -LiteralPath $submitIndexPath -Raw
$submitApp = Get-Content -LiteralPath $submitAppPath -Raw
$adminIndex = Get-Content -LiteralPath $adminIndexPath -Raw
$adminApp = Get-Content -LiteralPath $adminAppPath -Raw
$adminStyles = Get-Content -LiteralPath $adminStylesPath -Raw
$contactEndpoint = Get-Content -LiteralPath $contactEndpointPath -Raw
$apiLib = Get-Content -LiteralPath $apiLibPath -Raw
$apiConfig = Get-Content -LiteralPath $apiConfigPath -Raw
$combined = "$index`n$audiobooks`n$contact"

Assert-Contains $index "Accessible Audio" "homepage"
Assert-Contains $index '<link rel="canonical" href="https://accessibleaudio.co.za/">' "homepage"
Assert-Contains $index '<meta property="og:url" content="https://accessibleaudio.co.za/">' "homepage"
Assert-Contains $index '<meta property="og:title" content="Accessible Audio | Locally Run AI Audiobooks">' "homepage"
Assert-Contains $index '<meta name="twitter:card" content="summary_large_image">' "homepage"
Assert-Contains $index "audiobooks.html" "homepage"
Assert-Contains $index '>Sample AudioBooks</a>' "homepage navigation"
Assert-Contains $audiobooks '>Sample AudioBooks</a>' "audiobook library navigation"
Assert-Contains $contact '>Sample AudioBooks</a>' "contact navigation"
Assert-Contains $faq '>Sample AudioBooks</a>' "FAQ navigation"
Assert-Contains $voiceSamples '>Sample AudioBooks</a>' "voice samples navigation"
Assert-Contains $submitIndex '>Sample AudioBooks</a>' "submit navigation"
Assert-Contains $index "voice-samples.html" "homepage"
Assert-Contains $index 'class="home-page"' "homepage"
Assert-Contains $index "hero-signal" "homepage"
Assert-Contains $index "styles.css?v=20260715-voices4" "homepage"
Assert-Contains $index "scripts/site-motion.js?v=20260715-motion2" "homepage"
Assert-NotContains $combined "https://accessible-audio-submit.onrender.com" "public pages"
Assert-Contains $index "Hear AI audiobook editions across languages." "homepage"
Assert-Contains $index "Browse audiobook samples" "homepage"
Assert-Contains $index "<strong>Peter Pan</strong>" "homepage"
Assert-NotContains $index "Listen by title, then choose the language edition." "homepage"
Assert-NotContains $index "listening library" "homepage"
Assert-NotContains $index "organized by book first" "homepage"
Assert-NotContains $index "data-video-id=""LB1Q60lAQAI""" "homepage"
Assert-NotContains $index "https://i.ytimg.com/vi/LB1Q60lAQAI/hqdefault.jpg" "homepage"
Assert-NotContains $index "Peter Pan - isiZulu" "homepage"
Assert-Contains $audiobooks "Audiobook Library" "audiobook library"
Assert-Contains $audiobooks '<link rel="canonical" href="https://accessibleaudio.co.za/audiobooks.html">' "audiobook library"
Assert-Contains $audiobooks '<meta property="og:url" content="https://accessibleaudio.co.za/audiobooks.html">' "audiobook library"
Assert-Contains $audiobooks '<meta property="og:title" content="Audiobook Library | Accessible Audio">' "audiobook library"
Assert-Contains $audiobooks '<meta name="twitter:card" content="summary_large_image">' "audiobook library"
Assert-Contains $audiobooks "Select a title and hear the available editions." "audiobook library"
Assert-Contains $audiobooks "Peter Pan" "audiobook library"
Assert-NotContains $audiobooks '<p class="section-kicker">Title</p>' "audiobook library"
Assert-NotContains $audiobooks "Books now sit in one growing catalogue." "audiobook library"
Assert-NotContains $audiobooks "Browse current AI audiobook examples by book title first" "audiobook library"
Assert-NotContains $audiobooks "Current listening library" "audiobook library"
Assert-NotContains $audiobooks "Add a title" "audiobook library"
Assert-NotContains $audiobooks "Build the next audiobook edition." "audiobook library"
Assert-Contains $audiobooks "data-video-id=""MmPTNK_cIcs""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""ZEi0RaIXFCM""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""gvB1WMaCNm4""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""mbdOKDtlP2s""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""oFkZpfG1BIc""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""aGPnxvuDhPw""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""8wwxt_uuaFY""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""nAzx8teBKUE""" "audiobook library"
Assert-Contains $audiobooks "data-video-id=""LB1Q60lAQAI""" "audiobook library"
Assert-Contains $audiobooks "https://i.ytimg.com/vi/MmPTNK_cIcs/hqdefault.jpg" "audiobook library"
Assert-Contains $audiobooks "https://i.ytimg.com/vi/LB1Q60lAQAI/hqdefault.jpg" "audiobook library"
Assert-NotContains $audiobooks "data-video-id=""Lp_DIbne5pY""" "audiobook library"
Assert-NotContains $audiobooks "data-video-id=""wLrHhoZoPns""" "audiobook library"
Assert-NotContains $audiobooks "data-video-id=""L16Rhnthe0o""" "audiobook library"
Assert-NotContains $audiobooks "data-video-id=""NIVOpA9Qs_s""" "audiobook library"
Assert-NotContains $audiobooks "data-video-id=""HniPyrcqrRA""" "audiobook library"
Assert-NotContains $audiobooks "data-video-id=""PujBGYcoSC0""" "audiobook library"
Assert-Contains $audiobooks "styles.css?v=20260715-voices4" "audiobook library"
Assert-Contains $audiobooks "scripts/site-motion.js?v=20260715-motion2" "audiobook library"
Assert-Contains $contact "styles.css?v=20260715-voices4" "contact page"
Assert-Contains $contact "scripts/site-motion.js?v=20260715-motion2" "contact page"
Assert-Contains $contact '<link rel="canonical" href="https://accessibleaudio.co.za/contact.html">' "contact page"
Assert-Contains $contact '<meta property="og:url" content="https://accessibleaudio.co.za/contact.html">' "contact page"
Assert-Contains $contact '<meta property="og:title" content="Contact | Accessible Audio AI Audiobooks">' "contact page"
Assert-Contains $contact '<meta name="twitter:card" content="summary_large_image">' "contact page"
Assert-Contains $styles "faded section backgrounds" "stylesheet"
Assert-Contains $styles "aa-photo-hero.webp" "stylesheet"
Assert-Contains $styles "aa-photo-access-gap.webp" "stylesheet"
Assert-Contains $styles "aa-photo-production.webp" "stylesheet"
Assert-Contains $styles "aa-photo-languages.webp" "stylesheet"
Assert-Contains $styles "aa-photo-samples.webp" "stylesheet"
Assert-Contains $styles "accessible-audio-logo-round.svg?v=20260624-logo1" "stylesheet"
Assert-Contains $index "accessible-audio-logo-round.svg?v=20260624-logo1" "homepage"
Assert-Contains $index "favicon.svg?v=20260612-private" "homepage"
Assert-Contains $contact "favicon.svg?v=20260612-private" "contact page"
Assert-NotContains $faq '<meta name="robots" content="noindex, nofollow">' "FAQ page"
Assert-Contains $faq '<a href="faq.html" aria-current="page">FAQ</a>' "FAQ page"
Assert-Contains $faq "Frequently asked questions." "FAQ page"
Assert-Contains $faq ".txt" "FAQ page"
Assert-Contains $faq ".docx" "FAQ page"
Assert-Contains $faq ".md" "FAQ page"
Assert-Contains $faq "You retain ownership of your book." "FAQ page"
Assert-Contains $faq "within 30 days" "FAQ page"
Assert-Contains $faq "What can and cannot be uploaded?" "FAQ page"
Assert-Contains $faq "Do not upload material that is illegal." "FAQ page"
Assert-Contains $faq "excessively explicit" "FAQ page"
Assert-Contains $faq "not automatically eligible for a refund" "FAQ page"
Assert-Contains $faq "styles.css?v=20260715-voices4" "FAQ page"
Assert-Contains $faq "scripts/site-motion.js?v=20260715-motion2" "FAQ page"
Assert-Contains $voiceSamples '<link rel="canonical" href="https://accessibleaudio.co.za/voice-samples.html">' "voice samples page"
Assert-Contains $voiceSamples "Numbered voice catalogue" "voice samples page"
Assert-Contains $voiceSamples "same number appears in the" "voice samples page"
Assert-Contains $voiceSamples "styles.css?v=20260715-voices4" "voice samples page"
Assert-Contains $voiceSamples "scripts/site-motion.js?v=20260715-motion2" "voice samples page"
Assert-NotContains $voiceSamples "<select" "voice samples page"
Assert-NotMatch "$voiceSamples`n$voiceSamplesScript`n$voiceCatalogScript" "(?i)gemini|google text|omnivoice|voice provider|local ai" "public voice sample interface"
Assert-Contains $voiceSamples 'id="local-voice-list"' "voice samples page"
Assert-Contains $voiceSamples 'id="cloud-voice-list"' "voice samples page"
Assert-Contains $voiceSamples "Local voices" "voice samples page"
Assert-Contains $voiceSamples "Cloud voices" "voice samples page"
Assert-Contains $voiceSamples "0.5c" "voice samples page"
Assert-Contains $voiceSamples "0.75c" "voice samples page"
Assert-NotContains $voiceSamples "voice-provider-button" "voice samples page"
Assert-Contains $styles ".voice-card-actions button:focus-visible" "stylesheet"
Assert-Contains $styles "background: var(--soft);" "stylesheet"
Assert-NotContains $styles "#fffdf7" "stylesheet"
Assert-Contains $voiceCatalogScript "const voiceCount = 35" "voice catalog script"
Assert-Contains $voiceCatalogScript "const localVoiceCount = 5" "voice catalog script"
Assert-Contains $voiceCatalogScript "cloudCostPerWordCents = localCostPerWordCents * 1.5" "voice catalog script"
Assert-Contains $voiceCatalogScript "/assets/voice-samples/catalog/voice-" "voice catalog script"
Assert-Contains $voiceSamplesScript "window.ACCESSIBLE_AUDIO_VOICES" "voice sample script"
Assert-Contains $submitIndex "../scripts/voice-catalog.js?v=20260715-voices4" "submit page"
Assert-Contains $submitIndex "./styles.css?v=20260715-google1" "submit page"
Assert-Contains $submitIndex "./app.js?v=20260716-text1" "submit page"
Assert-Contains $submitIndex 'id="stop-narrator-sample" disabled' "submit page"
Assert-Contains $submitApp '/api/delete-file.php' "submit script"
Assert-Contains $submitApp 'data-delete-upload' "submit script"
Assert-Contains $submitApp '/api/payment.php' "submit script"
Assert-Contains $submitApp 'data-pay-upload' "submit script"
Assert-Contains $submitIndex "../scripts/site-motion.js?v=20260715-motion2" "submit page"
Assert-NotContains $submitIndex "The numbers match the" "submit page"
Assert-Contains $submitIndex "Local: 0.5c/word." "submit page"
Assert-Contains $submitIndex "Cloud: 0.75c/word." "submit page"
Assert-Contains $submitIndex 'href="https://accessibleaudio.co.za/voice-samples.html">Voice samples</a>' "submit page"
Assert-Contains $submitApp "VOICE_CATALOG.map" "submit app"
Assert-Contains $submitApp "populateNarratorVoices" "submit app"
Assert-Contains $submitApp 'document.createElement("optgroup")' "submit app"
Assert-Contains $submitApp 'selectedVoice.costPerWordCents' "submit app"
Assert-Contains $submitIndex 'id="google-button"' "submit page"
Assert-Contains $submitIndex "Continue with Google" "submit page"
Assert-Contains $submitIndex "never receives or stores your Google password" "submit page"
Assert-NotContains $submitIndex 'id="email"' "submit page"
Assert-NotContains $submitIndex 'id="password"' "submit page"
Assert-NotContains $submitIndex 'id="login-button"' "submit page"
Assert-NotContains $submitIndex 'id="signup-button"' "submit page"
Assert-NotContains $submitIndex "turnstile/v0/api.js" "submit page"
Assert-Contains $submitApp 'provider: "google"' "submit app"
Assert-NotContains $submitApp "signInWithPassword" "submit app"
Assert-NotContains $submitApp "auth.signUp" "submit app"
Assert-NotContains $submitApp "tryTestLogin" "submit app"
Assert-Contains $adminIndex 'content="noindex,nofollow,noarchive"' "admin page"
Assert-Contains $adminIndex "Continue with Google" "admin page"
Assert-Contains $adminApp 'provider: "google"' "admin app"
Assert-Contains $adminApp '/api/admin-files.php' "admin app"
Assert-Contains $adminApp 'response.blob()' "admin app"
Assert-Contains $adminStyles "@media (prefers-reduced-motion: reduce)" "admin stylesheet"
Assert-NotContains $sitemap "/admin/" "sitemap"
Assert-Contains $index '<a href="faq.html">FAQ</a>' "homepage"
Assert-Contains $audiobooks '<a href="faq.html">FAQ</a>' "audiobook library"
Assert-Contains $contact '<a href="faq.html">FAQ</a>' "contact page"
Assert-Contains $voiceSamples '<a href="faq.html">FAQ</a>' "voice samples page"
Assert-NotContains $index "scripts/video-embeds.js" "homepage"
Assert-Contains $audiobooks "scripts/video-embeds.js?v=20260626-nocookie1" "audiobook library"
Assert-Contains $videoScript "https://www.youtube-nocookie.com/embed/" "video script"
Assert-NotContains $videoScript "https://www.youtube.com/embed/" "video script"
Assert-Contains $index "Locally Run AI Audiobooks" "homepage"
Assert-Contains $index "AI audiobook production" "homepage"
Assert-Contains $index "AI-generated audiobook" "homepage"
Assert-Contains $index "Audiobook generation is handled locally." "homepage"
Assert-Contains $index "commercial cloud" "homepage"
Assert-NotContains $index "pushed into commercial cloud" "homepage"
Assert-NotContains $index "can reasonably sit around" "homepage"
Assert-NotContains $index "voice samples ready to demonstrate today" "homepage"
Assert-NotContains $combined "production workflow" "public pages"
Assert-NotContains $combined "production stack" "public pages"
Assert-Contains $index "Optional hosting support" "homepage"
Assert-Contains $index "85,000-word book usually takes about 2.5 months to complete" "homepage"
Assert-NotContains $combined "voice artist's availability" "public pages"
Assert-Contains $contact "pilot AI audiobook" "contact page"
Assert-Contains $contact "Discuss an audiobook pilot for your catalogue." "contact page"
Assert-Contains $index "isiZulu" "homepage"
Assert-Contains $index "Xhosa" "homepage"
Assert-Contains $index "Pedi" "homepage"
Assert-Contains $index "Setswana" "homepage"
Assert-Contains $index "Each language is" "homepage"
Assert-NotContains $index "Sesotho" "homepage"
Assert-Contains $index "Afrikaans" "homepage"
Assert-NotContains $index "isiNdebele" "homepage"
Assert-NotContains $index "siSwati" "homepage"
Assert-NotContains $index "Tshivenda" "homepage"
Assert-NotContains $index "XiTsonga" "homepage"
Assert-Contains $index "Publishing a book does not automatically make it accessible." "homepage"
Assert-Contains $index "Traditional audiobook production is expensive." "homepage"
Assert-Contains $index "R60,000-R85,000" "homepage"
Assert-Contains $index "Sample audiobooks" "homepage"
Assert-Contains $audiobooks "character-voice narration demo" "audiobook library"
Assert-Contains $index "Book-to-audio production" "homepage"
Assert-Contains $index "Open-source model foundation" "homepage"
Assert-Contains $index "Review-ready audio files" "homepage"
Assert-Contains $index "The Wonderful Wizard of Oz" "homepage"
Assert-Contains $index "isiZulu" "homepage"
Assert-Contains $index "Alice In Wonderland" "homepage"
Assert-Contains $index "Character voices" "homepage"
Assert-Contains $audiobooks "The Wonderful Wizard of Oz" "audiobook library"
Assert-Contains $audiobooks "Peter Pan" "audiobook library"
Assert-Contains $audiobooks "isiZulu" "audiobook library"
Assert-Contains $audiobooks "Afrikaans" "audiobook library"
Assert-Contains $audiobooks "Alice In Wonderland" "audiobook library"
Assert-Contains $audiobooks "Character voices" "audiobook library"
Assert-NotMatch $combined "(?<!isi)\bZulu\b" "public pages"
Assert-NotContains $index "AI Audiobook<br><strong>Sample edition" "homepage"
Assert-NotContains "$index`n$audiobooks`n$faq`n$contact" "mailto:" "public pages"
Assert-NotContains "$index`n$audiobooks`n$faq`n$contact" "@accessibleaudio.co.za" "public pages"
Assert-Contains $contact 'id="contact-form"' "contact page"
Assert-Contains $contact 'id="contact-captcha"' "contact page"
Assert-Contains $contact "www.google.com/recaptcha/api.js" "contact page"
Assert-Contains $contact "scripts/contact.js?v=20260714-contact1" "contact page"
Assert-Contains $contactScript 'fetch("/api/contact.php"' "contact script"
Assert-Contains $contactScript "window.grecaptcha.getResponse" "contact script"
Assert-Contains $contactEndpoint "send_contact_email" "contact endpoint"
Assert-Contains $contactEndpoint "AUTH LOGIN" "contact endpoint"
Assert-Contains $contactEndpoint "stream_socket_client" "contact endpoint"
Assert-NotMatch $contactEndpoint "(?<![A-Za-z0-9_])mail\(" "contact endpoint"
Assert-Contains $apiLib "contact_smtp_password" "API library"
Assert-NotMatch $apiConfig "(?i)smtp|email_password" "public API config"
Assert-Contains $deploy "Get-ChildItem" "deployment script"
Assert-Contains $deploy "audiobooks.html" "deployment script"
Assert-Contains $deploy '"faq.html"' "deployment script"
Assert-Contains $deploy '"voice-samples.html"' "deployment script"
Assert-Contains $deploy '"scripts/voice-samples.js"' "deployment script"
Assert-Contains $deploy '"scripts/voice-catalog.js"' "deployment script"
Assert-Contains $deploy '"scripts/site-motion.js"' "deployment script"
Assert-Contains $deploy '"scripts/contact.js"' "deployment script"
Assert-Contains $deploy "robots.txt" "deployment script"
Assert-Contains $deploy "sitemap.xml" "deployment script"
Assert-Contains $robots "User-agent: *" "robots.txt"
Assert-Contains $robots "Allow: /" "robots.txt"
Assert-Contains $robots "Sitemap: https://accessibleaudio.co.za/sitemap.xml" "robots.txt"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/</loc>" "sitemap.xml"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/audiobooks.html</loc>" "sitemap.xml"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/contact.html</loc>" "sitemap.xml"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/voice-samples.html</loc>" "sitemap.xml"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/faq.html</loc>" "sitemap.xml"

Assert-NotContains $index "youtube.com/watch" "homepage"
Assert-NotContains $audiobooks "youtube.com/watch" "audiobook library"
Assert-NotContains $combined "chapter-by-chapter" "public pages"
Assert-NotContains $combined "chapter by chapter" "public pages"
Assert-NotContains $combined "how I do it" "public pages"
Assert-Contains $siteMotionScript "IntersectionObserver" "homepage motion script"
Assert-Contains $siteMotionScript "prefers-reduced-motion: reduce" "homepage motion script"
Assert-Contains $siteMotionScript "requestAnimationFrame" "homepage motion script"
Assert-Contains $styles "@media (prefers-reduced-motion: reduce)" "stylesheet"

foreach ($asset in @(
  "assets\accessible-audio-hero.png",
  "assets\accessibility-language-bridge.png",
  "assets\aa-photo-hero.webp",
  "assets\aa-photo-access-gap.webp",
  "assets\aa-photo-production.webp",
  "assets\aa-photo-languages.webp",
  "assets\aa-photo-samples.webp"
)) {
  Assert-FileExists (Join-Path $root $asset)
}

foreach ($number in 1..5) {
  Assert-FileExists (Join-Path $root ("assets\voice-samples\catalog\voice-{0:D2}.wav" -f $number))
}
foreach ($number in 6..35) {
  Assert-FileExists (Join-Path $root ("assets\voice-samples\catalog\voice-{0:D2}.mp3" -f $number))
}

Write-Host "Site checks passed."
