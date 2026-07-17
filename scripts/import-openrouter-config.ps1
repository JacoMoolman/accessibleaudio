param(
  [string] $SourcePath = "G:\Projects\AUDIOBOOK\AudioBookMaker\config.local.json",
  [string] $TargetPath = (Join-Path $PSScriptRoot "..\api\config.local.php")
)

$source = Get-Content -LiteralPath $SourcePath -Raw | ConvertFrom-Json
$apiKey = [string] $source.openrouter_api_key
if ([string]::IsNullOrWhiteSpace($apiKey)) {
  throw "The source config does not contain openrouter_api_key"
}
$escapedKey = $apiKey.Replace("\", "\\").Replace("'", "\'")
$body = if (Test-Path -LiteralPath $TargetPath) {
  Get-Content -LiteralPath $TargetPath -Raw
} else {
  "<?php`nreturn [`n];`n"
}
if ($body -match "(?m)^\s*'OPENROUTER_API_KEY'\s*=>") {
  $body = [regex]::Replace(
    $body,
    "(?m)^\s*'OPENROUTER_API_KEY'\s*=>\s*.*,$",
    "    'OPENROUTER_API_KEY' => '$escapedKey',"
  )
} else {
  $body = $body -replace "(?m)^\];\s*$", "    'OPENROUTER_API_KEY' => '$escapedKey',`n    'OPENROUTER_TTS_MODEL' => 'x-ai/grok-voice-tts-1.0',`n];"
}
[System.IO.File]::WriteAllText($TargetPath, $body, [System.Text.UTF8Encoding]::new($false))
Write-Output "Imported the OpenRouter key into the ignored local PHP configuration."
