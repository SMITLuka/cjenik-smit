# Pravila koja agent primjenjuje na svaki redak cjenika (ucitava ih Send-Prices.ps1). Ne dira bazu.
# Ocekuje u pozivajucem opsegu: $Inv (InvariantCulture), $Utf8NoBom.

$HistoryDays = 30

# Sifre marki iz DMS-a (tablica marki) -> naziv u cjeniku. Sifre koje nisu ovdje idu kao 'Ostale marke'
# (u DMS-u su to i *, ER, SH, SC, LM = lokalna marka). Kljuc nije osjetljiv na velika/mala slova.
# Znakovi izvan ASCII-ja pisu se preko kodova da datoteka ostane neovisna o kodnoj stranici.
$BrandMap = @{
    'CH' = 'Chrysler'; 'DC' = 'Dacia'; 'FO' = 'Ford'; 'NI' = 'Nissan'; 'PE' = 'Peugeot'; 'RE' = 'Renault'; 'RX' = 'Renault'
    'XX' = 'Ostale marke'; 'VO' = 'Volvo'; 'KI' = 'Kia'; 'SU' = 'Subaru'; 'SY' = 'SsangYong'
    'GA' = ('Ga' + [string][char]0x161 + 'perov')
    'IV' = 'Iveco'; 'ME' = 'Mercedes-Benz'; 'MB' = 'Mercedes-Benz'; 'DA' = 'DAF'; 'MA' = 'Mazda'; 'JA' = 'Jaguar'; 'FI' = 'Fiat'
    'LR' = 'Land Rover'; 'AR' = 'Alfa Romeo'; 'JE' = 'Jeep'; 'LA' = 'Lancia'; 'AM' = 'Aston Martin'; 'AU' = 'Audi'; 'BE' = 'Bentley'
    'BM' = 'BMW'; 'CA' = 'Cadillac'; 'CI' = ('Citro' + [string][char]0xEB + 'n'); 'CV' = 'Chevrolet/Daewoo'; 'DO' = 'Dodge'
    'FE' = 'Ferrari'; 'HO' = 'Honda'; 'HU' = 'Hummer'; 'HY' = 'Hyundai'; 'IS' = 'Isuzu'; 'LC' = 'Lincoln'; 'LD' = 'Lada'
    'LG' = 'Lamborghini'; 'LO' = 'Lotus'; 'LW' = 'Landwind'; 'OP' = 'Opel'; 'RO' = 'Rover'; 'SE' = 'Seat'
    'SK' = ([string][char]0x160 + 'koda')
    'SZ' = 'Suzuki'; 'TO' = 'Toyota'; 'MG' = 'MG'; 'VW' = 'Volkswagen'; 'IN' = 'Infiniti'; 'PR' = 'Proton'; 'PO' = 'Porsche'
    'FA' = 'Farizon'; 'GM' = 'General Motors'; 'GL' = 'Geely'
    'MT' = 'Mitsubishi'; 'LE' = 'Lexus'; 'PU' = 'Puch'; 'SM' = 'Smart'; 'MI' = 'Mini'; 'TE' = 'Tesla'; 'ZS' = 'Zastava'
}

function ConvertTo-MoneyOrNull([string]$s) {
    $d = [decimal]0
    if ([decimal]::TryParse($s, [Globalization.NumberStyles]::Number, $Inv, [ref]$d)) { return $d }
    return $null
}

# ---- povijest cijena (agent pamti cijene iz svojih pokretanja; za dijelove nema zurnala cijena u bazi)
# Datoteka: jedan red po PROMJENI cijene: kljuc<TAB>yyyy-MM-dd<TAB>cijena
function Read-PriceHistory([string]$Path) {
    $h = @{}
    if (Test-Path $Path) {
        foreach ($line in [IO.File]::ReadAllLines($Path, $Utf8NoBom)) {
            $p = $line.Split("`t")
            if ($p.Count -ne 3) { continue }
            $price = ConvertTo-MoneyOrNull $p[2]
            if ($null -eq $price) { continue }
            if (-not $h.ContainsKey($p[0])) { $h[$p[0]] = New-Object System.Collections.ArrayList }
            [void]$h[$p[0]].Add(@($p[1], $price))
        }
    }
    return $h
}

function Get-WindowStart([string]$Today) {
    return ([datetime]::ParseExact($Today, 'yyyy-MM-dd', $Inv)).AddDays(-$HistoryDays).ToString('yyyy-MM-dd')
}

# Najnizi iznos u zadnjih 30 dana: trenutna cijena, cijene koje su poceli vrijediti u prozoru
# i cijena koja je vrijedila na pocetku prozora.
function Get-Lowest30($Hist, [string]$Key, [decimal]$Current, [string]$Today) {
    $start = Get-WindowStart $Today
    $min = $Current
    if ($Hist.ContainsKey($Key)) {
        $atStart = $null
        foreach ($e in $Hist[$Key]) {
            if ([string]::CompareOrdinal([string]$e[0], $start) -le 0) { $atStart = [decimal]$e[1] }
            elseif ([decimal]$e[1] -lt $min) { $min = [decimal]$e[1] }
        }
        if ($null -ne $atStart -and $atStart -lt $min) { $min = $atStart }
    }
    return $min
}

