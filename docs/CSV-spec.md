# CSV specifikacija i protokol slanja

## Format datoteke
- Kodiranje **UTF-8** (BOM se tolerira i uklanja), razdjelnik **`;`**, navodnici `"`, prvi redak = zaglavlje, kraj retka `\n` ili `\r\n`.
- Iznosi s **decimalnom točkom**, bez znaka valute i bez razdjelnika tisućica: `1234.50` (do 9 znamenki + do 4 decimale). Valuta: EUR.
- Zaglavlje mora biti **točno** kao dolje (isti redoslijed).
- Polja ne smiju sadržavati novi redak ni kontrolne znakove; tekst ne smije počinjati s `=`, `+`, `@` (zaštita od formula injectiona).
- Datoteka mora imati barem jedan redak podataka.

### `proizvodi` (vozila i rezervni dijelovi u istoj datoteci; ista struktura)
Struktura prema specifikaciji "Sidrene cijene Hrvatska s 17.11.2026.".
```
naziv;sifra;marka;neto_kolicina;jedinica_mjere;cijena_po_jedinici_mjere;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;bazna_cijena;sidrena_cijena;barkod;dostupnost;najniza_cijena_30_dana
```
| Stupac | Obavezno | Pravilo |
|---|---|---|
| naziv | da | tekst ≤ 300 |
| sifra | da | tekst ≤ 100 (kod vozila: ARV SALES ID) |
| marka | ne | tekst ≤ 100 |
| neto_kolicina | ne | broj (decimalna točka), npr. 1 ili 0.75 |
| jedinica_mjere | ne | tekst ≤ 20 (KOM, L); obavezna ako je upisana `cijena_po_jedinici_mjere` |
| cijena_po_jedinici_mjere | ne | iznos s PDV-om i PPMV-om |
| maloprodajna_cijena | da | iznos s PDV-om i PPMV-om i popustom (cijena koja trenutno vrijedi) |
| posebni_oblik_prodaje | da | `da` / `ne` |
| naziv_posebnog_oblika_prodaje | ako je `da` | tekst ≤ 200 (npr. AKCIJA) |
| bazna_cijena | da | iznos na dan 10.9.2026. |
| sidrena_cijena | da | dodatna (sidrena) cijena, iznos na dan 10.9.2026. |
| barkod | ne | 8–14 znamenki |
| dostupnost | da | `dostupno` / `nedostupno` (objavljuju se samo artikli na zalihi, pa je uvijek `dostupno`) |
| najniza_cijena_30_dana | da | iznos s PDV-om i PPMV-om i popustom: najniža cijena u zadnjih 30 dana |

### `radovi` (usluge)
```
naziv_usluge;maloprodajna_cijena;posebni_oblik_prodaje;naziv_posebnog_oblika_prodaje;bazna_cijena;sidrena_cijena;najniza_cijena_30_dana
```
Pravila stupaca isto kao gore (`naziv_usluge` = tekst ≤ 300, obavezno).

Referentni datum nije stupac (specifikacija ga ne traži): bazna i sidrena cijena odnose se na 10.9.2026.
Pravila žive u `app/src/CsvSpec.php` (jedno mjesto; mijenjaju se i za validaciju na serveru i za stranicu `/format.html`).


## Naziv objavljene datoteke (nacrt Pravilnika o načinu objave cjenika, čl. 6(2))
Svaka objava sprema se kao posebna datoteka u arhivu lokacije, s nazivom:

```
<naziv objekta>_<oblik>_<adresa>_<oznaka>_<cjenik>_<broj pohrane>_<GGGGMMDDHHMMSS>.csv
npr. rb-auto-zagreb_salon_ulica-1-zagreb_kb521_proizvodi_000007_20261117070000.csv
```
- *oblik* i *adresa* uzimaju se iz admina (Lokacije); prazno se izostavlja. *oznaka* = oznaka objekta ili <GRP><BETNR>.
- *broj pohrane* je **redni broj objave** za tu lokaciju i cjenik (PRETPOSTAVKA — pojam u nacrtu nije definiran; mijenja se u `Pages::downloadName`).
- Vremenska oznaka = datum i vrijeme slanja (lokalno vrijeme).
- Uz to uvijek postoji stalni `latest.csv` (kopija zadnje objave) za automatsko preuzimanje.

