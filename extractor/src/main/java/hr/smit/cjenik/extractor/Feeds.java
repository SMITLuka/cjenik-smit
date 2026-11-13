package hr.smit.cjenik.extractor;

import java.util.List;
import java.util.Map;
import java.util.Set;

/** Stupci cjenika: moraju tocno odgovarati app/src/CsvSpec.php (portal odbija drukcije zaglavlje). */
public final class Feeds {
    public static final List<String> PROIZVOD = List.of(
        "naziv", "sifra", "marka", "neto_kolicina", "jedinica_mjere", "cijena_po_jedinici_mjere",
        "maloprodajna_cijena", "posebni_oblik_prodaje", "naziv_posebnog_oblika_prodaje", "bazna_cijena",
        "sidrena_cijena", "barkod", "dostupnost", "najniza_cijena_30_dana");

    public static final List<String> USLUGA = List.of(
        "naziv_usluge", "maloprodajna_cijena", "posebni_oblik_prodaje", "naziv_posebnog_oblika_prodaje",
        "bazna_cijena", "sidrena_cijena", "najniza_cijena_30_dana");

    private static final Map<String, List<String>> BY_FEED = Map.of(
        "dijelovi", PROIZVOD, "vozila", PROIZVOD, "radovi", USLUGA);

    /** Ako SQL ne vraca stupac, uzima se drugi: bazna i sidrena su na dan 10.9.2026. iste, najnizu zamjenjuje maloprodajna. */
    public static final Map<String, String> FALLBACK = Map.of(
        "bazna_cijena", "sidrena_cijena",
        "sidrena_cijena", "bazna_cijena",
        "najniza_cijena_30_dana", "maloprodajna_cijena");

    /** Neobavezni stupci: ako ih SQL ne vraca, u CSV idu prazni. */
    public static final Set<String> OPTIONAL = Set.of(
        "marka", "neto_kolicina", "jedinica_mjere", "naziv_posebnog_oblika_prodaje", "barkod");

    /** Stupci koje servis sam izvodi ako ih SQL ne vraca (vidi RowRules). */
    public static final Set<String> DERIVED = Set.of(
        "cijena_po_jedinici_mjere", "posebni_oblik_prodaje", "najniza_cijena_30_dana");

    /** Tekstualni stupci: ciste se od znakova koje portal odbija. */
    public static final Set<String> TEXT = Set.of(
        "naziv", "naziv_usluge", "sifra", "marka", "jedinica_mjere", "naziv_posebnog_oblika_prodaje");

    private Feeds() {
    }

    public static boolean isFeed(String feed) {
        return BY_FEED.containsKey(feed);
    }

    public static List<String> columns(String feed) {
        List<String> c = BY_FEED.get(feed);
        if (c == null) {
            throw new IllegalArgumentException("Nepoznat cjenik: " + feed);
        }
        return c;
    }
}
