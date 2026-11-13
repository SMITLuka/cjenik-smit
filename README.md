# cjenik.sm-it.hr — javni portal cjenika (sidrene cijene) po koncesiji i lokaciji

Javne stranice i CSV datoteke za svaku lokaciju:

```
https://cjenik.sm-it.hr/                                neutralna stranica (BEZ popisa koncesija)
https://cjenik.sm-it.hr/autocentarroca/                 koncesija KA7 (samo njezine lokacije)
https://cjenik.sm-it.hr/autocentarroca/1/               lokacija BETNR 1 (preuzimanje + arhiva 30 dana)
https://cjenik.sm-it.hr/autocentarroca/1/dijelovi/latest.csv       rezervni dijelovi (stalni URL)
https://cjenik.sm-it.hr/autocentarroca/1/vozila/latest.csv         vozila (stalni URL)
https://cjenik.sm-it.hr/autocentarroca/1/radovi/latest.csv         radovi (stalni URL)
https://cjenik.sm-it.hr/autocentarroca/1/dijelovi/2026-11-17.csv  arhiva (isto za vozila i radovi) (zadnjih 30 dana)
https://cjenik.sm-it.hr/autocentarroca/format.html      opis stupaca (po koncesiji; opća kopija na /format.html)
```

**Naziv datoteke** svake objave prati nacrt Pravilnika (čl. 6(2)): objekt, oblik, adresa, oznaka, cjenik, broj pohrane i vrijeme slanja — vidi `docs/CSV-spec.md`. Uz to postoji stalni `latest.csv`.

**Oznaka u adresi (slug) je odvojena od interne oznake (GRP).** Javno je `/autocentarroca/`, a interno (baza, agent, API) ostaje `KA7`. Slug se postavlja u adminu (*Koncesije*) ili u `seed/concessions.csv` (stupac `slug`); ako nije postavljen, u adresi je GRP (`/ka7/`). Promjena sluga premješta mapu i **stari URL odmah daje 404**. Agent i API se ne mijenjaju: i dalje šalju na `/api/v1/ka7/1/dijelovi`.

**Tri odvojena cjenika po lokaciji:** `dijelovi` (rezervni dijelovi), `vozila` i `radovi` — svaki ima svoj CSV i svoj SQL u agentu; lokacija koja nema npr. vozila isključi taj cjenik u adminu (Lokacije).

Javni dio **nema prijavu, captchu ni bot-zaštitu** i poslužuje se kao obične datoteke (bez PHP-a).

**Izolacija po koncesiji (navigacija):** iz stranica koncesije KA4 nema poveznice na početnu, na druge koncesije ni na popis svih; početna stranica ne nabraja koncesije, a nema ni `sitemap.xml`. To je namjerno. **Ali to nije kontrola pristupa:** cjenici su javni po zakonu i moraju biti dostupni automatskim alatima bez prijave, pa tko pogodi `/ka7/1/` vidi i taj cjenik. Ako želiš da adresu ne može pogoditi, oznaka u URL-u može dobiti nasumični nastavak (npr. `/ka4-7h2k9/1/`) — recite ako to želite.

## Kako radi

```
[vaša VPN mreža]                                   [Hetzner Webhosting]
 Send-Prices.ps1  --(HTTPS PUT, HMAC potpis)-->   /api/v1/{grp}/{betnr}/{feed}
 07:00, SQL -> CSV                                  validacija -> public_html/{grp}/{betnr}/{feed}/YYYY-MM-DD.csv + latest.csv
 (samo IZLAZNI promet)                              cron svakih 5 min: retencija 30 dana, watchdog 07:40 / prijenos jučerašnjeg 07:55
                                                    /admin (IP allowlist + lozinka + TOTP 2FA)
```

Hosting nikad ne otvara vezu prema vašoj mreži. Jedini ulaz za podatke je potpisani `PUT`.

## Struktura

