param(
  [string] $EnvPath = ".env.local"
)

$ErrorActionPreference = "Stop"

function Read-EnvFile {
  param([string] $Path)

  if (-not (Test-Path -LiteralPath $Path)) {
    throw "Missing env file: $Path"
  }

  $values = @{}
  foreach ($line in Get-Content -LiteralPath $Path) {
    $trimmed = $line.Trim()
    if ($trimmed.Length -eq 0 -or $trimmed.StartsWith("#")) {
      continue
    }

    $parts = $trimmed.Split("=", 2)
    if ($parts.Count -eq 2) {
      $values[$parts[0].Trim()] = $parts[1].Trim()
    }
  }

  return $values
}

function New-FtpRequest {
  param(
    [string] $Uri,
    [string] $Method,
    [string] $Username,
    [string] $Password
  )

  $request = [System.Net.FtpWebRequest]::Create($Uri)
  $request.Method = $Method
  $request.Credentials = [System.Net.NetworkCredential]::new($Username, $Password)
  $request.UseBinary = $true
  $request.UsePassive = $true
  $request.KeepAlive = $false
  return $request
}

function Ensure-FtpDirectory {
  param(
    [string] $BaseUri,
    [string] $RelativePath,
    [string] $Username,
    [string] $Password
  )

  if ([string]::IsNullOrWhiteSpace($RelativePath)) {
    return
  }

  $segments = $RelativePath -split "[\\/]" | Where-Object { $_ }
  $current = $BaseUri.TrimEnd("/")

  foreach ($segment in $segments) {
    $current = "$current/$segment"
    try {
      $request = New-FtpRequest `
        -Uri $current `
        -Method ([System.Net.WebRequestMethods+Ftp]::MakeDirectory) `
        -Username $Username `
        -Password $Password
      $response = $request.GetResponse()
      $response.Close()
    } catch [System.Net.WebException] {
      $response = $_.Exception.Response
      if ($null -ne $response) {
        $response.Close()
      }
    }
  }
}

function Send-FtpFile {
  param(
    [string] $LocalPath,
    [string] $RemoteUri,
    [string] $Username,
    [string] $Password
  )

  $request = New-FtpRequest `
    -Uri $RemoteUri `
    -Method ([System.Net.WebRequestMethods+Ftp]::UploadFile) `
    -Username $Username `
    -Password $Password

  $bytes = [System.IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $LocalPath))
  $request.ContentLength = $bytes.Length

  $stream = $request.GetRequestStream()
  $stream.Write($bytes, 0, $bytes.Length)
  $stream.Close()

  $response = $request.GetResponse()
  $response.Close()
}

$envValues = Read-EnvFile -Path $EnvPath
$hostValue = $envValues["FTP_IP"]
if ([string]::IsNullOrWhiteSpace($hostValue)) {
  $hostValue = $envValues["FTP_HOST"]
}

$username = $envValues["FTP_USERNAME"]
$password = $envValues["FTP_PASSWORD"]
$remotePath = $envValues["FTP_REMOTE_PATH"]

if ([string]::IsNullOrWhiteSpace($hostValue)) {
  throw "FTP_IP or FTP_HOST must be set in $EnvPath"
}
if ([string]::IsNullOrWhiteSpace($username)) {
  throw "FTP_USERNAME must be set in $EnvPath"
}
if ([string]::IsNullOrWhiteSpace($password)) {
  throw "FTP_PASSWORD must be set in $EnvPath before deployment"
}
if ([string]::IsNullOrWhiteSpace($remotePath)) {
  throw "FTP_REMOTE_PATH must be set in $EnvPath"
}

if ($remotePath -eq "/") {
  $baseUri = "ftp://$hostValue/"
} else {
  $baseUri = "ftp://$hostValue/$remotePath"
}
$files = @(
  "index.html",
  "audiobooks.html",
  "contact.html",
  "submit/index.html",
  "submit/app.js",
  "submit/styles.css",
  "robots.txt",
  "sitemap.xml",
  "favicon.svg",
  "styles.css",
  "scripts/video-embeds.js",
  "private_uploads/.htaccess"
)

$assetFiles = Get-ChildItem -LiteralPath "assets" -Recurse -File |
  Where-Object { $_.Name -notlike "_ref_*" } |
  ForEach-Object { $_.FullName.Substring((Resolve-Path -LiteralPath ".").Path.Length + 1) }

$files += $assetFiles

$submitAssetFiles = Get-ChildItem -LiteralPath "submit/assets" -Recurse -File |
  ForEach-Object { $_.FullName.Substring((Resolve-Path -LiteralPath ".").Path.Length + 1) }

$files += $submitAssetFiles

$apiFiles = Get-ChildItem -LiteralPath "api" -File -Filter "*.php" |
  Where-Object { $_.Name -notlike "config.local*" } |
  ForEach-Object { $_.FullName.Substring((Resolve-Path -LiteralPath ".").Path.Length + 1) }

$files += $apiFiles

foreach ($file in $files) {
  if (-not (Test-Path -LiteralPath $file)) {
    throw "Missing deploy file: $file"
  }
}

foreach ($file in $files) {
  $remoteRelativeDir = Split-Path -Path $file -Parent
  Ensure-FtpDirectory `
    -BaseUri $baseUri `
    -RelativePath $remoteRelativeDir `
    -Username $username `
    -Password $password

  $remoteFile = ($file -replace "\\", "/")
  $remoteUri = "$($baseUri.TrimEnd("/"))/$remoteFile"
  Write-Host "Uploading $file -> $remoteUri"
  Send-FtpFile `
    -LocalPath $file `
    -RemoteUri $remoteUri `
    -Username $username `
    -Password $password
}

Write-Host "Upload complete."
