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

    public static BigDecimal money(String s) {
        if (s == null || !MONEY.matcher(s).matches()) {
            return null;
        }
        return new BigDecimal(s);
    }

    /**
     * @param v       stupac -> tekst (vec ociscen); mijenja se
     * @param sqlCols stupci koje je SQL stvarno vratio (nakon zamjene bazna/sidrena)
     * @param hist    povijest cijena, ili null kad SQL sam vraca najnizu cijenu
     * @return true ako je povijest izmijenjena
     */
    public static boolean complete(Map<String, String> v, Set<String> sqlCols, List<String> cols,
                                   PriceHistory hist, String today) {
        boolean changed = false;
        BigDecimal mal = money(v.get("maloprodajna_cijena"));
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

        // poseban oblik prodaje: ako SQL ne vraca, cijena ispod cijene na dan 10.9.2026. znaci AKCIJA
        if (!sqlCols.contains("posebni_oblik_prodaje")) {
            if (mal != null && sid != null && mal.compareTo(sid) < 0) {
                v.put("posebni_oblik_prodaje", "da");
                if (v.getOrDefault("naziv_posebnog_oblika_prodaje", "").isEmpty()) {
                    v.put("naziv_posebnog_oblika_prodaje", "AKCIJA");
                }
            } else {
                v.put("posebni_oblik_prodaje", "ne");
            }
        }

        // cijena za jedinicu mjere (bez popusta): veca od maloprodajne i cijene na dan 10.9.2026.
        if (cols.contains("cijena_po_jedinici_mjere") && !sqlCols.contains("cijena_po_jedinici_mjere") && mal != null) {
            BigDecimal reg = (sid != null && sid.compareTo(mal) > 0) ? sid : mal;
            v.put("cijena_po_jedinici_mjere", fmt(reg));
        }

        // najniza cijena u zadnjih 30 dana iz povijesti (kljuc = sifra + marka; kod usluga naziv)
        if (hist != null && mal != null) {
            String key = v.containsKey("sifra") ? v.get("sifra") : v.getOrDefault("naziv_usluge", "");
            if (!key.isEmpty() && v.containsKey("marka") && !v.get("marka").isEmpty()) {
                key = key + "|" + v.get("marka");
            }
            if (!key.isEmpty()) {
                if (hist.update(key, mal, today)) {
                    changed = true;
                }
                v.put("najniza_cijena_30_dana", fmt(hist.lowest30(key, mal, today)));
            }
        }
        return changed;
    }
}