| Mapa | Sadržaj |
|---|---|
| `public_html/` | document root domene: `index.php` (samo `/api` i `/admin`), `.htaccess`, `assets/`. Ovdje aplikacija **generira** HTML/CSV. |
| `app/` | PHP kod, predlošci, `bin/` CLI skripte. **Mora biti izvan document roota.** |
| `extractor/` | Java servis koji sam čita AS400 i šalje cjenike (vidi odjeljak Extractor). |
| `agent/` | PowerShell agent za vašu mrežu (`Send-Prices.ps1`, `Register-Task.ps1`, primjeri SQL-a). |
| `seed/locations.csv` | 50 lokacija iz `Betins.xls` (dijakritici očišćeni, **provjeri nazive**). |
| `Dockerfile`, `docker/` | slika za Docker (vidi odjeljak Docker). |
| `tests/run.php` | testovi (`php tests/run.php`, SQLite u memoriji). |
| `docs/CSV-spec.md` | specifikacija CSV-a i protokola potpisa. |

## Docker

`Dockerfile` (PHP 8.3 + Apache) pokreće web dio; **sva konfiguracija ide iz varijabli okruženja** (`app/config.docker.php`), u slici nema tajni. Potrebna je MariaDB/MySQL baza.

| Varijabla | Opis |
|---|---|
| `APP_KEY` | obavezno, `php app/bin/gen-key.php` (ako se izgubi, svi ključevi i 2FA se moraju ponovno izdati) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | baza (ili jedan `DB_DSN`) |
| `BASE_URL` | zadano `https://cjenik.sm-it.hr` |
| `INGEST_ALLOWED_IPS`, `ADMIN_ALLOWED_IPS` | zarez-odvojene IP adrese/CIDR; prazno = bez ograničenja (samo za razvoj) |
| `CLIENT_IP_HEADER` | iza reverse proxyja npr. `HTTP_X_FORWARDED_FOR`; postaviti samo ako je kontejner dostupan isključivo preko tog proxyja |
| `CLIENT_IP_HOPS` | koliko pouzdanih proxyja stoji ispred aplikacije (Coolify/Traefik = `1`, CDN + Traefik = `2`). Čita se adresa koju je dodao proxy (s **kraja** `X-Forwarded-For`), nikad prva jer je klijent može krivotvoriti |
| `REQUIRE_HTTPS` | zadano `true` (TLS završava na proxyju, uz `CLIENT_IP_HEADER` se čita `X-Forwarded-Proto`). Admin kolačić je `Secure`, pa **prijava u admin ne radi bez HTTPS-a**: na Coolifyu domena mora biti `https://` |
| `SKIP_MIGRATE` | `1` = ne kreiraj tablice pri pokretanju (kad ih je administrator baze kreirao iz `docker/db/01-schema.sql`) |
| `OPERATOR_NAME`, `OPERATOR_CONTACT`, `ALERT_EMAIL`, `MAIL_FROM`, `RETENTION_DAYS`, `WATCHDOG_*` | neobavezno |

**Baza:** `docker/db/01-schema.sql` (9 tablica, generirano iz koda) i `docker/db/02-user.sql` (baza `cjenik` i korisnik `cjenik_app`, lozinka se generira i ide samo u `DB_PASS`). Tko je što poslao i kada bilježi tablica `uploads` (`key_id` = API ključ pošiljatelja, lokacija, cjenik, datum cijena, broj redaka, SHA-256, naziv datoteke, vrijeme), a radnje u adminu `audit_log`.

**Struktura mapa s CSV-ovima** (ispod `/var/www/html`): `<oznaka koncesije>/<broj lokacije>/<cjenik>/<naziv datoteke>.csv` plus stalni `latest.csv`, npr. `autocentarroca/1/dijelovi/…csv`. Oznaka je slug koncesije (ili GRP). Mape aplikacija sama kreira pri prvom slanju, ali samo za lokacije koje postoje u bazi (zaštita od pisanja u proizvoljne putanje); starije od 30 dana briše cron.

