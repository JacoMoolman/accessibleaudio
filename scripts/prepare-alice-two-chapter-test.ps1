param(
  [string] $Source = "G:\Projects\AUDIOBOOK\AudioBookMaker\books\Alice_In_Wonderland\Alice_In_Wonderland.txt",
  [string] $Destination = (Join-Path $PSScriptRoot "..\tmp\Alice_In_Wonderland_Chapters_1_2.txt")
)

$text = [System.IO.File]::ReadAllText($Source, [System.Text.Encoding]::UTF8)
$thirdChapter = [regex]::Match($text, '(?m)^Chapter 3\.\s*$')
if (-not $thirdChapter.Success) {
  throw "Could not find Chapter 3 in $Source"
}
$excerpt = $text.Substring(0, $thirdChapter.Index).Trim() + "`n"
$directory = Split-Path -Parent $Destination
if (-not (Test-Path -LiteralPath $directory)) {
  New-Item -ItemType Directory -Path $directory | Out-Null
}
[System.IO.File]::WriteAllText($Destination, $excerpt, [System.Text.UTF8Encoding]::new($false))
Write-Output $Destination
