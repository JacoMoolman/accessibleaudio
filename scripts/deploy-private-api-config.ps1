param(
  [string] $EnvPath = ".env.local",
  [string] $ConfigPath = "api/config.local.php"
)

$ErrorActionPreference = "Stop"
$values = @{}
foreach ($line in Get-Content -LiteralPath $EnvPath) {
  $parts = $line.Trim().Split("=", 2)
  if ($parts.Count -eq 2 -and -not $line.Trim().StartsWith("#")) {
    $values[$parts[0].Trim()] = $parts[1].Trim()
  }
}
$hostValue = if ($values.FTP_IP) { $values.FTP_IP } else { $values.FTP_HOST }
$script:privateConfigTlsSuffix = if ($values.FTP_TLS_ALLOWED_CERT_SUFFIX) { $values.FTP_TLS_ALLOWED_CERT_SUFFIX.ToLowerInvariant() } else { ".hstgr.io" }
$previousCertificateCallback = [System.Net.ServicePointManager]::ServerCertificateValidationCallback
[System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
[System.Net.ServicePointManager]::ServerCertificateValidationCallback = {
  param($sender, $certificate, $chain, $sslPolicyErrors)
  if ($sslPolicyErrors -eq [System.Net.Security.SslPolicyErrors]::None) { return $true }
  if ($sslPolicyErrors -ne [System.Net.Security.SslPolicyErrors]::RemoteCertificateNameMismatch) { return $false }
  $dnsName = $certificate.GetNameInfo([System.Security.Cryptography.X509Certificates.X509NameType]::DnsName, $false)
  return $dnsName.ToLowerInvariant().EndsWith($script:privateConfigTlsSuffix)
}
$request = [System.Net.FtpWebRequest]::Create("ftp://$hostValue/api/config.local.php")
$request.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
$request.Credentials = [System.Net.NetworkCredential]::new($values.FTP_USERNAME, $values.FTP_PASSWORD)
$request.EnableSsl = $true
$request.UseBinary = $true
$request.UsePassive = $true
$bytes = [System.IO.File]::ReadAllBytes((Resolve-Path -LiteralPath $ConfigPath))
$request.ContentLength = $bytes.Length
try {
  $stream = $request.GetRequestStream()
  $stream.Write($bytes, 0, $bytes.Length)
  $stream.Close()
  $response = $request.GetResponse()
  $response.Close()
  Write-Output "Uploaded the ignored private API configuration over FTPS."
} finally {
  [System.Net.ServicePointManager]::ServerCertificateValidationCallback = $previousCertificateCallback
}