Volumeni: `/var/www/html` (objavljeni cjenici i CSV, **mora biti trajan**) i `/var/www/storage` (logovi, backup). Pri pokretanju kontejner migrira bazu, regenerira javne stranice i svakih 5 minuta pokreće cron (retencija 30 dana, watchdog, prijenos jučerašnjeg cjenika).

```bash
cp .env.example .env        # popuni APP_KEY i DB_PASS
docker compose -f docker-compose.example.yml up --build
# jednokratno: administrator (lozinka + TOTP) i lokacije
docker compose -f docker-compose.example.yml exec web su -s /bin/sh www-data -c "php /var/www/app/bin/create-admin.php"
docker compose -f docker-compose.example.yml exec web su -s /bin/sh www-data -c "php /var/www/app/bin/import-concessions.php /var/www/seed/concessions.csv"
docker compose -f docker-compose.example.yml exec web su -s /bin/sh www-data -c "php /var/www/app/bin/import-locations.php /var/www/seed/locations.csv"
```

> Mail upozorenja (`ALERT_EMAIL`) koristi PHP `mail()`, a kontejner nema MTA: za e-mail treba dodati SMTP relay. Slika nije testirana na računalu na kojem je pripremljena (nema Dockera); prvi `build` treba provjeriti.

## Extractor (servis sam čita AS400)

`extractor/` je Java 17 servis (jt400, bez frameworka) koji **svaki dan u 07:00** izvrši SQL-ove nad AS400 (samo `SELECT`, veza je `setReadOnly`), primijeni ista pravila kao PowerShell agent (marke, akcija, najniža cijena u 30 dana, čišćenje naziva i barkoda) i pošalje CSV na ingest API portala s istim HMAC potpisom. Popis lokacija i uključenih cjenika uzima s portala (`GET /api/v1/locations`), pa se koncesije i cjenici uključuju i isključuju u adminu, bez promjene koda. Agent iz PowerShella i dalje radi i može se koristiti umjesto njega (ili za probu).

Coolify: nova aplikacija iz **istog repoa**, *Base Directory* `/extractor`, Dockerfile.

| Varijabla | Opis |
|---|---|
| `PORTAL_URL` | adresa portala, **https** (npr. `https://cjenik.sm-it.hr`) |
| `PORTAL_KEY_ID`, `PORTAL_SECRET` | API ključ iz admina (*Ključevi*, opseg „Sve lokacije”); tajna samo u Coolify secretima |
| `AS400_HOST`, `AS400_USER`, `AS400_PASSWORD` | isti nazivi kao u MIMV-u. **Poseban korisnik samo za čitanje**, ne korisnik s širokim ovlastima |
| `AS400_JDBC_PARAMS` | neobavezno, zadano `extended metadata=false;keep alive=true;libraries=QGPL;prompt=false;errors=full;date format=iso` |
| `SQL_DIR` | zadano `/config/sql`; unutra `dijelovi/*.sql`, `vozila/*.sql`, `radovi/*.sql` (ili `dijelovi.sql`…). U upitu `{GRP}` i `{BETNR}`. **SQL se ne nalazi u repou** (otkriva strukturu baze): montira se kao datoteke |
| `RUN_AT`, `DEADLINE`, `TZ` | zadano `07:00`, `07:45` (nakon toga nema ponavljanja), `Europe/Zagreb` |
| `RETRIES` | zadano 4 (eksponencijalno čekanje) |
| `ONLY`, `FEED`, `DRY_RUN`, `RUN_ONCE`, `RUN_ON_START` | proba: `RUN_ONCE=true DRY_RUN=true ONLY=kb5/1 FEED=dijelovi` sprema CSV u `/data/out/<datum>/` bez slanja |
| `FROM_DIR` | proba bez AS400: čita `<grp>_<broj>_<cjenik>.csv` iz mape |

**Trajni volumen `/data` je obavezan:** u `/data/state/history` je povijest cijena za „najnižu cijenu u 30 dana” (za dijelove u bazi nema žurnala). Bez volumena se povijest gubi pri svakom deployu. Povijest agenta (`agent\state\history\*.tsv`) ima isti oblik i može se kopirati u `/data/state/history/`.

