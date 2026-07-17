param(
  [Parameter(Mandatory = $true)]
  [string] $SourceJson,
  [string] $TargetPath = (Join-Path $PSScriptRoot "..\api\config.local.php")
)

$ErrorActionPreference = "Stop"

if (-not (Test-Path -LiteralPath $SourceJson)) {
  throw "Missing PayFast source file: $SourceJson"
}
if (-not (Test-Path -LiteralPath $TargetPath)) {
  throw "Missing private PHP config: $TargetPath"
}

$requiredKeys = @(
  "PAYFAST_MERCHANT_ID",
  "PAYFAST_MERCHANT_KEY",
  "PAYFAST_PASSPHRASE",
  "PAYFAST_SANDBOX",
  "PAYFAST_UNSIGNED_SANDBOX",
  "PAYFAST_RETURN_URL",
  "PAYFAST_CANCEL_URL",
  "PAYFAST_NOTIFY_URL"
)
$values = Get-Content -LiteralPath $SourceJson -Raw | ConvertFrom-Json

foreach ($key in $requiredKeys) {
  $property = $values.PSObject.Properties[$key]
  if ($null -eq $property) {
    throw "PayFast source file is missing $key"
  }
  if ($key -notin @("PAYFAST_SANDBOX", "PAYFAST_UNSIGNED_SANDBOX", "PAYFAST_PASSPHRASE") -and [string]::IsNullOrWhiteSpace([string] $property.Value)) {
    throw "PayFast source file contains an empty $key"
  }
}

function ConvertTo-PhpLiteral {
  param([object] $Value)

  if ($Value -is [bool]) {
    return $(if ($Value) { "true" } else { "false" })
  }
  $escaped = ([string] $Value).Replace("\", "\\").Replace("'", "\'")
  return "'$escaped'"
}

$content = Get-Content -LiteralPath $TargetPath -Raw
foreach ($key in $requiredKeys) {
  $line = "    '$key' => $(ConvertTo-PhpLiteral $values.PSObject.Properties[$key].Value),"
  $pattern = "(?m)^\s*'$([regex]::Escape($key))'\s*=>.*$"
  if ([regex]::IsMatch($content, $pattern)) {
    $content = [regex]::Replace($content, $pattern, $line, 1)
    continue
  }

  $closingPattern = "(?m)^\];\s*$"
  if (-not [regex]::IsMatch($content, $closingPattern)) {
    throw "Private PHP config does not end with ];"
  }
  $content = [regex]::Replace($content, $closingPattern, "$line`r`n];", 1)
}

$resolvedTarget = [IO.Path]::GetFullPath($TargetPath)
[IO.File]::WriteAllText($resolvedTarget, $content, [Text.UTF8Encoding]::new($false))
Write-Output "Updated private PayFast configuration keys in $resolvedTarget"
