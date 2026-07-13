param(
  [int] $IntervalSeconds = 3600
)

$ErrorActionPreference = "Continue"

$monitor = Join-Path $PSScriptRoot "monitor-issue38.ps1"
$root = Split-Path -Parent $PSScriptRoot
$logDirectory = Join-Path $root "tmp"
$logPath = Join-Path $logDirectory "issue-38-monitor.log"

New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null
"{0} issue #38: persistent monitor started; interval={1}s" -f `
  (Get-Date).ToString("o"), $IntervalSeconds | Add-Content -LiteralPath $logPath -Encoding utf8

while ($true) {
  try {
    & $monitor
  } catch {
    "{0} issue #38: monitor error: {1}" -f `
      (Get-Date).ToString("o"), $_.Exception.Message | Add-Content -LiteralPath $logPath -Encoding utf8
  }
  Start-Sleep -Seconds $IntervalSeconds
}
