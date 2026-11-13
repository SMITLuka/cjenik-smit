<#
.SYNOPSIS
  Pretvara popunjenu tablicu (Podaci-koncesije-i-lokacije.xlsx) u dvije CSV datoteke za uvoz u portal.
.DESCRIPTION
  Ne treba Excel: cita .xlsx izravno. Napravi mapu "izvoz" s datotekama koncesije.csv i lokacije.csv
  (UTF-8, razdjelnik ';'). Zatim ih uvezi u adminu (Uvoz) ili naredbama:
    php app/bin/import-concessions.php podaci/izvoz/koncesije.csv
    php app/bin/import-locations.php   podaci/izvoz/lokacije.csv
.EXAMPLE
  .\Izvezi-CSV.ps1
  .\Izvezi-CSV.ps1 -Xlsx "C:\putanja\moja-tablica.xlsx"
#>
param(
    [string]$Xlsx = (Join-Path $PSScriptRoot 'Podaci-koncesije-i-lokacije.xlsx'),
    [string]$OutDir = (Join-Path $PSScriptRoot 'izvoz')
)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Read-ZipText($zip, [string]$name) {
    $e = $zip.GetEntry($name)
    if ($null -eq $e) { return $null }
    $sr = New-Object IO.StreamReader($e.Open(), [Text.Encoding]::UTF8)
    try { return $sr.ReadToEnd() } finally { $sr.Close() }
}

function ConvertTo-ColIndex([string]$letters) {
    $n = 0
    foreach ($ch in $letters.ToUpper().ToCharArray()) { $n = $n * 26 + ([int]$ch - 64) }
    return $n
}

# Vraca listu redaka; svaki redak je hashtable stupac(1-based) -> tekst
function Read-Sheet($zip, [string]$sheetPath, $shared) {
    [xml]$doc = Read-ZipText $zip $sheetPath
    $ns = New-Object Xml.XmlNamespaceManager($doc.NameTable)
    $ns.AddNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main')
    $rows = @()
    foreach ($row in $doc.SelectNodes('//m:sheetData/m:row', $ns)) {
        $h = @{}
        foreach ($c in $row.SelectNodes('m:c', $ns)) {
            $ref = [string]$c.r
            $col = ConvertTo-ColIndex ($ref -replace '\d', '')
            $t = [string]$c.t
            $val = ''
            if ($t -eq 's') { $val = $shared[[int]$c.v] }
            elseif ($t -eq 'inlineStr') { $val = ($c.SelectNodes('.//m:t', $ns) | ForEach-Object { $_.InnerText }) -join '' }
            elseif ($null -ne $c.v) {
                $vv = $c.v
                if ($vv -is [Xml.XmlElement]) { $vv = $vv.InnerText }   # PowerShell XML adapter vraca string ako element ima samo tekst
                $val = [string]$vv
                if ($val -match '^-?\d+\.0+$') { $val = $val -replace '\.0+$', '' }
            }
            $h[$col] = ([string]$val).Trim()
        }
        $rows += , $h
    }
    return , $rows
}

function ConvertTo-CsvField([string]$s) {
    if ($s -match '[;"\r\n]') { return '"' + $s.Replace('"', '""') + '"' }
    return $s
}

if (-not (Test-Path $Xlsx)) { throw "Ne nalazim datoteku: $Xlsx" }
$zip = [IO.Compression.ZipFile]::OpenRead($Xlsx)
try {
    # popis stringova
    $shared = @()
    $ssText = Read-ZipText $zip 'xl/sharedStrings.xml'
    if ($ssText) {
        [xml]$ss = $ssText
        $ns0 = New-Object Xml.XmlNamespaceManager($ss.NameTable)
        $ns0.AddNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main')
        foreach ($si in $ss.SelectNodes('//m:si', $ns0)) {
            $shared += (($si.SelectNodes('.//m:t', $ns0) | ForEach-Object { $_.InnerText }) -join '')
        }
    }
    # nazivi listova -> datoteke
    [xml]$wbx = Read-ZipText $zip 'xl/workbook.xml'
    [xml]$rels = Read-ZipText $zip 'xl/_rels/workbook.xml.rels'
    $relMap = @{}
    foreach ($r in $rels.Relationships.Relationship) { $relMap[[string]$r.Id] = [string]$r.Target }
    $sheetFile = @{}
    foreach ($s in $wbx.workbook.sheets.sheet) {
        $rid = $s.GetAttribute('id', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships')
        $target = $relMap[$rid]
        $sheetFile[[string]$s.name] = 'xl/' + ($target -replace '^/?(xl/)?', '')
    }

    function Export-Sheet([string]$sheetName, [string[]]$columns, [string]$outFile, [int]$keyCols) {
        if (-not $sheetFile.ContainsKey($sheetName)) { throw "U tablici nema lista '$sheetName'." }
        $rows = Read-Sheet $zip $sheetFile[$sheetName] $shared
        $hdr = $rows[0]
        for ($i = 0; $i -lt $columns.Count; $i++) {
            if ($hdr[$i + 1] -ne $columns[$i]) { throw "List '$sheetName': stupac $($i + 1) mora imati naziv '$($columns[$i])' (nađeno '$($hdr[$i + 1])'). Ne mijenjaj prvi redak." }
        }
        $sb = New-Object Text.StringBuilder
        [void]$sb.Append(($columns -join ';')).Append("`n")
        $n = 0
        foreach ($row in $rows | Select-Object -Skip 1) {
            $vals = for ($i = 1; $i -le $columns.Count; $i++) { if ($row.ContainsKey($i)) { $row[$i] } else { '' } }
            if ($vals[0] -eq '') { continue }
            [void]$sb.Append((($vals | ForEach-Object { ConvertTo-CsvField $_ }) -join ';')).Append("`n")
            $n++
        }
        [IO.File]::WriteAllText($outFile, $sb.ToString(), $Utf8NoBom)
        Write-Host ("{0}: {1} redaka -> {2}" -f $sheetName, $n, $outFile)
    }

    New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
    Export-Sheet 'Koncesije' @('grp', 'naziv', 'slug', 'web_url') (Join-Path $OutDir 'koncesije.csv') 1
    Export-Sheet 'Lokacije' @('grp', 'betnr', 'naziv', 'pravni_naziv', 'adresa', 'oblik_mjesta', 'oznaka_objekta', 'dijelovi', 'vozila', 'radovi') (Join-Path $OutDir 'lokacije.csv') 2
} finally {
    $zip.Dispose()
}
Write-Host "Gotovo. Sljedeci korak: uvoz u adminu (Uvoz) ili naredbe import-concessions.php / import-locations.php."
