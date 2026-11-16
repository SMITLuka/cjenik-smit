package hr.smit.cjenik.extractor;

import java.math.BigDecimal;
import java.math.RoundingMode;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Set;
import java.util.regex.Pattern;

/**
 * Pravila koja se primjenjuju na svaki redak cjenika (isto kao agent\Rules.ps1). Ne diraju bazu.
 */
public final class RowRules {
    /** Sifre marki iz DMS-a (tablica marki) -> naziv. Nepoznata ili prazna sifra = Ostale marke. */
    private static final Map<String, String> BRANDS = Map.ofEntries(
        Map.entry("CH", "Chrysler"), Map.entry("DC", "Dacia"), Map.entry("FO", "Ford"), Map.entry("NI", "Nissan"),
        Map.entry("PE", "Peugeot"), Map.entry("RE", "Renault"), Map.entry("RX", "Renault"),
        Map.entry("XX", "Ostale marke"), Map.entry("VO", "Volvo"), Map.entry("KI", "Kia"), Map.entry("SU", "Subaru"),
        Map.entry("SY", "SsangYong"), Map.entry("GA", "Gašperov"), Map.entry("IV", "Iveco"),
        Map.entry("ME", "Mercedes-Benz"), Map.entry("MB", "Mercedes-Benz"), Map.entry("DA", "DAF"),
        Map.entry("MA", "Mazda"), Map.entry("JA", "Jaguar"), Map.entry("FI", "Fiat"), Map.entry("LR", "Land Rover"),
        Map.entry("AR", "Alfa Romeo"), Map.entry("JE", "Jeep"), Map.entry("LA", "Lancia"),
        Map.entry("AM", "Aston Martin"), Map.entry("AU", "Audi"), Map.entry("BE", "Bentley"), Map.entry("BM", "BMW"),
        Map.entry("CA", "Cadillac"), Map.entry("CI", "Citroën"), Map.entry("CV", "Chevrolet/Daewoo"),
        Map.entry("DO", "Dodge"), Map.entry("FE", "Ferrari"), Map.entry("HO", "Honda"), Map.entry("HU", "Hummer"),
        Map.entry("HY", "Hyundai"), Map.entry("IS", "Isuzu"), Map.entry("LC", "Lincoln"), Map.entry("LD", "Lada"),
        Map.entry("LG", "Lamborghini"), Map.entry("LO", "Lotus"), Map.entry("LW", "Landwind"), Map.entry("OP", "Opel"),
        Map.entry("RO", "Rover"), Map.entry("SE", "Seat"), Map.entry("SK", "Škoda"), Map.entry("SZ", "Suzuki"),
        Map.entry("TO", "Toyota"), Map.entry("MG", "MG"), Map.entry("VW", "Volkswagen"), Map.entry("IN", "Infiniti"),
        Map.entry("PR", "Proton"), Map.entry("PO", "Porsche"), Map.entry("FA", "Farizon"),
        Map.entry("GM", "General Motors"), Map.entry("GL", "Geely"), Map.entry("MT", "Mitsubishi"),
        Map.entry("LE", "Lexus"), Map.entry("PU", "Puch"), Map.entry("SM", "Smart"), Map.entry("MI", "Mini"),
        Map.entry("TE", "Tesla"), Map.entry("ZS", "Zastava"));

    private static final Pattern MONEY = Pattern.compile("-?\\d+(\\.\\d+)?");
    private static final Pattern NON_DIGIT = Pattern.compile("\\D");

    private RowRules() {
    }

    /** Iznos u obliku 0.00## (najmanje 2, najvise 4 decimale, bez tisucica). */
    public static String fmt(BigDecimal d) {
        BigDecimal r = d.setScale(4, RoundingMode.HALF_UP).stripTrailingZeros();
        if (r.scale() < 2) {
            r = r.setScale(2);
        }
        return r.toPlainString();
    }

    /** Iznos kao tekst ili prazno ako nije valjan pozitivan iznos (cijena 0 u bazi znaci "nema podatka"). */
    static String positiveOrEmpty(String s) {
        BigDecimal m = money(s);
        return (m == null || m.signum() <= 0) ? "" : s;
    }

    public static BigDecimal money(String s) {
        if (s == null || !MONEY.matcher(s).matches()) {
            return null;
        }
        return new BigDecimal(s);
    }

    /** Zadrzava staro ponasanje: hist == null znaci da SQL sam vraca najnizu cijenu. */
    public static boolean complete(Map<String, String> v, Set<String> sqlCols, List<String> cols,
                                   PriceHistory hist, String today) {
        return complete(v, sqlCols, cols, hist, today, hist == null);
    }

