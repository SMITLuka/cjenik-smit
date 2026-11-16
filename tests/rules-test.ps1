# Test pravila agenta (agent\Rules.ps1) - ne koristi bazu ni mrezu.
# Pokretanje: powershell -NoProfile -ExecutionPolicy Bypass -File tests\rules-test.ps1
$ErrorActionPreference = 'Stop'
$Inv = [Globalization.CultureInfo]::InvariantCulture
$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)
. (Join-Path (Split-Path -Parent $PSScriptRoot) 'agent\Rules.ps1')

$script:pass = 0; $script:fail = 0
function T([string]$name, [bool]$ok) {
    if ($ok) { $script:pass++; Write-Host "  ok   $name" } else { $script:fail++; Write-Host "  FAIL $name" -ForegroundColor Red }
}
function NewVals($mal, $sid, $sifra = 'A1', $barkod = '') {
    @{ sifra = $sifra; maloprodajna_cijena = $mal; sidrena_cijena = $sid; posebni_oblik_prodaje = ''; naziv_posebnog_oblika_prodaje = ''; cijena_po_jedinici_mjere = ''; barkod = $barkod; najniza_cijena_30_dana = $mal }
}
$cols = @('sifra', 'maloprodajna_cijena', 'sidrena_cijena', 'posebni_oblik_prodaje', 'naziv_posebnog_oblika_prodaje', 'cijena_po_jedinici_mjere', 'barkod', 'najniza_cijena_30_dana')
$idxNone = @{ sifra = 0; maloprodajna_cijena = 1; sidrena_cijena = 2 }

# akcija i cijena bez popusta
$v = NewVals '80.00' '100.00'
[void](Complete-Row $v $idxNone $cols $null '2026-11-17')
T 'cijena ispod sidrene = akcija' ($v['posebni_oblik_prodaje'] -eq 'da' -and $v['naziv_posebnog_oblika_prodaje'] -eq 'AKCIJA')
T 'cijena za jedinicu mjere = cijena bez popusta (sidrena)' ($v['cijena_po_jedinici_mjere'] -eq '100.00')
$v = NewVals '120.00' '100.00'
[void](Complete-Row $v $idxNone $cols $null '2026-11-17')
T 'poskupljenje nije akcija' ($v['posebni_oblik_prodaje'] -eq 'ne' -and $v['naziv_posebnog_oblika_prodaje'] -eq '')
T 'poskupljenje: cijena za jedinicu = maloprodajna' ($v['cijena_po_jedinici_mjere'] -eq '120.00')
$v = NewVals '50.00' '50.00'
[void](Complete-Row $v $idxNone $cols $null '2026-11-17')
T 'jednake cijene nisu akcija' ($v['posebni_oblik_prodaje'] -eq 'ne')

# ako SQL sam vraca oblik prodaje ili cijenu po jedinici, agent ih ne dira
$v = NewVals '80.00' '100.00'; $v['posebni_oblik_prodaje'] = 'da'; $v['naziv_posebnog_oblika_prodaje'] = 'Rasprodaja'; $v['cijena_po_jedinici_mjere'] = '77.00'
$idxFull = @{ sifra = 0; maloprodajna_cijena = 1; sidrena_cijena = 2; posebni_oblik_prodaje = 3; cijena_po_jedinici_mjere = 4 }
[void](Complete-Row $v $idxFull $cols $null '2026-11-17')
T 'vrijednosti iz SQL-a ostaju (poseban oblik da, naziv, cijena po jedinici)' ($v['posebni_oblik_prodaje'] -eq 'da' -and $v['naziv_posebnog_oblika_prodaje'] -eq 'Rasprodaja' -and $v['cijena_po_jedinici_mjere'] -eq '77.00')
$v = NewVals '80.00' '100.00'; $v['posebni_oblik_prodaje'] = 'NE'; $v['cijena_po_jedinici_mjere'] = '80.00'
[void](Complete-Row $v $idxFull $cols $null '2026-11-17')
T 'SQL kaze NE, ali je cijena ispod sidrene: AKCIJA i cijena bez popusta' ($v['posebni_oblik_prodaje'] -eq 'da' -and $v['naziv_posebnog_oblika_prodaje'] -eq 'AKCIJA' -and $v['cijena_po_jedinici_mjere'] -eq '100.00')

