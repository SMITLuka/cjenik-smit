<#
.SYNOPSIS
  Push agent: izvrti SQL po lokaciji i feedu, napravi CSV i posalje ga HTTPS-om na cjenik.sm-it.hr.
.DESCRIPTION
  Pokrece se u vasoj mrezi (VPN) svaki dan u 07:00 (vidi Register-Task.ps1). Samo IZLAZNI promet (HTTPS 443).
  - Popis lokacija i feedova dohvaca s API-ja (GET /api/v1/locations), ili iz -Only.
  - Za svaki feed izvrsava sql\<feed>.sql (ODBC, parametri redom: grp, betnr) i mapira kolone po imenu.
  - Zahtjev se potpisuje HMAC-SHA256 (isti kanonski niz kao server, vidi docs/CSV-spec.md).
  - Retry s eksponencijalnim cekanjem do roka (deadline), zatim greska + izlazni kod 1.
.EXAMPLE
  .\Send-Prices.ps1 -SetSecret          # jednokratno: upisi API tajnu (DPAPI, vezano uz ovog Windows korisnika)
  .\Send-Prices.ps1 -SetDbPassword      # jednokratno: upisi lozinku baze (DPAPI)
  .\Send-Prices.ps1 -DryRun             # samo generira CSV u out\ (ne salje)
  .\Send-Prices.ps1 -Only ka4/1         # samo jedna lokacija
  .\Send-Prices.ps1 -Feed dijelovi      # samo jedan cjenik (dijelovi | vozila | radovi), sve lokacije
  .\Send-Prices.ps1 -FromDir C:\csv     # testni nacin: umjesto SQL-a cita C:\csv\<grp>_<betnr>_<feed>.csv
#>
[CmdletBinding()]
param(
    [string]$Config = '',
    [switch]$SetSecret,
    [switch]$SetDbPassword,
    [switch]$DryRun,
    [string]$Only,
    [ValidateSet('', 'dijelovi', 'vozila', 'radovi')][string]$Feed = '',
    [string]$FromDir
)

$ErrorActionPreference = 'Stop'
# Mapa skripte (PSScriptRoot je u nekim nacinima pokretanja prazan, pa rezerva preko MyInvocation)
$Here = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
if (-not $Config) { $Config = Join-Path $Here 'config.json' }
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$Inv = [Globalization.CultureInfo]::InvariantCulture

# ------------------------------------------------------------------ pomocne funkcije
$Script:LogFile = $null
function Write-Log([string]$Level, [string]$Msg) {
    $line = '{0} [{1}] {2}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Level, $Msg
    if ($Level -eq 'ERROR') { Write-Host $line -ForegroundColor Red } else { Write-Host $line }
    if ($Script:LogFile) { Add-Content -Path $Script:LogFile -Value $line -Encoding UTF8 }
}

function Read-Config {
    if (-not (Test-Path $Config)) { throw "Nema konfiguracije: $Config (kopiraj config.example.json)" }
    return Get-Content $Config -Raw -Encoding UTF8 | ConvertFrom-Json
}

function Save-Config($cfg) {
    [IO.File]::WriteAllText($Config, ($cfg | ConvertTo-Json -Depth 6), $Utf8NoBom)
}

function Unprotect-Dpapi([string]$Blob) {
    $ss = ConvertTo-SecureString $Blob
    $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($ss)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr) }
}

function Get-Secret($cfg) {
    if ($env:CJENIK_SECRET) { return $env:CJENIK_SECRET }
    if ($cfg.secretDpapi) { return Unprotect-Dpapi $cfg.secretDpapi }
    throw 'API tajna nije postavljena. Pokreni: .\Send-Prices.ps1 -SetSecret'
}