Sigurnost: lozinka AS400 i API tajna samo u Coolify secretima; servis ne sluša nijedan port; AS400 treba biti dostupan samo s poslužitelja na kojem radi servis; korisnik baze ograničen na čitanje.

> Neprovjereno: stvarno spajanje na AS400 (jt400) i Integration Hub. Provjereno: SQL → CSV (H2), pravila, potpis identičan PHP implementaciji, slanje na pravi portal (`FROM_DIR`), dnevni raspored. Integration Hub nije potreban za prvu verziju (pristupni podaci su u okolinskim varijablama); kasnije se može dodati kao izvor podataka po firmi.

## Postavljanje na Hetzner Webhosting L

> Stvari označene ⚠ nisam mogao provjeriti bez vašeg računa — potvrdi ih na hostingu prije produkcije (vidi dolje).

1. **Domena.** Registriraj `cjenik.sm-it.hr` kod hrvatskog registrara. Kod njega postavi A (i AAAA) zapis za `cjenik.sm-it.hr` i `www` na IP adresu Hetzner paketa (ili NS na Hetznerove nameservere). U Hetznerovoj konzoli dodaj domenu i zatraži SSL certifikat. ⚠
2. **PHP verzija:** u konzoli odaberi PHP 8.2+ (kod je pisan za 8.1+; testiran na 8.5). Potrebna proširenja: `pdo_mysql`, `openssl`, `mbstring`, `zlib`.
3. **Baza:** u konzoli napravi MariaDB bazu i korisnika.
4. **Upload** (SSH/SFTP): sadržaj `public_html/` u document root, a `app/` **pored** njega (npr. `~/app`, `~/public_html`). `index.php` očekuje `../app/bootstrap.php`. Napravi i `~/storage` (`chmod 750`).
5. **Konfiguracija:**
   ```bash
   cp app/config.example.php app/config.php
   php app/bin/gen-key.php        # upiši rezultat u 'app_key'
   chmod 600 app/config.php
   ```
   Uredi `db`, `webroot`, `storage`, `alert_email`, `mail_from`. **`app_key` spremi i izvan servera** (password manager) — bez njega su ključevi i 2FA neupotrebljivi.
6. **Inicijalizacija:**
   ```bash
   php app/bin/migrate.php
   php app/bin/import-locations.php seed/locations.csv   # 63 lokacije + generira javne stranice
   php app/bin/create-admin.php borna                    # lozinka min. 12 znakova; 2FA se postavlja pri prvoj prijavi
   ```
7. **Cron** (konzola ili `crontab -e`; vidi ⚠ o minimalnom intervalu):
   ```cron
   */5 * * * * php ~/app/bin/cron.php
   15 3 * * *  php ~/app/bin/backup.php
   ```
8. **Naziv i web adresa koncesija:** admin → *Koncesije* (zasad nose samo oznaku, npr. `KA4`). Dodaj *pravni naziv*, *adresu* i *broj pohrane* po lokaciji (koriste se u imenu datoteke za preuzimanje).
9. **API ključ za agent:** admin → *Ključevi* → opseg „Sve lokacije”. Tajna se prikazuje jednom.

### Hardening (obavezno prije produkcije)
- **IP allowlist** na dva mjesta (defense-in-depth): u `app/config.php` (`ingest_allowed_ips`, `admin_allowed_ips`) **i** u `public_html/.htaccess` (zakomentirani `Require ip` blok na dnu). Ingest: izlazna javna IP vaše VPN mreže. Admin: vaše IP adrese/VPN.
- **Prava:** `config.php` 600, `storage/` 750; `app/` nikad u document rootu.
- **Provjeri `client_ip_header`:** ako je ispred PHP-a Hetznerov Varnish/proxy, `REMOTE_ADDR` može biti adresa proxyja. Napravi test s `ingest_allowed_ips` i, ako treba, postavi `client_ip_header => 'HTTP_X_FORWARDED_FOR'`. ⚠ (Bez ovoga allowlist ili blokira sve ili ništa.)
- **Varnish/cache:** `/api/` i `/admin/` ne smiju se cacheirati; `latest.csv` mora biti svjež odmah nakon uploada (`Cache-Control: max-age=300` u `.htaccess` — smanji ili isključi ako vidiš stare podatke). ⚠
- **SSH samo ključem**, redovite zakrpe PHP verzije, monitoring dostupnosti (npr. provjera `latest.csv` svakih 5 min).