# normalizacije i zadane vrijednosti
$cn = @('posebni_oblik_prodaje', 'dostupnost', 'neto_kolicina', 'jedinica_mjere', 'maloprodajna_cijena', 'bazna_cijena', 'sidrena_cijena', 'najniza_cijena_30_dana')
$n = @{ posebni_oblik_prodaje = 'NE'; dostupnost = 'DA'; neto_kolicina = ''; jedinica_mjere = ''; maloprodajna_cijena = '12.00'; bazna_cijena = ''; sidrena_cijena = ''; najniza_cijena_30_dana = '' }
[void](Complete-Row $n @{ posebni_oblik_prodaje = 0; dostupnost = 1; najniza_cijena_30_dana = 2 } $cn $null '2026-11-17' $true)
T 'DA/NE se normaliziraju (dostupno, ne)' ($n['dostupnost'] -eq 'dostupno' -and $n['posebni_oblik_prodaje'] -eq 'ne')
T 'neto 1 i jedinica KOM kad fale' ($n['neto_kolicina'] -eq '1' -and $n['jedinica_mjere'] -eq 'KOM')
T 'prazna bazna i sidrena = maloprodajna' ($n['bazna_cijena'] -eq '12.00' -and $n['sidrena_cijena'] -eq '12.00')
T 'prazna najniza iz SQL-a = maloprodajna (bez povijesti)' ($n['najniza_cijena_30_dana'] -eq '12.00')

# barkod
$v = NewVals '1.00' '1.00' 'A1' '3850000000011'; [void](Complete-Row $v $idxNone $cols $null '2026-11-17')
T 'valjan barkod ostaje' ($v['barkod'] -eq '3850000000011')
$v = NewVals '1.00' '1.00' 'A1' 'ABC-12'; [void](Complete-Row $v $idxNone $cols $null '2026-11-17')
T 'los barkod postaje prazan' ($v['barkod'] -eq '')
$v = NewVals '1.00' '1.00' 'A1' '123'; [void](Complete-Row $v $idxNone $cols $null '2026-11-17')
T 'prekratak barkod postaje prazan' ($v['barkod'] -eq '')

# povijest cijena i najniza cijena u 30 dana
$h = @{}
$v = NewVals '100.00' '100.00'; [void](Complete-Row $v $idxNone $cols $h '2026-11-17')
T 'prvi dan: najniza = trenutna' ($v['najniza_cijena_30_dana'] -eq '100.00')
$v = NewVals '80.00' '100.00'; [void](Complete-Row $v $idxNone $cols $h '2026-11-20')
T 'nakon snizenja najniza = 80' ($v['najniza_cijena_30_dana'] -eq '80.00')
$v = NewVals '100.00' '100.00'; [void](Complete-Row $v $idxNone $cols $h '2026-12-01')
T 'nakon povratka cijene najniza ostaje 80 (unutar 30 dana)' ($v['najniza_cijena_30_dana'] -eq '80.00')
$v = NewVals '100.00' '100.00'; [void](Complete-Row $v $idxNone $cols $h '2026-12-20')
T '30 dana nakon pocetka snizenja ono je jos u prozoru' ($v['najniza_cijena_30_dana'] -eq '80.00')
$v = NewVals '100.00' '100.00'; [void](Complete-Row $v $idxNone $cols $h '2027-01-05')
T 'kad snizenje ispadne iz 30 dana najniza = 100' ($v['najniza_cijena_30_dana'] -eq '100.00')
$v = NewVals '100.00' '100.00'; [void](Complete-Row $v $idxNone $cols $h '2027-01-05')
T 'isti dan ponovno: nema duplikata u povijesti' ($h['A1'].Count -eq 3)

# spremanje i ucitavanje (s ciscenjem starih unosa)
$path = Join-Path ([IO.Path]::GetTempPath()) ('hist_' + [guid]::NewGuid().ToString('N') + '.tsv')
Save-PriceHistory $h $path '2027-01-05'
$h2 = Read-PriceHistory $path
T 'ucitana povijest ima kljuc' ($h2.ContainsKey('A1'))
T 'stari unosi su ocisceni (ostaje cijena koja vrijedi na pocetku prozora)' ($h2['A1'].Count -eq 1)
$v = NewVals '90.00' '100.00'; [void](Complete-Row $v $idxNone $cols $h2 '2027-01-06')
T 'nakon ucitavanja najniza = 90' ($v['najniza_cijena_30_dana'] -eq '90.00')
Remove-Item -LiteralPath $path -Force

# povratna vrijednost: je li povijest izmijenjena (jedan bool, ne niz)
$h3 = @{}
$r1 = Complete-Row (NewVals '10.00' '10.00' 'Z9') $idxNone $cols $h3 '2027-02-01'
$r2 = Complete-Row (NewVals '10.00' '10.00' 'Z9') $idxNone $cols $h3 '2027-02-01'
$r3 = Complete-Row (NewVals '12.00' '12.00' 'Z9') $idxNone $cols $h3 '2027-02-02'
T 'povratna vrijednost: nova cijena = true, ista = false, promjena = true' (($r1 -is [bool]) -and $r1 -and ($r2 -is [bool]) -and (-not $r2) -and $r3)