    /**
     * @param v          stupac -> tekst (vec ociscen); mijenja se
     * @param sqlCols    stupci koje je SQL stvarno vratio (nakon zamjene bazna/sidrena)
     * @param hist       povijest cijena (null = ne vodi se)
     * @param sqlNajniza true ako SQL vraca stupac najniza_cijena_30_dana (prazna vrijednost se i dalje dopunjuje)
     * @return true ako je povijest izmijenjena
     */
    public static boolean complete(Map<String, String> v, Set<String> sqlCols, List<String> cols,
                                   PriceHistory hist, String today, boolean sqlNajniza) {
        boolean changed = false;
        BigDecimal mal = money(v.get("maloprodajna_cijena"));

        // da/ne i dostupnost: SQL smije vracati DA/NE; portal trazi da/ne i dostupno/nedostupno
        if (v.containsKey("posebni_oblik_prodaje")) {
            v.put("posebni_oblik_prodaje", v.get("posebni_oblik_prodaje").toLowerCase(Locale.ROOT));
        }
        if (v.containsKey("dostupnost")) {
            String d = v.get("dostupnost").toLowerCase(Locale.ROOT);
            if (d.equals("da")) {
                d = "dostupno";
            } else if (d.equals("ne")) {
                d = "nedostupno";
            }
            v.put("dostupnost", d);
        }

        // neto kolicina i jedinica mjere: po specifikaciji 1 KOM kad ih SQL ne daje (cijena po jedinici trazi jedinicu)
        if (v.containsKey("neto_kolicina") && v.get("neto_kolicina").isEmpty()) {
            v.put("neto_kolicina", "1");
        }
        if (v.containsKey("jedinica_mjere") && v.get("jedinica_mjere").isEmpty()) {
            v.put("jedinica_mjere", "KOM");
        }

        // bazna i sidrena su na dan 10.9.2026. iste; ako su obje prazne (nema povijesti cijene), vrijedi maloprodajna
        if (v.containsKey("sidrena_cijena") || v.containsKey("bazna_cijena")) {
            String bz = positiveOrEmpty(v.getOrDefault("bazna_cijena", ""));
            String sd = positiveOrEmpty(v.getOrDefault("sidrena_cijena", ""));
            if (bz.isEmpty() && !sd.isEmpty()) {
                bz = sd;
            } else if (sd.isEmpty() && !bz.isEmpty()) {
                sd = bz;
            } else if (bz.isEmpty() && mal != null) {
                bz = fmt(mal);
                sd = bz;
            }
            if (v.containsKey("bazna_cijena")) {
                v.put("bazna_cijena", bz);
            }
            if (v.containsKey("sidrena_cijena")) {
                v.put("sidrena_cijena", sd);
            }
        }
        BigDecimal sid = money(v.get("sidrena_cijena"));

        // barkod: samo 8-14 znamenki, inace prazno (portal odbija cijelu datoteku zbog jednog losog barkoda)
        if (v.containsKey("barkod")) {
            String digits = NON_DIGIT.matcher(v.get("barkod")).replaceAll("");
            v.put("barkod", digits.length() >= 8 && digits.length() <= 14 ? digits : "");
        }

        // marka: sifra -> naziv; vrijednosti dulje od 2 znaka (SQL je vec vratio naziv) ostaju kako jesu
        if (v.containsKey("marka")) {
            String m = v.get("marka");
            if (m.length() <= 2) {
                String name = m.isEmpty() ? null : BRANDS.get(m.toUpperCase(Locale.ROOT));
                v.put("marka", name != null ? name : "Ostale marke");
            }
        }

        // poseban oblik prodaje: cijena ispod cijene na dan 10.9.2026. = AKCIJA (i kad SQL tvrdi "ne"); SQL "da" ostaje
        boolean below = mal != null && sid != null && mal.compareTo(sid) < 0;
        String pob = v.getOrDefault("posebni_oblik_prodaje", "");
        if (below && !pob.equals("da")) {
            v.put("posebni_oblik_prodaje", "da");
            if (v.getOrDefault("naziv_posebnog_oblika_prodaje", "").isEmpty()) {
                v.put("naziv_posebnog_oblika_prodaje", "AKCIJA");
            }
        } else if (pob.isEmpty()) {
            v.put("posebni_oblik_prodaje", "ne");
        }

        // cijena za jedinicu mjere (bez popusta): prazna ili jednaka maloprodajnoj kod akcije -> veca od maloprodajne i sidrene
        if (cols.contains("cijena_po_jedinici_mjere") && mal != null) {
            String cur = v.getOrDefault("cijena_po_jedinici_mjere", "");
            BigDecimal curM = money(cur);
            BigDecimal reg = (sid != null && sid.compareTo(mal) > 0) ? sid : mal;
            if (cur.isEmpty() || (below && curM != null && curM.compareTo(mal) == 0)) {
                v.put("cijena_po_jedinici_mjere", fmt(reg));
            }
        }

        // najniza cijena u zadnjih 30 dana: iz SQL-a ako je vratio vrijednost, inace iz povijesti (kljuc = sifra + marka)
        boolean sqlHas = sqlNajniza && !positiveOrEmpty(v.getOrDefault("najniza_cijena_30_dana", "")).isEmpty();
        if (hist != null && mal != null) {
            String key = v.containsKey("sifra") ? v.get("sifra") : v.getOrDefault("naziv_usluge", "");
            if (!key.isEmpty() && v.containsKey("marka") && !v.get("marka").isEmpty()) {
                key = key + "|" + v.get("marka");
            }
            if (!key.isEmpty()) {
                if (hist.update(key, mal, today)) {
                    changed = true;
                }
                if (!sqlHas) {
                    v.put("najniza_cijena_30_dana", fmt(hist.lowest30(key, mal, today)));
                }
            } else if (!sqlHas) {
                v.put("najniza_cijena_30_dana", fmt(mal));
            }
        } else if (!sqlHas && mal != null && v.containsKey("najniza_cijena_30_dana")) {
            v.put("najniza_cijena_30_dana", fmt(mal));
        }
        return changed;
    }
}