## Bazna / sidrena / najniža cijena
- `bazna_cijena` i `sidrena_cijena` (dodatna cijena) su obje cijene na dan 10.9.2026. Agent: ako SQL vraća samo jednu od njih, upisuje istu vrijednost u obje.
- `najniza_cijena_30_dana`: ako je SQL ne vraća, agent upisuje maloprodajnu cijenu. To je točno samo dok u zadnjih 30 dana nije bilo popusta; za artikle koji su bili na akciji treba stvarna povijest cijena.
## API

Baza: `https://cjenik.sm-it.hr`. Svi zahtjevi su potpisani; odgovori su JSON.

| Metoda i putanja | Opis |
|---|---|
| `GET /api/v1/ping` | provjera potpisa i sata |
| `GET /api/v1/locations` | aktivne lokacije, cjenici (`feeds`: `proizvodi`, `radovi`) i uključeni izvori (`sources`: `dijelovi`, `vozila`, `radovi`) dopušteni ključem |
| `PUT /api/v1/{grp}/{betnr}/{feed}[?date=YYYY-MM-DD]` (`feed` = `proizvodi` \| `radovi`) | tijelo = CSV (opcionalno gzip uz `Content-Encoding: gzip`). `date` smije biti **danas ili jučer**, default danas. |

Statusi: `200` ok · `400` loš zahtjev/SHA · `401` autentikacija (potpis, sat, replay) · `403` IP ili ključ nema pravo · `404` lokacija · `409` feed isključen · `413` preveliko · `422` CSV nije prošao validaciju (`errors[]`) · `429` previše neuspjelih pokušaja · `500` greška servera.

### Zaglavlja zahtjeva
| Zaglavlje | Vrijednost |
|---|---|
| `X-Key-Id` | `k_` + 16 hex znakova (iz admina) |
| `X-Timestamp` | Unix sekunde (UTC), razlika od servera ≤ 300 s |
| `X-Nonce` | 16–64 alfanumerička znaka, **jednokratan** po ključu |
| `X-Content-SHA256` | hex SHA-256 tijela **onako kako se šalje** (nakon gzipa) |
| `X-Signature` | hex HMAC-SHA256, vidi dolje |

### Potpis
```
kanonski_niz = METODA + "\n" + REQUEST_URI + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + SHA256_TIJELA
X-Signature  = hex( HMAC_SHA256( kljuc = TAJNA (kao string), poruka = kanonski_niz ) )
```
- `REQUEST_URI` = putanja + query točno kako se šalje (npr. `/api/v1/ka4/1/proizvodi?date=2026-11-16`).
- Za `GET` je tijelo prazno: `SHA256("")`.
- Referentna implementacija: `agent/Send-Prices.ps1` (`Invoke-Signed`) i `app/src/Hmac.php`.

### Primjer (curl + openssl, bash)
```bash
BODY=proizvodi.csv; URI=/api/v1/ka4/1/proizvodi
TS=$(date +%s); NONCE=$(openssl rand -hex 12); SHA=$(openssl dgst -sha256 -hex < "$BODY" | awk '{print $NF}')
SIG=$(printf 'PUT\n%s\n%s\n%s\n%s' "$URI" "$TS" "$NONCE" "$SHA" | openssl dgst -sha256 -hmac "$SECRET" -hex | awk '{print $NF}')
curl -sS -X PUT "https://cjenik.sm-it.hr$URI" -H "X-Key-Id: $KEY_ID" -H "X-Timestamp: $TS" -H "X-Nonce: $NONCE" \
  -H "X-Content-SHA256: $SHA" -H "X-Signature: $SIG" -H 'Content-Type: text/csv; charset=utf-8' --data-binary @"$BODY"
```

### Opseg ključeva
`global` (sve lokacije — agent), `group` (jedna koncesija), `location` (jedna lokacija). Tajna je u bazi šifrirana (`app_key`), a prikazuje se samo pri izdavanju. Opoziv: admin → Ključevi.
