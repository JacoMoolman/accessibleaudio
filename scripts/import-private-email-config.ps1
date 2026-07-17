param(
  [string] $EnvPath = (Join-Path $PSScriptRoot "..\.env.local"),
  [string] $TargetPath = (Join-Path $PSScriptRoot "..\api\config.local.php")
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $EnvPath)) {
  throw "Missing private environment file: $EnvPath"
}
if (-not (Test-Path -LiteralPath $TargetPath)) {
  throw "Missing private PHP config: $TargetPath"
}

$values = @{}
foreach ($line in Get-Content -LiteralPath $EnvPath) {
  $trimmed = $line.Trim()
  if ($trimmed.Length -eq 0 -or $trimmed.StartsWith("#")) {
    continue
  }
  $parts = $trimmed.Split("=", 2)
  if ($parts.Count -eq 2) {
    $values[$parts[0].Trim()] = $parts[1].Trim().Trim('"').Trim("'")
  }
}

$requiredKeys = @(
  "EMAIL_SMTP_HOST",
  "EMAIL_SMTP_PORT",
  "EMAIL_ADDRESS",
  "EMAIL_PASSWORD"
)
foreach ($key in $requiredKeys) {
  if ([string]::IsNullOrWhiteSpace([string] $values[$key])) {
    throw "Private environment file is missing $key"
  }
}

function ConvertTo-PhpLiteral {
  param([string] $Key, [string] $Value)

  if ($Key -eq "EMAIL_SMTP_PORT") {
    $port = 0
    if (-not [int]::TryParse($Value, [ref] $port) -or $port -lt 1 -or $port -gt 65535) {
      throw "EMAIL_SMTP_PORT must be a valid TCP port"
    }
    return [string] $port
  }
  $escaped = $Value.Replace("\", "\\").Replace("'", "\'")
  return "'$escaped'"
}

$content = Get-Content -LiteralPath $TargetPath -Raw
foreach ($key in $requiredKeys) {
  $line = "    '$key' => $(ConvertTo-PhpLiteral -Key $key -Value $values[$key]),"
  $replacementLine = $line.Replace('$', '$$')
  $pattern = "(?m)^\s*'$([regex]::Escape($key))'\s*=>.*$"
  if ([regex]::IsMatch($content, $pattern)) {
    $content = [regex]::Replace($content, $pattern, $replacementLine, 1)
    continue
  }
  if (-not [regex]::IsMatch($content, "(?m)^\];\s*$")) {
    throw "Private PHP config does not end with ];"
  }
  $appendReplacement = ("$line`r`n];").Replace('$', '$$')
  $content = [regex]::Replace($content, "(?m)^\];\s*$", $appendReplacement, 1)
}

$resolvedTarget = [IO.Path]::GetFullPath($TargetPath)
[IO.File]::WriteAllText($resolvedTarget, $content, [Text.UTF8Encoding]::new($false))
Write-Output "Imported private SMTP settings into the ignored PHP configuration."