function New-Nonce {
    $b = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create(); $rng.GetBytes($b); $rng.Dispose()
    return (($b | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-Sha256Hex([byte[]]$Bytes) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return (($sha.ComputeHash($Bytes) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $sha.Dispose() }
}

function Get-HmacHex([string]$Secret, [string]$Text) {
    $h = New-Object Security.Cryptography.HMACSHA256 (, $Utf8NoBom.GetBytes($Secret))
    try { return (($h.ComputeHash($Utf8NoBom.GetBytes($Text)) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $h.Dispose() }
}

function Compress-Gzip([byte[]]$Bytes) {
    $ms = New-Object IO.MemoryStream
    $gz = New-Object IO.Compression.GZipStream($ms, [IO.Compression.CompressionMode]::Compress, $true)
    $gz.Write($Bytes, 0, $Bytes.Length); $gz.Close()
    $out = $ms.ToArray(); $ms.Dispose()
    return , $out
}

# Potpisani HTTPS zahtjev. Vraca @{ Status; Body(string) }. Ne baca iznimku za HTTP greske.
function Invoke-Signed($cfg, [string]$Method, [string]$PathAndQuery, [byte[]]$Body = $null, [bool]$Gzip = $false) {
    $secret = Get-Secret $cfg
    $payload = if ($null -ne $Body) { $Body } else { New-Object byte[] 0 }
    if ($Gzip -and $payload.Length -gt 0) { $payload = Compress-Gzip $payload }
    $sha = Get-Sha256Hex $payload
    $ts = [string][DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    $nonce = New-Nonce
    $canonical = "{0}`n{1}`n{2}`n{3}`n{4}" -f $Method.ToUpper(), $PathAndQuery, $ts, $nonce, $sha
    $sig = Get-HmacHex $secret $canonical

    $url = $cfg.baseUrl.TrimEnd('/') + $PathAndQuery
    $req = [Net.HttpWebRequest]::Create($url)
    $req.Method = $Method.ToUpper()
    $req.Timeout = 120000
    $req.ReadWriteTimeout = 120000
    $req.UserAgent = 'cjenik-agent/1.0'
    $req.Headers.Add('X-Key-Id', $cfg.keyId)
    $req.Headers.Add('X-Timestamp', $ts)
    $req.Headers.Add('X-Nonce', $nonce)
    $req.Headers.Add('X-Content-SHA256', $sha)
    $req.Headers.Add('X-Signature', $sig)
    if ($payload.Length -gt 0) {
        $req.ContentType = 'text/csv; charset=utf-8'
        if ($Gzip) { $req.Headers.Add('Content-Encoding', 'gzip') }
        $req.ContentLength = $payload.Length
        $rs = $req.GetRequestStream(); $rs.Write($payload, 0, $payload.Length); $rs.Close()
    }
    try {
        $resp = [Net.HttpWebResponse]$req.GetResponse()
    } catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { return @{ Status = 0; Body = $_.Exception.Message } }
        $resp = [Net.HttpWebResponse]$_.Exception.Response
    }
    $sr = New-Object IO.StreamReader($resp.GetResponseStream(), $Utf8NoBom)
    $text = $sr.ReadToEnd(); $sr.Close()
    $status = [int]$resp.StatusCode; $resp.Close()
    return @{ Status = $status; Body = $text }
}

# ------------------------------------------------------------------ jednokratne postavke
if ($SetSecret -or $SetDbPassword) {
    $cfg = Read-Config
    if ($SetSecret) {
        $s = Read-Host 'API tajna (iz admina > Kljucevi)' -AsSecureString
        $cfg | Add-Member -NotePropertyName secretDpapi -NotePropertyValue ($s | ConvertFrom-SecureString) -Force
        Write-Host 'API tajna spremljena (DPAPI, vazi samo za ovog Windows korisnika na ovom racunalu).'
    }
    if ($SetDbPassword) {
        $s = Read-Host 'Lozinka baze' -AsSecureString
        $cfg.odbc | Add-Member -NotePropertyName passwordDpapi -NotePropertyValue ($s | ConvertFrom-SecureString) -Force
        Write-Host 'Lozinka baze spremljena (DPAPI).'
    }
    Save-Config $cfg
    return
}

# ------------------------------------------------------------------ glavni tok
$cfg = Read-Config
$base = $Here
$logPath = if ($cfg.logFile) { $cfg.logFile } else { 'logs\agent.log' }
if (-not [IO.Path]::IsPathRooted($logPath)) { $logPath = Join-Path $base $logPath }
New-Item -ItemType Directory -Force -Path (Split-Path $logPath) | Out-Null
$Script:LogFile = $logPath

$deadline = $null
if ($cfg.deadline) {
    $deadline = [datetime]::ParseExact($cfg.deadline, 'HH:mm', $Inv)
}
$maxAttempts = if ($cfg.retries) { [int]$cfg.retries } else { 4 }
$outDir = if ($cfg.outDir) { $cfg.outDir } else { 'out' }
if (-not [IO.Path]::IsPathRooted($outDir)) { $outDir = Join-Path $base $outDir }
$sqlDir = if ($cfg.sqlDir) { $cfg.sqlDir } else { 'sql' }
if (-not [IO.Path]::IsPathRooted($sqlDir)) { $sqlDir = Join-Path $base $sqlDir }

$ProizvodCols = @('naziv', 'sifra', 'marka', 'neto_kolicina', 'jedinica_mjere', 'cijena_po_jedinici_mjere', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'bazna_cijena', 'sidrena_cijena', 'barkod', 'dostupnost', 'najniza_cijena_30_dana')
$UslugaCols = @('naziv_usluge', 'maloprodajna_cijena', 'posebni_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'bazna_cijena', 'sidrena_cijena', 'najniza_cijena_30_dana')
# Ako SQL ne vraca kolonu, uzima se druga (bazna i sidrena su na dan 10.9.2026. ista; najnizu cijenu
# bez povijesti zamjenjuje maloprodajna).
$Fallback = @{ bazna_cijena = 'sidrena_cijena'; sidrena_cijena = 'bazna_cijena'; najniza_cijena_30_dana = 'maloprodajna_cijena' }
# Tri odvojena cjenika po lokaciji: dijelovi i vozila (isti stupci) te radovi.
$Header = @{ dijelovi = $ProizvodCols; vozila = $ProizvodCols; radovi = $UslugaCols }
# Neobavezne kolone: ako ih SQL ne vraca, u CSV idu prazne (nije greska).
$OptionalCols = @('marka', 'neto_kolicina', 'jedinica_mjere', 'naziv_posebnog_oblika_prodaje', 'barkod')
# Kolone koje agent sam izvodi ako ih SQL ne vraca (vidi Rules.ps1): cijena za jedinicu mjere, poseban oblik prodaje,
# najniza cijena u 30 dana.
$DerivedCols = @('cijena_po_jedinici_mjere', 'posebni_oblik_prodaje', 'najniza_cijena_30_dana')
. (Join-Path $Here 'Rules.ps1')

function Format-Cell($v) {
    if ($null -eq $v -or $v -is [DBNull]) { return '' }
    if ($v -is [decimal] -or $v -is [double] -or $v -is [single]) { return ([decimal]$v).ToString('0.00##', $Inv) }
    if ($v -is [datetime]) { return $v.ToString('yyyy-MM-dd', $Inv) }
    return (([string]$v) -replace '[\x00-\x1F\x7F]+', ' ').Trim()
}

# Tekstualni stupci: portal odbija vrijednosti koje pocinju znakom = + @ (zastita od formula injectiona).
$TextCols = @('naziv', 'naziv_usluge', 'sifra', 'marka', 'jedinica_mjere', 'naziv_posebnog_oblika_prodaje')
function Clean-Text([string]$s) { return ($s -replace '^[=+@\s]+', '') }

function ConvertTo-CsvField([string]$s) {
    if ($s -match '[;"\r\n]') { return '"' + $s.Replace('"', '""') + '"' }
    return $s
}

function Get-OdbcConnectionString {
    $cs = [string]$cfg.odbc.connectionString
    if ($cs -match '\{PWD\}') {
        if (-not $cfg.odbc.passwordDpapi) { throw 'Lozinka baze nije postavljena. Pokreni: .\Send-Prices.ps1 -SetDbPassword' }
        $cs = $cs.Replace('{PWD}', (Unprotect-Dpapi $cfg.odbc.passwordDpapi))
    }
    return $cs
}

# SQL datoteke za feed (dijelovi | vozila | radovi): jedna datoteka sql\<feed>.sql ili mapa sql\<feed>\*.sql
# (svaka se izvrsi, redci se spoje; redoslijed po imenu).
function Get-SqlFiles([string]$Feed) {
    $dir = Join-Path $sqlDir $Feed
    if (Test-Path $dir -PathType Container) {
        $files = @(Get-ChildItem $dir -Filter *.sql | Sort-Object Name)
        if ($files.Count -eq 0) { throw "Mapa $dir nema nijedan .sql" }
        return $files
    }
    $f = Join-Path $sqlDir "$Feed.sql"
    if (Test-Path $f) { return @(Get-Item $f) }
    throw "Nema SQL-a za feed '$Feed': ni $f ni mapa $dir"
}

# Cita SQL kao UTF-8; ako nije valjan UTF-8 (npr. spremljen iz Windows alata), cita kao Windows-1250.
function Read-SqlText([string]$Path) {
    $bytes = [IO.File]::ReadAllBytes($Path)
    try { return (New-Object Text.UTF8Encoding($false, $true)).GetString($bytes).TrimStart([char]0xFEFF) }
    catch { return [Text.Encoding]::GetEncoding(1250).GetString($bytes) }
}

# Priprema SQL: uklanja retke komentara (--), zamjenjuje {GRP} (mala slova, npr. kb5) i {BETNR} (npr. 21).
# Tako se nazivi biblioteka/tablica u SQL-u slazu po lokaciji. Vrijednosti se provjeravaju (nema SQL injectiona).
function Expand-Sql([string]$Sql, [string]$Grp, [int]$Betnr) {
    if ($Grp -notmatch '^[A-Za-z0-9]{2,8}$') { throw "Neispravan GRP '$Grp'" }
    if ($Betnr -lt 1 -or $Betnr -gt 99999) { throw "Neispravan BETNR '$Betnr'" }
    $Sql = (($Sql -split "`r?`n") | Where-Object { $_.TrimStart() -notlike '--*' }) -join "`n"
    return $Sql.Replace('{GRP}', $Grp.ToLower()).Replace('{BETNR}', [string]$Betnr)
}

# Vraca CSV tekst (UTF-8) za lokaciju+feed izvrsavanjem SQL-a (svih datoteka feeda).
# Parametri '?' (ako ih ima u upitu): 1) GRP velikim slovima, 2) BETNR.
function New-CsvFromSql([string]$Grp, [int]$Betnr, [string]$Feed, $Conn) {
    $cols = $Header[$Feed]
    $sb = New-Object Text.StringBuilder
    [void]$sb.Append(($cols -join ';')).Append("`n")
    $n = 0
    $today = Get-Date -Format 'yyyy-MM-dd'
    $histPath = Join-Path $base ('state\history\{0}_{1}_{2}.tsv' -f $Grp.ToLower(), $Betnr, $Feed)
    $hist = Read-PriceHistory $histPath
    $histChanged = $false
    foreach ($file in (Get-SqlFiles $Feed)) {
        $sql = Expand-Sql (Read-SqlText $file.FullName) $Grp $Betnr
        $cmd = $Conn.CreateCommand()
        $cmd.CommandText = $sql
        $cmd.CommandTimeout = 1800
        $qm = ([regex]::Matches($sql, '\?')).Count
        if ($qm -ge 1) { [void]$cmd.Parameters.AddWithValue('@grp', $Grp.ToUpper()) }
        if ($qm -ge 2) { [void]$cmd.Parameters.AddWithValue('@betnr', $Betnr) }
        $rd = $cmd.ExecuteReader()
        try {
            $idx = @{}
            for ($i = 0; $i -lt $rd.FieldCount; $i++) { $idx[$rd.GetName($i).ToLower()] = $i }
            # najnizu cijenu u 30 dana racuna agent iz svoje povijesti samo ako je SQL ne vraca
            $rowHist = if ($idx.ContainsKey('najniza_cijena_30_dana')) { $null } else { $hist }
            foreach ($k in @($Fallback.Keys)) {
                if (-not $idx.ContainsKey($k) -and $idx.ContainsKey($Fallback[$k])) { $idx[$k] = $idx[$Fallback[$k]] }
            }
            foreach ($c in $cols) {
                if (-not $idx.ContainsKey($c) -and $OptionalCols -notcontains $c -and $DerivedCols -notcontains $c) { throw "$($file.Name): SQL ne vraca kolonu '$c' (alias u SELECT-u)." }
            }
            $fileRows = 0
            while ($rd.Read()) {
                $vals = @{}
                foreach ($c in $cols) {
                    $s = if ($idx.ContainsKey($c)) { Format-Cell $rd.GetValue($idx[$c]) } else { '' }
                    if ($TextCols -contains $c) { $s = Clean-Text $s }
                    $vals[$c] = $s
                }
                # prazan naziv (u bazi ima artikala bez naziva) -> zamjenjuje se sifrom
                foreach ($nc in @('naziv', 'naziv_usluge')) {
                    if ($vals.ContainsKey($nc) -and $vals[$nc] -eq '' -and $vals.ContainsKey('sifra')) { $vals[$nc] = $vals['sifra'] }
                }
                if (Complete-Row $vals $idx $cols $rowHist $today) { $histChanged = $true }
                $cells = foreach ($c in $cols) { ConvertTo-CsvField $vals[$c] }
                [void]$sb.Append(($cells -join ';')).Append("`n")
                $n++; $fileRows++
            }
        } finally { $rd.Close() }
        Write-Log 'INFO' "  ${Grp}/${Betnr}/${Feed}: $($file.Name) -> $fileRows redaka"
    }
    if ($n -eq 0) { throw "SQL za $Grp/$Betnr/$Feed nije vratio nijedan redak." }
    if ($histChanged) { Save-PriceHistory $hist $histPath $today }
    return $sb.ToString()
}

function Send-Feed([string]$Grp, [int]$Betnr, [string]$Feed, [string]$Csv) {
    $bytes = $Utf8NoBom.GetBytes($Csv)
    $path = "/api/v1/$($Grp.ToLower())/$Betnr/$Feed"
    for ($attempt = 1; $attempt -le $maxAttempts; $attempt++) {
        $r = Invoke-Signed $cfg 'PUT' $path $bytes $true
        if ($r.Status -eq 200) { return $true }
        $retryable = ($r.Status -eq 0 -or $r.Status -ge 500 -or $r.Status -eq 429)
        Write-Log 'WARN' "$Grp/$Betnr/$Feed pokusaj $attempt/$maxAttempts -> HTTP $($r.Status): $($r.Body)"
        if (-not $retryable) { return $false }
        if ($deadline -and (Get-Date) -gt $deadline) { Write-Log 'ERROR' "Rok ($($cfg.deadline)) je prosao, odustajem od $Grp/$Betnr/$Feed."; return $false }
        Start-Sleep -Seconds ([Math]::Min(120, [Math]::Pow(2, $attempt) * 2))
    }
    return $false
}

Write-Log 'INFO' "Start (DryRun=$([bool]$DryRun) FromDir=$FromDir Only=$Only)"

# popis lokacija
$targets = @()
if ($Only) {
    $g, $b = $Only.Split('/')
    foreach ($f in @('dijelovi', 'vozila', 'radovi')) { $targets += [pscustomobject]@{ grp = $g.ToLower(); betnr = [int]$b; feed = $f } }
} else {
    $r = Invoke-Signed $cfg 'GET' '/api/v1/locations'
    if ($r.Status -ne 200) { Write-Log 'ERROR' "Ne mogu dohvatiti lokacije: HTTP $($r.Status) $($r.Body)"; exit 1 }
    foreach ($l in ($r.Body | ConvertFrom-Json).locations) {
        foreach ($f in $l.feeds) { $targets += [pscustomobject]@{ grp = $l.grp; betnr = [int]$l.betnr; feed = $f } }
    }
}
if ($Feed) { $targets = @($targets | Where-Object { $_.feed -eq $Feed }) }
Write-Log 'INFO' "Za obradu: $($targets.Count) feedova"

$conn = $null
if (-not $FromDir) {
    $conn = New-Object System.Data.Odbc.OdbcConnection (Get-OdbcConnectionString)
    $conn.Open()
}

$ok = 0; $failed = @()
try {
    foreach ($t in $targets) {
        $label = "$($t.grp)/$($t.betnr)/$($t.feed)"
        try {
            if ($FromDir) {
                $file = Join-Path $FromDir ('{0}_{1}_{2}.csv' -f $t.grp, $t.betnr, $t.feed)
                if (-not (Test-Path $file)) { Write-Log 'WARN' "${label}:nema $file, preskacem"; continue }
                $csv = [IO.File]::ReadAllText($file, $Utf8NoBom)
            } else {
                $csv = New-CsvFromSql $t.grp $t.betnr $t.feed $conn
            }
            if ($DryRun) {
                $dir = Join-Path $outDir (Get-Date -Format 'yyyy-MM-dd'); New-Item -ItemType Directory -Force -Path $dir | Out-Null
                [IO.File]::WriteAllText((Join-Path $dir ('{0}_{1}_{2}.csv' -f $t.grp, $t.betnr, $t.feed)), $csv, $Utf8NoBom)
                Write-Log 'INFO' "${label}:DryRun, CSV spremljen u $dir"
                $ok++; continue
            }
            if (Send-Feed $t.grp $t.betnr $t.feed $csv) { Write-Log 'INFO' "${label}:OK"; $ok++ }
            else { Write-Log 'ERROR' "${label}:NIJE poslano"; $failed += $label }
        } catch {
            Write-Log 'ERROR' "${label}:$($_.Exception.Message)"
            $failed += $label
        }
    }
} finally {
    if ($conn) { $conn.Close() }
}

Write-Log 'INFO' "Gotovo: OK=$ok, neuspjelo=$($failed.Count)"
if ($failed.Count -gt 0) { Write-Log 'ERROR' ('Neuspjelo: ' + ($failed -join ', ')); exit 1 }
exit 0
