package hr.smit.cjenik.extractor;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.math.BigDecimal;
import java.nio.file.Path;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class RowRulesTest {
    private static final List<String> COLS = List.of("sifra", "marka", "maloprodajna_cijena", "sidrena_cijena",
        "posebni_oblik_prodaje", "naziv_posebnog_oblika_prodaje", "cijena_po_jedinici_mjere", "barkod",
        "najniza_cijena_30_dana");
    private static final Set<String> NONE = Set.of("sifra", "maloprodajna_cijena", "sidrena_cijena");

    private static Map<String, String> row(String mal, String sid, String sifra, String marka, String barkod) {
        Map<String, String> v = new LinkedHashMap<>();
        v.put("sifra", sifra);
        v.put("marka", marka);
        v.put("maloprodajna_cijena", mal);
        v.put("sidrena_cijena", sid);
        v.put("posebni_oblik_prodaje", "");
        v.put("naziv_posebnog_oblika_prodaje", "");
        v.put("cijena_po_jedinici_mjere", "");
        v.put("barkod", barkod);
        v.put("najniza_cijena_30_dana", mal);
        return v;
    }

    private static Map<String, String> row(String mal, String sid) {
        return row(mal, sid, "A1", "Renault", "");
    }

    @Test
    void cijenaIspodSidreneJeAkcijaICijenaBezPopusta() {
        Map<String, String> v = row("80.00", "100.00");
        RowRules.complete(v, NONE, COLS, null, "2026-11-17");
        assertEquals("da", v.get("posebni_oblik_prodaje"));
        assertEquals("AKCIJA", v.get("naziv_posebnog_oblika_prodaje"));
        assertEquals("100.00", v.get("cijena_po_jedinici_mjere"));
    }

    @Test
    void poskupljenjeIJednakeCijeneNisuAkcija() {
        Map<String, String> v = row("120.00", "100.00");
        RowRules.complete(v, NONE, COLS, null, "2026-11-17");
        assertEquals("ne", v.get("posebni_oblik_prodaje"));
        assertEquals("", v.get("naziv_posebnog_oblika_prodaje"));
        assertEquals("120.00", v.get("cijena_po_jedinici_mjere"));
        Map<String, String> e = row("50.00", "50.00");
        RowRules.complete(e, NONE, COLS, null, "2026-11-17");
        assertEquals("ne", e.get("posebni_oblik_prodaje"));
    }

    @Test
    void vrijednostiIzSqlaSeNeDiraju() {
        Map<String, String> v = row("80.00", "100.00");
        v.put("posebni_oblik_prodaje", "ne");
        v.put("cijena_po_jedinici_mjere", "77.00");
        Set<String> cols = new HashSet<>(NONE);
        cols.add("posebni_oblik_prodaje");
        cols.add("cijena_po_jedinici_mjere");
        RowRules.complete(v, cols, COLS, null, "2026-11-17");
        assertEquals("ne", v.get("posebni_oblik_prodaje"));
        assertEquals("77.00", v.get("cijena_po_jedinici_mjere"));
    }

    @Test
    void barkodSamoOsamDoCetrnaestZnamenki() {
        Map<String, String> a = row("1.00", "1.00", "A1", "RE", "3850000000011");
        RowRules.complete(a, NONE, COLS, null, "2026-11-17");
        assertEquals("3850000000011", a.get("barkod"));
        Map<String, String> b = row("1.00", "1.00", "A1", "RE", "ABC-12");
        RowRules.complete(b, NONE, COLS, null, "2026-11-17");
        assertEquals("", b.get("barkod"));
        Map<String, String> c = row("1.00", "1.00", "A1", "RE", "123");
        RowRules.complete(c, NONE, COLS, null, "2026-11-17");
        assertEquals("", c.get("barkod"));
    }

    @Test
    void markeSifraUNaziv() {
        assertEquals("Renault", marka("RE"));
        assertEquals("Renault", marka("re"));
        assertEquals("Mazda", marka("MA"));
        assertEquals("Land Rover", marka("LR"));
        assertEquals("Mercedes-Benz", marka("MB"));
        assertEquals("Mercedes-Benz", marka("ME"));
        assertEquals("Škoda", marka("SK"));
        assertEquals("Citroën", marka("CI"));
        assertEquals("Gašperov", marka("GA"));
        assertEquals("Farizon", marka("FA"));
        assertEquals("General Motors", marka("GM"));
        assertEquals("Geely", marka("GL"));
        assertEquals("Mitsubishi", marka("MT"));
        assertEquals("Mini", marka("MI"));
        assertEquals("Isuzu", marka("IS"));
        assertEquals("Ostale marke", marka("XX"));
        assertEquals("Ostale marke", marka("ZZ"));
        assertEquals("Ostale marke", marka(""));
        assertEquals("Ostale marke", marka("*"));
        assertEquals("Ostale marke", marka("LM"));
        // naziv koji je SQL vec vratio ostaje
        assertEquals("Renault", marka("Renault"));
        assertEquals("Ostale marke", marka("Ostale marke"));
    }

    private static String marka(String code) {
        Map<String, String> v = row("1.00", "1.00", "K1", code, "");
        RowRules.complete(v, NONE, COLS, null, "2026-11-17");
        return v.get("marka");
    }

    @Test
    void najnizaCijenaIzPovijesti(@TempDir Path dir) throws Exception {
        Path f = dir.resolve("h.tsv");
        PriceHistory h = PriceHistory.load(f);
        assertEquals("100.00", run(h, "100.00", "2026-11-17"));
        assertEquals("80.00", run(h, "80.00", "2026-11-20"));
        assertEquals("80.00", run(h, "100.00", "2026-12-01"));
        assertEquals("80.00", run(h, "100.00", "2026-12-20"));
        assertEquals("100.00", run(h, "100.00", "2027-01-05"));
        assertEquals(3, countEntries(h));

        h.save(f, "2027-01-05");
        PriceHistory h2 = PriceHistory.load(f);
        assertEquals("90.00", run(h2, "90.00", "2027-01-06"));
        assertEquals(new BigDecimal("90.00"), h2.lowest30("A1|Renault", new BigDecimal("90.00"), "2027-01-06"));
    }

    private static int countEntries(PriceHistory h) throws Exception {
        Path tmp = Path.of(System.getProperty("java.io.tmpdir"), "ph_count_" + System.nanoTime() + ".tsv");
        h.save(tmp, "2026-11-17");
        int n = java.nio.file.Files.readAllLines(tmp).size();
        java.nio.file.Files.delete(tmp);
        return n;
    }

    private static String run(PriceHistory h, String price, String day) {
        Map<String, String> v = row(price, "100.00");
        RowRules.complete(v, NONE, COLS, h, day);
        return v.get("najniza_cijena_30_dana");
    }

    @Test
    void istaSifraZaDvijeMarkeNeDijeliPovijest() {
        PriceHistory h = null;
        try {
            h = PriceHistory.load(Path.of("nepostojeca-datoteka.tsv"));
        } catch (Exception e) {
            throw new AssertionError(e);
        }
        Map<String, String> a = row("3.74", "3.74", "S1", "NI", "");
        Map<String, String> b = row("5.26", "5.26", "S1", "RE", "");
        RowRules.complete(a, NONE, COLS, h, "2027-03-01");
        RowRules.complete(b, NONE, COLS, h, "2027-03-01");
        assertEquals("3.74", a.get("najniza_cijena_30_dana"));
        assertEquals("5.26", b.get("najniza_cijena_30_dana"));
    }

    @Test
    void povijestSeBiljeziSamoPriPromjeni() {
        PriceHistory h = new PriceHistory();
        Map<String, String> a = row("10.00", "10.00", "Z9", "RE", "");
        assertTrue(RowRules.complete(a, NONE, COLS, h, "2027-02-01"));
        Map<String, String> b = row("10.00", "10.00", "Z9", "RE", "");
        assertFalse(RowRules.complete(b, NONE, COLS, h, "2027-02-01"));
        Map<String, String> c = row("12.00", "12.00", "Z9", "RE", "");
        assertTrue(RowRules.complete(c, NONE, COLS, h, "2027-02-02"));
    }

    @Test
    void najnizaIzSqlaOstaje() {
        Map<String, String> v = row("70.00", "100.00");
        v.put("najniza_cijena_30_dana", "65.00");
        RowRules.complete(v, NONE, COLS, null, "2026-11-17");
        assertEquals("65.00", v.get("najniza_cijena_30_dana"));
    }

    @Test
    void formatIznosa() {
        assertEquals("100.00", RowRules.fmt(new BigDecimal("100")));
        assertEquals("19.78", RowRules.fmt(new BigDecimal("19.7800")));
        assertEquals("1.50", RowRules.fmt(new BigDecimal("1.5")));
        assertEquals("0.12", RowRules.fmt(new BigDecimal("0.12")));
        assertEquals("1.2346", RowRules.fmt(new BigDecimal("1.23456")));
    }
}
