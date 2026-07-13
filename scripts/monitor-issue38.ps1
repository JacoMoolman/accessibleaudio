param(
  [string] $Repository = "JacoMoolman/momstats",
  [int] $IssueNumber = 38
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
$stateDirectory = Join-Path $root "tmp"
$statePath = Join-Path $stateDirectory "issue-$IssueNumber-monitor-state.json"
$logPath = Join-Path $stateDirectory "issue-$IssueNumber-monitor.log"

New-Item -ItemType Directory -Force -Path $stateDirectory | Out-Null

$githubCli = Join-Path $env:ProgramFiles "GitHub CLI\gh.exe"
if (-not (Test-Path -LiteralPath $githubCli)) {
  $githubCli = (Get-Command gh -ErrorAction Stop).Source
}

$issue = & $githubCli issue view $IssueNumber --repo $Repository --json number,state,updatedAt,comments,url,body |
  ConvertFrom-Json

$comments = @($issue.comments)
$lastComment = if ($comments.Count) { $comments[$comments.Count - 1] } else { $null }
$lastCommentId = if ($lastComment) { [string] $lastComment.id } else { "" }
$sha256 = [System.Security.Cryptography.SHA256]::Create()
try {
  $bodyHash = [BitConverter]::ToString(
    $sha256.ComputeHash([System.Text.Encoding]::UTF8.GetBytes([string] $issue.body))
  ).Replace("-", "")
} finally {
  $sha256.Dispose()
}
$fingerprint = "{0}|{1}|{2}|{3}" -f `
  $issue.state,
  $issue.updatedAt,
  $lastCommentId,
  $bodyHash

$previous = $null
if (Test-Path -LiteralPath $statePath) {
  $previous = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
}

$changed = $null -ne $previous -and $previous.fingerprint -ne $fingerprint
$record = [ordered]@{
  checked_at = (Get-Date).ToString("o")
  repository = $Repository
  issue_number = $issue.number
  issue_url = $issue.url
  state = $issue.state
  updated_at = $issue.updatedAt
  comment_count = $comments.Count
  last_comment_id = if ($lastComment) { $lastComment.id } else { $null }
  last_comment_at = if ($lastComment) { $lastComment.createdAt } else { $null }
  fingerprint = $fingerprint
  changed_since_previous_check = $changed
}

$record | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding utf8

$outcome = if ($null -eq $previous) {
  "baseline saved"
} elseif ($changed) {
  "UPDATE DETECTED"
} else {
  "no change"
}

"{0} issue #{1}: {2}; state={3}; comments={4}; updated={5}" -f `
  $record.checked_at,
  $issue.number,
  $outcome,
  $issue.state,
  $comments.Count,
  $issue.updatedAt | Add-Content -LiteralPath $logPath -Encoding utf8

if ($changed) {
  $message = "Accessible Audio issue #${IssueNumber} changed. Open $($issue.url) and tell Codex to continue."
  $msgExe = Join-Path $env:SystemRoot "System32\msg.exe"
  if (Test-Path -LiteralPath $msgExe) {
    & $msgExe $env:USERNAME $message 2>$null | Out-Null
  }
  Write-Output "UPDATE DETECTED for issue #${IssueNumber}: $($issue.url)"
}