### Backup
- `backup.php` sprema JSON dump baze u `storage/backups/` (zadnjih 14, **bez** `app_key`).
- Arhiva CSV-a je u `public_html/<grp>/<betnr>/...` — kopiraj izvan servera, npr.
  `rsync -a --delete ~/public_html/ user@storagebox:/backup/cjenik/` dnevno u cronu. Napravi **restore test** (uvjet da backup vrijedi).

## Agent u vašoj mreži (VPN)

Na računalu koje vidi bazu (PowerShell 5.1+, ODBC driver za vašu bazu):

```powershell
cd agent
copy config.example.json config.json        # upiši baseUrl, keyId, ODBC connectionString
copy sql\dijelovi.sql.example sql\dijelovi.sql   # prilagodi svojoj bazi (SELECT; aliasi = nazivi stupaca)
copy sql\vozila.sql.example sql\vozila.sql
copy sql\radovi.sql.example sql\radovi.sql
.\Send-Prices.ps1 -SetSecret                # tajna ključa -> DPAPI (veže se uz ovog Windows korisnika)
.\Send-Prices.ps1 -SetDbPassword            # lozinka baze -> DPAPI (u connection stringu stoji {PWD})
.\Send-Prices.ps1 -DryRun -Only ka4/1       # generira CSV u out\, ne šalje
.\Send-Prices.ps1 -Only ka4/1               # stvarno slanje jedne lokacije
.\Register-Task.ps1                         # dnevni zadatak u 07:00
```

