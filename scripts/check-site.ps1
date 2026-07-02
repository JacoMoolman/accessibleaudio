$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$indexPath = Join-Path $root "index.html"
$audiobooksPath = Join-Path $root "audiobooks.html"
$contactPath = Join-Path $root "contact.html"
$robotsPath = Join-Path $root "robots.txt"
$sitemapPath = Join-Path $root "sitemap.xml"
$stylesPath = Join-Path $root "styles.css"
$faviconPath = Join-Path $root "favicon.svg"
$logoSvgPath = Join-Path $root "assets\accessible-audio-logo-round.svg"
$logoPngPath = Join-Path $root "assets\accessible-audio-logo-round-1024.png"
$deployPath = Join-Path $root "scripts\deploy-hostinger.ps1"
$videoScriptPath = Join-Path $root "scripts\video-embeds.js"

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
Assert-FileExists $robotsPath
Assert-FileExists $sitemapPath
Assert-FileExists $stylesPath
Assert-FileExists $faviconPath
Assert-FileExists $logoSvgPath
Assert-FileExists $logoPngPath
Assert-FileExists $deployPath
Assert-FileExists $videoScriptPath

$index = Get-Content -LiteralPath $indexPath -Raw
$audiobooks = Get-Content -LiteralPath $audiobooksPath -Raw
$contact = Get-Content -LiteralPath $contactPath -Raw
$robots = Get-Content -LiteralPath $robotsPath -Raw
$sitemap = Get-Content -LiteralPath $sitemapPath -Raw
$styles = Get-Content -LiteralPath $stylesPath -Raw
$deploy = Get-Content -LiteralPath $deployPath -Raw
$videoScript = Get-Content -LiteralPath $videoScriptPath -Raw
$combined = "$index`n$audiobooks`n$contact"

Assert-Contains $index "Accessible Audio" "homepage"
Assert-Contains $index '<link rel="canonical" href="https://accessibleaudio.co.za/">' "homepage"
Assert-Contains $index '<meta property="og:url" content="https://accessibleaudio.co.za/">' "homepage"
Assert-Contains $index '<meta property="og:title" content="Accessible Audio | Locally Run AI Audiobooks">' "homepage"
Assert-Contains $index '<meta name="twitter:card" content="summary_large_image">' "homepage"
Assert-Contains $index "audiobooks.html" "homepage"
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
Assert-Contains $index "styles.css?v=20260628-video1" "homepage"
Assert-Contains $audiobooks "styles.css?v=20260628-video1" "audiobook library"
Assert-Contains $contact "styles.css?v=20260628-video1" "contact page"
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
Assert-Contains $contact "audio@accessibleaudio.co.za" "contact page"
Assert-Contains $contact "mailto:audio@accessibleaudio.co.za" "contact page"
Assert-Contains $deploy "Get-ChildItem" "deployment script"
Assert-Contains $deploy "audiobooks.html" "deployment script"
Assert-Contains $deploy "robots.txt" "deployment script"
Assert-Contains $deploy "sitemap.xml" "deployment script"
Assert-Contains $robots "User-agent: *" "robots.txt"
Assert-Contains $robots "Allow: /" "robots.txt"
Assert-Contains $robots "Sitemap: https://accessibleaudio.co.za/sitemap.xml" "robots.txt"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/</loc>" "sitemap.xml"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/audiobooks.html</loc>" "sitemap.xml"
Assert-Contains $sitemap "<loc>https://accessibleaudio.co.za/contact.html</loc>" "sitemap.xml"

Assert-NotContains $index "youtube.com/watch" "homepage"
Assert-NotContains $audiobooks "youtube.com/watch" "audiobook library"
Assert-NotContains $combined "chapter-by-chapter" "public pages"
Assert-NotContains $combined "chapter by chapter" "public pages"
Assert-NotContains $combined "how I do it" "public pages"

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

Write-Host "Site checks passed."
