# Test agenta s LAZNOM bazom (DataTable umjesto ODBC-a): ne spaja se na bazu ni mrezu.
# Provjerava New-CsvFromSql: mapiranje stupaca, ciscenje teksta, pravila iz Rules.ps1.
# Pokretanje: powershell -NoProfile -ExecutionPolicy Bypass -File tests\agent-test.ps1 [-Out datoteka.csv]
param([string]$Out = '')
$ErrorActionPreference = 'Stop'
$agent = Join-Path (Split-Path -Parent $PSScriptRoot) 'agent'
$Inv = [Globalization.CultureInfo]::InvariantCulture
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

# funkcije iz agenta (bez izvrsavanja glavnog toka)
$ast = [System.Management.Automation.Language.Parser]::ParseFile((Join-Path $agent 'Send-Prices.ps1'), [ref]$null, [ref]$null)
$want = 'Write-Log', 'Format-Cell', 'Clean-Text', 'ConvertTo-CsvField', 'Get-SqlFiles', 'Read-SqlText', 'Expand-Sql', 'New-CsvFromSql'
foreach ($f in $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true)) {
    if ($want -contains $f.Name) { Invoke-Expression $f.Extent.Text }
}
. (Join-Path $agent 'Rules.ps1')
$TextCols = @('naziv', 'naziv_usluge', 'sifra', 'marka', 'jedinica_mjere', 'naziv_posebnog_oblika_prodaje')
$ProizvodCols = @('naziv', 'sifra', 'marka', 'neto_kolicina', 'jedinica_mjere', 'cijena_po_jedinici_mjere', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'bazna_cijena', 'sidrena_cijena', 'barkod', 'dostupnost', 'najniza_cijena_30_dana')
$Header = @{ proizvodi = $ProizvodCols }
$Fallback = @{ bazna_cijena = 'sidrena_cijena'; sidrena_cijena = 'bazna_cijena'; najniza_cijena_30_dana = 'maloprodajna_cijena' }
$OptionalCols = @('marka', 'neto_kolicina', 'jedinica_mjere', 'naziv_posebnog_oblika_prodaje', 'barkod')
$DerivedCols = @('cijena_po_jedinici_mjere', 'posebni_oblik_prodaje', 'najniza_cijena_30_dana')

$tmp = Join-Path ([IO.Path]::GetTempPath()) ('agtest_' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Force -Path "$tmp\sql" | Out-Null
[IO.File]::WriteAllText("$tmp\sql\dijelovi.sql", "-- komentar`nselect 1 from x", $Utf8NoBom)
$base = $tmp; $sqlDir = Join-Path $tmp 'sql'
$Script:LogFile = $null

# lazna veza: vraca DataTable
$dt = New-Object System.Data.DataTable
foreach ($c in 'naziv', 'sifra', 'marka', 'barkod', 'dostupnost') { [void]$dt.Columns.Add($c) }
foreach ($c in 'maloprodajna_cijena', 'sidrena_cijena') { [void]$dt.Columns.Add($c, [decimal]) }
[void]$dt.Rows.Add('Filter ulja', 'F1', 'Renault', '3850000000011', 'dostupno', [decimal]80, [decimal]100)
[void]$dt.Rows.Add('=EVIL()', 'F2', 'Dacia', 'xx', 'dostupno', [decimal]20, [decimal]20)
[void]$dt.Rows.Add('', 'F3', 'Nissan', '', 'dostupno', [decimal]30, [decimal]25)
[void]$dt.Rows.Add(('Vijak' + [char]7 + ' M8'), 'F4', 'MG', '', 'dostupno', [decimal]1.5, [decimal]1.5)
$conn = [pscustomobject]@{}
$conn | Add-Member ScriptMethod CreateCommand {
    $cmd = [pscustomobject]@{ CommandText = ''; CommandTimeout = 0; Parameters = [pscustomobject]@{} }
    $cmd.Parameters | Add-Member ScriptMethod AddWithValue { param($a, $b) }
    $cmd | Add-Member ScriptMethod ExecuteReader { , $dt.CreateDataReader() }.GetNewClosure()
    $cmd
}.GetNewClosure()

$csv = New-CsvFromSql 'kb5' 1 'proizvodi' @('dijelovi') $conn
if ($Out) { [IO.File]::WriteAllText($Out, $csv, $Utf8NoBom) }
$lines = $csv -split "`n" | Where-Object { $_ -ne '' }
$rows = @($lines | Select-Object -Skip 1 | ForEach-Object { , ($_ -split ';') })

$script:pass = 0; $script:fail = 0
function T([string]$name, [bool]$ok) {
    if ($ok) { $script:pass++; Write-Host "  ok   $name" } else { $script:fail++; Write-Host "  FAIL $name" -ForegroundColor Red }
}
T 'zaglavlje ima 14 stupaca' (($lines[0] -split ';').Count -eq 14)
T 'sva 4 retka su u CSV-u' ($rows.Count -eq 4)
T 'svaki redak ima 14 polja' (@($rows | Where-Object { $_.Count -ne 14 }).Count -eq 0)
$h = $lines[0] -split ';'
function Col($r, $name) { $r[[array]::IndexOf($h, $name)] }
T 'akcija: poseban oblik da + AKCIJA' ((Col $rows[0] 'posebni_oblik_prodaje') -eq 'da' -and (Col $rows[0] 'naziv_posebnog_oblika_prodaje') -eq 'AKCIJA')
T 'cijena za jedinicu = 100 (bez popusta)' ((Col $rows[0] 'cijena_po_jedinici_mjere') -eq '100.00')
T 'bazna kopirana iz sidrene' ((Col $rows[0] 'bazna_cijena') -eq '100.00')
T 'najniza = 80' ((Col $rows[0] 'najniza_cijena_30_dana') -eq '80.00')
T 'barkod valjan ostaje' ((Col $rows[0] 'barkod') -eq '3850000000011')
T 'los barkod prazan' ((Col $rows[1] 'barkod') -eq '')
T 'naziv koji pocinje s = je ociscen' ((Col $rows[1] 'naziv') -eq 'EVIL()')
T 'prazan naziv zamijenjen sifrom' ((Col $rows[2] 'naziv') -eq 'F3')
T 'poskupljenje nije akcija' ((Col $rows[2] 'posebni_oblik_prodaje') -eq 'ne')
T 'kontrolni znak u nazivu uklonjen' ((Col $rows[3] 'naziv') -notmatch '[\x00-\x1F]')
T 'povijest je spremljena' (Test-Path (Join-Path $tmp 'state\history\kb5_1_dijelovi.tsv'))

# drugi dan: cijena F1 se vraca na 100 -> najniza ostaje 80
$dt.Rows[0]['maloprodajna_cijena'] = [decimal]100
$csv2 = New-CsvFromSql 'kb5' 1 'proizvodi' @('dijelovi') $conn
$rows2 = @(($csv2 -split "`n" | Where-Object { $_ -ne '' } | Select-Object -Skip 1) | ForEach-Object { , ($_ -split ';') })
T 'ponovno pokretanje: najniza ostaje 80 nakon povratka cijene' ((Col $rows2[0] 'najniza_cijena_30_dana') -eq '80.00')

Remove-Item -Recurse -Force -LiteralPath $tmp
Write-Host ("`n{0} proslo, {1} palo" -f $script:pass, $script:fail)
if ($script:fail -gt 0) { exit 1 }