- Retci SQL-a koji počinju s `--` agent uklanja prije izvršavanja (neki ODBC driveri ne podržavaju komentare).
- **SQL po feedu može biti mapa:** `agent\sql\dijelovi\*.sql` (npr. `01-lager-rd.sql`) — agent izvrši svaku datoteku i spoji retke u jedan CSV. Ili jedna datoteka `agent\sql\dijelovi.sql`. Isto za `vozila` i `radovi`.
- **Oznake u SQL-u:** `{GRP}` (oznaka koncesije malim slovima) i `{BETNR}` (broj lokacije); zamjenjuju se u nazivima biblioteka/tablica u SQL-u prema konvenciji vaše baze. Parametri `?` su neobavezni (veže se samo onoliko koliko ih ima: 1 = GRP, 2 = GRP i BETNR).
- SQL se čita kao UTF-8, a ako nije valjan UTF-8, kao Windows-1250.
- DB korisnik neka bude **read-only** i ograničen na potrebne tablice/view-ove.
- Agent radi samo izlazno (HTTPS 443). Retry do `deadline` (07:45), izlazni kod ≠ 0 pri greškama, log u `agent\logs\agent.log`.
- **Pravila agenta (`agent\Rules.ps1`, ne diraju bazu):** ako SQL ne vraća stupce, agent ih izvodi: `posebni_oblik_prodaje` = `da` i naziv `AKCIJA` kad je maloprodajna cijena manja od sidrene (cijene na dan 10.9.2026.); `cijena_po_jedinici_mjere` = cijena bez popusta; `najniza_cijena_30_dana` iz povijesti cijena koju agent bilježi u `agent\state\history\` (točna tek nakon 30 dana rada; za vozila je računa SQL iz žurnala). Šifru marke iz DMS-a (RE, MA, …) agent pretvara u naziv po tablici u `Rules.ps1` (`$BrandMap`, iz DMS tablice marki); nepoznata ili prazna šifra ide kao „Ostale marke”. Barkod koji nije 8–14 znamenki agent briše, a nevaljane nazive (prazan, počinje s `=`, `+`, `@`, kontrolni znakovi) popravlja. Testovi bez baze: `tests\rules-test.ps1`, `tests\agent-test.ps1`.
- **SQL upite moraš napisati ti** (nemam shemu baze) — vidi `agent/sql/*.example`. Svaki upit prima dva parametra (GRP, BETNR) i mora vratiti stupce iz `docs/CSV-spec.md`.

## Što se događa kad nešto fali
- **07:40** nema današnjeg cjenika za lokaciju/feed → e-mail upozorenje (`alert_email`).
- **07:55** automatski se **prenosi jučerašnji** cjenik kao današnji (oznaka „prenesen” u adminu i na javnoj stranici) + sažetak e-mailom. Isključi/izmijeni u `watchdog` konfiguraciji.
- Cjenici starije od 30 dana brišu se automatski (u principu reda).

## Pravne napomene (sažetak istraživanja)
- Obveza: Odluka o objavi cjenika (NN 101/2026), primjena **17.11.2026.** (NN 110/2026). Proizvodi (dijelovi, vozila): ažuriranje 1× dnevno do 08:00; usluge (radovi): pri svakoj promjeni do 08:00; dostupno 30 dana; CSV ili XML; mora se moći dohvaćati automatiziranim alatima.
- Službena CSV specifikacija ne postoji — format u `docs/CSV-spec.md` je naš izbor i lako se mijenja u `app/src/CsvSpec.php`.
- **Otvoreno (potvrditi s HOK-om/Ministarstvom):** (1) odluka kaže da se cjenik objavljuje „na mrežnim stranicama trgovca” — svaka koncesija bi trebala na svojoj stranici imati poveznicu na svoj URL (ili vlastitu poddomenu); (2) vozila nisu izričito izuzeta; (3) pravilnik o baznoj cijeni još je u savjetovanju (nacrt: rok 11:00 sljedeći radni dan) — rokovi se mijenjaju u `watchdog`/agentu.
- Izvori su većinom stručni članci; NN 101/2026 pročitan je izravno, datum primjene iz sekundarnih izvora.

## Tablica za popunjavanje podataka (koncesije i lokacije)
`podaci\Podaci-koncesije-i-lokacije.xlsx` — popuni žuta polja (pravni naziv, adresa, oblik prodajnog mjesta, oznaka objekta, koji cjenici se objavljuju, web adresa koncesije). Upute su na prvom listu. Zatim:
1. `podaci\Izvezi-CSV.ps1` (ne treba Excel) napravi `podaci\izvoz\koncesije.csv` i `lokacije.csv`;
2. uvezi ih u adminu (**Uvoz**) ili naredbama `php app/bin/import-concessions.php podaci/izvoz/koncesije.csv` i `php app/bin/import-locations.php podaci/izvoz/lokacije.csv`.
Uvoz je cjelina (greška = ništa se ne sprema), prazna ćelija ne mijenja postojeću vrijednost, a promjena `slug` stupca gasi stari URL.
## Provjeri na Hetzneru (⚠ nije potvrđeno)
`.htaccess` (`Require ip`, `<If>`, rewrite) · PHP limiti (`memory_limit` 384 MB je naveden; provjeri `post_max_size`, `upload_max_filesize` za ručni upload, `max_execution_time`) · minimalni interval cron joba · SSL certifikat uključen · ponašanje Varnisha · `mail()` radi · automatski backup paketa (nezavisno od našeg).

## Razvoj i testovi
```bash
php tests/run.php          # 132 provjere: IP, TOTP (RFC 6238), crypto, CSV validacija, API (potpis/replay/opseg ključeva), retencija, watchdog
```
Lokalno bez MariaDB-a: konfiguracija s `'dsn' => 'sqlite:/putanja/db.sqlite'` i `'require_https' => false`, pa `php -S 127.0.0.1:8099 -t public_html`.