# ista sifra za dvije marke ne dijeli povijest cijena
$colsM = $cols + 'marka'
$h4 = @{}
$a = NewVals '3.74' '3.74' 'S1'; $a['marka'] = 'Nissan'; [void](Complete-Row $a $idxNone $colsM $h4 '2027-03-01')
$b = NewVals '5.26' '5.26' 'S1'; $b['marka'] = 'Renault'; [void](Complete-Row $b $idxNone $colsM $h4 '2027-03-01')
T 'ista sifra, druga marka: najniza nije pomijesana' ($a['najniza_cijena_30_dana'] -eq '3.74' -and $b['najniza_cijena_30_dana'] -eq '5.26')

# marke s dijakriticima
$colsM2 = $cols + 'marka'
function MarkaZa([string]$code) { $x = NewVals '1.00' '1.00' 'K1'; $x['marka'] = $code; [void](Complete-Row $x $idxNone $colsM2 $null '2027-03-01'); $x['marka'] }
T 'sifre marki se pretvaraju u nazive' ((MarkaZa 'RE') -eq 'Renault' -and (MarkaZa 'MA') -eq 'Mazda' -and (MarkaZa 'LR') -eq 'Land Rover' -and (MarkaZa 'MB') -eq 'Mercedes-Benz' -and (MarkaZa 'ME') -eq 'Mercedes-Benz')
T 'sifra marke ne ovisi o velikim slovima' ((MarkaZa 're') -eq 'Renault')
T 'Skoda, Citroen i Gasperov dobivaju dijakritike' ((MarkaZa 'SK') -ceq ([string][char]0x160 + 'koda') -and (MarkaZa 'CI') -ceq ('Citro' + [string][char]0xEB + 'n') -and (MarkaZa 'GA') -ceq ('Ga' + [string][char]0x161 + 'perov'))
T 'FA=Farizon, GM=General Motors, GL=Geely' ((MarkaZa 'FA') -eq 'Farizon' -and (MarkaZa 'GM') -eq 'General Motors' -and (MarkaZa 'GL') -eq 'Geely')
T 'XX, nepoznata, prazna i zvjezdica = Ostale marke' ((MarkaZa 'XX') -eq 'Ostale marke' -and (MarkaZa 'ZZ') -eq 'Ostale marke' -and (MarkaZa '') -eq 'Ostale marke' -and (MarkaZa '*') -eq 'Ostale marke' -and (MarkaZa 'LM') -eq 'Ostale marke')
T 'naziv iz SQL-a ostaje kako jest' ((MarkaZa 'Renault') -eq 'Renault' -and (MarkaZa 'Ostale marke') -eq 'Ostale marke')
T 'MT=Mitsubishi, MI=Mini, IS=Isuzu' ((MarkaZa 'MT') -eq 'Mitsubishi' -and (MarkaZa 'MI') -eq 'Mini' -and (MarkaZa 'IS') -eq 'Isuzu')

# nula iz SQL-a za najnizu i sidrenu cijenu = nema podatka
$z = @{ sifra = 'X1'; maloprodajna_cijena = '140.36'; bazna_cijena = '0.00'; sidrena_cijena = '0.00'; najniza_cijena_30_dana = '0.00'; posebni_oblik_prodaje = 'ne'; naziv_posebnog_oblika_prodaje = '' }
[void](Complete-Row $z @{ sifra = 0; bazna_cijena = 1; sidrena_cijena = 2; najniza_cijena_30_dana = 3 } @('sifra','maloprodajna_cijena','bazna_cijena','sidrena_cijena','najniza_cijena_30_dana','posebni_oblik_prodaje') @{} '2027-03-01' $true)
T 'nula za najnizu/sidrenu/baznu = maloprodajna' ($z['najniza_cijena_30_dana'] -eq '140.36' -and $z['sidrena_cijena'] -eq '140.36' -and $z['bazna_cijena'] -eq '140.36')

# bez povijesti (SQL sam vraca najnizu) agent ne dira stupac
$v = NewVals '70.00' '100.00'; $v['najniza_cijena_30_dana'] = '65.00'
[void](Complete-Row $v $idxNone $cols $null '2026-11-17')
T 'najniza iz SQL-a ostaje' ($v['najniza_cijena_30_dana'] -eq '65.00')

Write-Host ("`n{0} proslo, {1} palo" -f $script:pass, $script:fail)
if ($script:fail -gt 0) { exit 1 }
