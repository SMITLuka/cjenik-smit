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

# Iznos kao tekst ili prazno ako nije valjan pozitivan iznos (cijena 0 u bazi znaci "nema podatka").
function Get-PositiveOrEmpty([string]$s) {
    $m = ConvertTo-MoneyOrNull $s
    if ($null -eq $m -or $m -le 0) { return '' }
    return $s
}

# ---- pravila po retku
# $Vals: hashtable stupac -> tekst (vec ociscen); $Idx: stupci koje je SQL stvarno vratio;
# $Hist: povijest cijena ili $null; $SqlNajniza: SQL vraca stupac najniza_cijena_30_dana (prazna vrijednost se dopunjuje).
# Vraca $true ako je povijest izmijenjena.
function Complete-Row([hashtable]$Vals, [hashtable]$Idx, [string[]]$Cols, $Hist, [string]$Today, $SqlNajniza = $null) {
    if ($null -eq $SqlNajniza) { $SqlNajniza = ($null -eq $Hist) }
    $changed = $false
    $mal = ConvertTo-MoneyOrNull $Vals['maloprodajna_cijena']

    # da/ne i dostupnost: SQL smije vracati DA/NE; portal trazi da/ne i dostupno/nedostupno
    if ($Vals.ContainsKey('posebni_oblik_prodaje')) { $Vals['posebni_oblik_prodaje'] = $Vals['posebni_oblik_prodaje'].ToLower() }
    if ($Vals.ContainsKey('dostupnost')) {
        $d = $Vals['dostupnost'].ToLower()
        if ($d -eq 'da') { $d = 'dostupno' } elseif ($d -eq 'ne') { $d = 'nedostupno' }
        $Vals['dostupnost'] = $d
    }

    # neto kolicina i jedinica mjere: po specifikaciji 1 KOM kad ih SQL ne daje
    if ($Vals.ContainsKey('neto_kolicina') -and $Vals['neto_kolicina'] -eq '') { $Vals['neto_kolicina'] = '1' }
    if ($Vals.ContainsKey('jedinica_mjere') -and $Vals['jedinica_mjere'] -eq '') { $Vals['jedinica_mjere'] = 'KOM' }

    # bazna i sidrena su na dan 10.9.2026. iste; ako su obje prazne, vrijedi maloprodajna
    if ($Vals.ContainsKey('sidrena_cijena') -or $Vals.ContainsKey('bazna_cijena')) {
        $bz = if ($Vals.ContainsKey('bazna_cijena')) { Get-PositiveOrEmpty $Vals['bazna_cijena'] } else { '' }
        $sd = if ($Vals.ContainsKey('sidrena_cijena')) { Get-PositiveOrEmpty $Vals['sidrena_cijena'] } else { '' }
        if ($bz -eq '' -and $sd -ne '') { $bz = $sd }
        elseif ($sd -eq '' -and $bz -ne '') { $sd = $bz }
        elseif ($bz -eq '' -and $null -ne $mal) { $bz = $mal.ToString('0.00##', $Inv); $sd = $bz }
        if ($Vals.ContainsKey('bazna_cijena')) { $Vals['bazna_cijena'] = $bz }
        if ($Vals.ContainsKey('sidrena_cijena')) { $Vals['sidrena_cijena'] = $sd }
    }
    $sid = ConvertTo-MoneyOrNull $Vals['sidrena_cijena']

    # barkod: samo 8-14 znamenki, inace prazno
    if ($Vals.ContainsKey('barkod')) {
        $digits = ($Vals['barkod'] -replace '\D', '')
        $Vals['barkod'] = if ($digits.Length -ge 8 -and $digits.Length -le 14) { $digits } else { '' }
    }

    # marka: sifra -> naziv; vrijednosti dulje od 2 znaka ostaju; nepoznata ili prazna = Ostale marke
    if ($Vals.ContainsKey('marka')) {
        $m = $Vals['marka']
        if ($m.Length -le 2) {
            $brand = if ($m -ne '' -and $BrandMap.ContainsKey($m)) { $BrandMap[$m] } else { 'Ostale marke' }
            # sifra XX (ostale marke): ako naziv pocinje s Renault, Dacia ili Nissan, to je marka
            if ($brand -eq 'Ostale marke' -and $Vals.ContainsKey('naziv')) {
                $first = ($Vals['naziv'].Trim() -split '\s+')[0]
                foreach ($b in @('Renault', 'Dacia', 'Nissan')) { if ($first -ieq $b) { $brand = $b } }
            }
            $Vals['marka'] = $brand
        }
    }

    # poseban oblik prodaje: cijena ispod cijene na dan 10.9.2026. = AKCIJA (i kad SQL tvrdi "ne"); SQL "da" ostaje
    $below = ($null -ne $mal -and $null -ne $sid -and $mal -lt $sid)
    $pob = if ($Vals.ContainsKey('posebni_oblik_prodaje')) { $Vals['posebni_oblik_prodaje'] } else { '' }
    if ($below -and $pob -ne 'da') {
        $Vals['posebni_oblik_prodaje'] = 'da'
        if ($Vals['naziv_posebnog_oblika_prodaje'] -eq '') { $Vals['naziv_posebnog_oblika_prodaje'] = 'AKCIJA' }
    } elseif ($pob -eq '') {
        $Vals['posebni_oblik_prodaje'] = 'ne'
    }

    # cijena za jedinicu mjere (bez popusta): prazna ili jednaka maloprodajnoj kod akcije -> veca od maloprodajne i sidrene
    if (($Cols -contains 'cijena_po_jedinici_mjere') -and $null -ne $mal) {
        $cur = if ($Vals.ContainsKey('cijena_po_jedinici_mjere')) { $Vals['cijena_po_jedinici_mjere'] } else { '' }
        $curM = ConvertTo-MoneyOrNull $cur
        $reg = if ($null -ne $sid -and $sid -gt $mal) { $sid } else { $mal }
        if ($cur -eq '' -or ($below -and $null -ne $curM -and $curM -eq $mal)) {
            $Vals['cijena_po_jedinici_mjere'] = $reg.ToString('0.00##', $Inv)
        }
    }

    # najniza cijena u zadnjih 30 dana: iz SQL-a ako je vratio vrijednost, inace iz povijesti (kljuc = sifra + marka)
    $sqlHas = ($SqlNajniza -and $Vals.ContainsKey('najniza_cijena_30_dana') -and (Get-PositiveOrEmpty $Vals['najniza_cijena_30_dana']) -ne '')
    if ($null -ne $Hist -and $null -ne $mal) {
        $key = if ($Vals.ContainsKey('sifra')) { $Vals['sifra'] } else { $Vals['naziv_usluge'] }
        if ($key -ne '' -and $Vals.ContainsKey('marka') -and $Vals['marka'] -ne '') { $key = $key + '|' + $Vals['marka'] }
        if ($key -ne '') {
            if (Update-PriceHistory $Hist $key $mal $Today) { $changed = $true }
            if (-not $sqlHas) { $Vals['najniza_cijena_30_dana'] = (Get-Lowest30 $Hist $key $mal $Today).ToString('0.00##', $Inv) }
        } elseif (-not $sqlHas) {
            $Vals['najniza_cijena_30_dana'] = $mal.ToString('0.00##', $Inv)
        }
    } elseif (-not $sqlHas -and $null -ne $mal -and $Vals.ContainsKey('najniza_cijena_30_dana')) {
        $Vals['najniza_cijena_30_dana'] = $mal.ToString('0.00##', $Inv)
    }
    return $changed
}