# Biljezi trenutnu cijenu (samo ako se razlikuje od zadnje zabiljezene). Vraca $true ako je nesto dodano.
function Update-PriceHistory($Hist, [string]$Key, [decimal]$Current, [string]$Today) {
    if (-not $Hist.ContainsKey($Key)) { $Hist[$Key] = New-Object System.Collections.ArrayList }
    $list = $Hist[$Key]
    if ($list.Count -gt 0 -and [decimal]$list[$list.Count - 1][1] -eq $Current) { return $false }
    [void]$list.Add(@($Today, $Current))
    return $true
}

function Save-PriceHistory($Hist, [string]$Path, [string]$Today) {
    $start = Get-WindowStart $Today
    $lines = New-Object System.Collections.Generic.List[string]
    foreach ($k in $Hist.Keys) {
        $list = $Hist[$k]
        $keepFrom = 0
        for ($i = 0; $i -lt $list.Count; $i++) {
            if ([string]::CompareOrdinal([string]$list[$i][0], $start) -le 0) { $keepFrom = $i }
        }
        for ($i = $keepFrom; $i -lt $list.Count; $i++) {
            $lines.Add(('{0}{1}{2}{1}{3}' -f $k, "`t", $list[$i][0], ([decimal]$list[$i][1]).ToString('0.00##', $Inv)))
        }
    }
    $dir = Split-Path $Path
    if ($dir) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
    $tmp = $Path + '.tmp'
    [IO.File]::WriteAllLines($tmp, $lines.ToArray(), $Utf8NoBom)
    Move-Item -Force -LiteralPath $tmp -Destination $Path
}

# ---- pravila po retku
# $Vals: hashtable stupac -> tekst (vec ociscen); $Idx: stupci koje je SQL stvarno vratio;
# $Hist: povijest cijena ili $null kad SQL sam vraca najnizu cijenu.
# Vraca $true ako je povijest izmijenjena.
function Complete-Row([hashtable]$Vals, [hashtable]$Idx, [string[]]$Cols, $Hist, [string]$Today) {
    $changed = $false
    $mal = ConvertTo-MoneyOrNull $Vals['maloprodajna_cijena']
    $sid = ConvertTo-MoneyOrNull $Vals['sidrena_cijena']

    # barkod: samo 8-14 znamenki, inace prazno (portal odbija cijelu datoteku zbog jednog losog barkoda)
    if ($Vals.ContainsKey('barkod')) {
        $digits = ($Vals['barkod'] -replace '\D', '')
        $Vals['barkod'] = if ($digits.Length -ge 8 -and $digits.Length -le 14) { $digits } else { '' }
    }

    # marka: sifra marke iz DMS-a (npr. RE) -> naziv; nepoznata ili prazna sifra = Ostale marke.
    # Vrijednosti dulje od 2 znaka (SQL je vec vratio naziv) ostaju kako jesu.
    if ($Vals.ContainsKey('marka')) {
        $m = $Vals['marka']
        if ($m.Length -le 2) {
            $Vals['marka'] = if ($m -ne '' -and $BrandMap.ContainsKey($m)) { $BrandMap[$m] } else { 'Ostale marke' }
        }
    }

    # poseban oblik prodaje: ako SQL ne vraca, cijena ispod cijene na dan 10.9.2026. znaci AKCIJA
    if (-not $Idx.ContainsKey('posebni_oblik_prodaje')) {
        if ($null -ne $mal -and $null -ne $sid -and $mal -lt $sid) {
            $Vals['posebni_oblik_prodaje'] = 'da'
            if ($Vals['naziv_posebnog_oblika_prodaje'] -eq '') { $Vals['naziv_posebnog_oblika_prodaje'] = 'AKCIJA' }
        } else {
            $Vals['posebni_oblik_prodaje'] = 'ne'
        }
    }

    # cijena za jedinicu mjere (bez popusta): ako SQL ne vraca, vrijedi veca od maloprodajne i cijene na dan 10.9.2026.
    if (($Cols -contains 'cijena_po_jedinici_mjere') -and -not $Idx.ContainsKey('cijena_po_jedinici_mjere') -and $null -ne $mal) {
        $reg = if ($null -ne $sid -and $sid -gt $mal) { $sid } else { $mal }
        $Vals['cijena_po_jedinici_mjere'] = $reg.ToString('0.00##', $Inv)
    }

    # najniza cijena u zadnjih 30 dana: iz povijesti agenta (kljuc = sifra, kod usluga naziv)
    if ($null -ne $Hist -and $null -ne $mal) {
        # kljuc: sifra + marka (u bazi ista sifra moze postojati za vise marki), kod usluga naziv
        $key = if ($Vals.ContainsKey('sifra')) { $Vals['sifra'] } else { $Vals['naziv_usluge'] }
        if ($key -ne '' -and $Vals.ContainsKey('marka') -and $Vals['marka'] -ne '') { $key = $key + '|' + $Vals['marka'] }
        if ($key -ne '') {
            if (Update-PriceHistory $Hist $key $mal $Today) { $changed = $true }
            $Vals['najniza_cijena_30_dana'] = (Get-Lowest30 $Hist $key $mal $Today).ToString('0.00##', $Inv)
        }
    }
    return $changed
}
