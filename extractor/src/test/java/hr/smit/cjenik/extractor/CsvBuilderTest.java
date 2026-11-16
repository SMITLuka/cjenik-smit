package hr.smit.cjenik.extractor;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.Statement;
import java.util.Arrays;
import java.util.List;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

/** Cijeli tok (SQL datoteka -> CSV) nad H2 bazom u memoriji; AS400 se ne dira. */
class CsvBuilderTest {
    @TempDir
    Path dir;
    Connection conn;

    @BeforeEach
    void setUp() throws Exception {
        conn = DriverManager.getConnection("jdbc:h2:mem:t" + System.nanoTime() + ";DB_CLOSE_DELAY=-1");
        try (Statement st = conn.createStatement()) {
            st.execute("create table art (naziv varchar(50), sifra varchar(20), marka varchar(5), mpc decimal(12,2), sid decimal(12,2), ean varchar(20))");
            st.execute("insert into art values ('Filter ulja', 'F1', 'RE ', 80.00, 100.00, '3850000000011')");
            st.execute("insert into art values ('=EVIL()', 'F2', 'DC', 20.00, 20.00, 'xx')");
            st.execute("insert into art values ('', 'F3', 'GL', 30.00, 25.00, '')");
            st.execute("insert into art values ('Vijak\u0007 M8; \"x\"', 'F4', 'XX', 1.5, 1.5, null)");
            st.execute("create table voz (naziv varchar(50), sifra varchar(20), marka varchar(5), mpc decimal(12,2), sid decimal(12,2))");
            st.execute("insert into voz values ('Clio 1.2', '161', 'RE', 9000.00, 9000.00)");
            st.execute("insert into voz values ('Sandero', '162', 'DC', 8000.00, 8500.00)");
        }
    }

    @AfterEach
    void tearDown() throws Exception {
        conn.close();
    }

    private Path hist() {
        return dir.resolve("state/history");
    }

    /** Upis SQL-a izvora: sql/&lt;izvor&gt;.sql */
    private Path sql(String source, String body) throws Exception {
        Files.createDirectories(dir.resolve("sql"));
        Files.writeString(dir.resolve("sql/" + source + ".sql"), body);
        return dir.resolve("sql");
    }

    private static final String SELECT = "-- komentar\nselect naziv, sifra, marka, 1 as neto_kolicina, 'KOM' as jedinica_mjere,\n"
        + "mpc as maloprodajna_cijena, sid as sidrena_cijena, ean as barkod, 'dostupno' as dostupnost\nfrom art order by sifra";

    private static final String SELECT_VOZILA = "select naziv, sifra, marka, 1 as neto_kolicina, 'KOM' as jedinica_mjere,\n"
        + "mpc as maloprodajna_cijena, sid as sidrena_cijena, 'dostupno' as dostupnost\nfrom voz order by sifra";

    private String build(CsvBuilder b, List<String> sources) throws Exception {
        return b.build(conn, "kb5", 1, "proizvodi", sources, hist(), "2026-11-17");
    }

    @Test
    void gradiCsvPremaSpecifikaciji() throws Exception {
        CsvBuilder b = new CsvBuilder(sql("dijelovi", SELECT));
        String csv = build(b, List.of("dijelovi"));
        String[] lines = csv.split("\n");
        assertEquals(String.join(";", Feeds.PROIZVOD), lines[0]);
        assertEquals(5, lines.length);
        for (int i = 1; i < lines.length; i++) {
            // red s navodnicima ima ; unutar polja, pa se broji samo za jednostavne retke
            if (!lines[i].contains("\"")) {
                assertEquals(14, lines[i].split(";", -1).length, lines[i]);
            }
        }
        List<String> f1 = Arrays.asList(lines[1].split(";", -1));
        assertEquals("Filter ulja", f1.get(0));
        assertEquals("Renault", f1.get(2));
        assertEquals("1", f1.get(3));
        assertEquals("KOM", f1.get(4));
        assertEquals("100.00", f1.get(5)); // cijena bez popusta
        assertEquals("80.00", f1.get(6));
        assertEquals("da", f1.get(7));
        assertEquals("AKCIJA", f1.get(8));
        assertEquals("100.00", f1.get(9)); // bazna = sidrena
        assertEquals("100.00", f1.get(10));
        assertEquals("3850000000011", f1.get(11));
        assertEquals("dostupno", f1.get(12));
        assertEquals("80.00", f1.get(13));
        assertTrue(Files.isRegularFile(hist().resolve("kb5_1_dijelovi.tsv")), "povijest je spremljena po izvoru");
    }

    @Test
    void ciscenjeNazivaIMarke() throws Exception {
        CsvBuilder b = new CsvBuilder(sql("dijelovi", SELECT));
        String csv = build(b, List.of("dijelovi"));
        String[] lines = csv.split("\n");
        assertTrue(lines[2].startsWith("EVIL();F2;Dacia;"), lines[2]);   // nema vodeceg =
        assertTrue(lines[3].startsWith("F3;F3;Geely;"), lines[3]);        // prazan naziv -> sifra; GL -> Geely
        assertTrue(lines[3].contains(";ne;;"), lines[3]);                 // 30 > 25: poskupljenje, nije akcija
    }

    @Test
    void nazivSNavodnicimaIKontrolnimZnakom() throws Exception {
        CsvBuilder b = new CsvBuilder(sql("dijelovi", SELECT));
        String f4 = build(b, List.of("dijelovi")).split("\n")[4];
        assertTrue(f4.startsWith("\"Vijak  M8; \"\"x\"\"\";F4;Ostale marke;"), f4);
    }

    @Test
    void dijeloviIVozilaSeSpajajuUJedanCsv() throws Exception {
        sql("dijelovi", SELECT);
        CsvBuilder b = new CsvBuilder(sql("vozila", SELECT_VOZILA));
        String csv = build(b, List.of("dijelovi", "vozila"));
        String[] lines = csv.split("\n");
        assertEquals(1 + 4 + 2, lines.length, "jedno zaglavlje, 4 dijela i 2 vozila");
        assertEquals(1, Arrays.stream(lines).filter(l -> l.startsWith("naziv;sifra;")).count(), "zaglavlje samo jednom");
        assertTrue(csv.contains("Filter ulja;F1;Renault;"));
        assertTrue(csv.contains("Clio 1.2;161;Renault;"));
        assertTrue(csv.contains("Sandero;162;Dacia;") && csv.contains(";da;AKCIJA;"), "akcija i za vozilo (8000 < 8500)");
        assertTrue(Files.isRegularFile(hist().resolve("kb5_1_dijelovi.tsv")) && Files.isRegularFile(hist().resolve("kb5_1_vozila.tsv")),
            "povijest cijena je zasebna po izvoru");
    }

    @Test
    void samoUkljuceniIzvoriSeIzvrsavaju() throws Exception {
        // za vozila nema SQL-a: da je izvor pokrenut, bilo bi greske; lokacija bez vozila ga ne trazi
        CsvBuilder b = new CsvBuilder(sql("dijelovi", SELECT));
        String csv = build(b, List.of("dijelovi"));
        assertEquals(5, csv.split("\n").length);
        Exception e = assertThrows(java.io.IOException.class, () -> build(b, List.of("dijelovi", "vozila")));
        assertTrue(e.getMessage().contains("vozila"), e.getMessage());
    }

    @Test
    void izvorBezRedakaJeUReduAkoDrugiImaRedaka() throws Exception {
        sql("dijelovi", SELECT);
        CsvBuilder b = new CsvBuilder(sql("vozila", SELECT_VOZILA.replace("from voz", "from voz where 1 = 0").replace("order by sifra", "")));
        String csv = build(b, List.of("dijelovi", "vozila"));
        assertEquals(5, csv.split("\n").length, "samo dijelovi");
    }

    @Test
    void nedostajucaObaveznaKolonaJeGreska() throws Exception {
        CsvBuilder b = new CsvBuilder(sql("dijelovi", "select naziv, sifra from art"));
        Exception e = assertThrows(IllegalStateException.class, () -> build(b, List.of("dijelovi")));
        assertTrue(e.getMessage().contains("ne vraca kolonu"), e.getMessage());
    }

    @Test
    void nulaRedakaUSvimIzvorimaJeGreska() throws Exception {
        sql("dijelovi", SELECT.replace("from art", "from art where 1 = 0").replace("order by sifra", ""));
        CsvBuilder b = new CsvBuilder(sql("vozila", SELECT_VOZILA.replace("from voz", "from voz where 1 = 0").replace("order by sifra", "")));
        Exception e = assertThrows(IllegalStateException.class, () -> build(b, List.of("dijelovi", "vozila")));
        assertTrue(e.getMessage().contains("nijedan redak"), e.getMessage());
    }

    @Test
    void zamjenaGrpIBetnrIParametri() throws Exception {
        try (Statement st = conn.createStatement()) {
            st.execute("create schema kb5d7l");
            st.execute("create table kb5d7l.art (naziv varchar(50), sifra varchar(20), marka varchar(5), mpc decimal(12,2), sid decimal(12,2), ean varchar(20))");
            st.execute("insert into kb5d7l.art values ('X', 'S', 'RE', 5, 5, null)");
        }
        String q = "select naziv, sifra, marka, mpc as maloprodajna_cijena, sid as sidrena_cijena, 'dostupno' as dostupnost\n"
            + "from {GRP}d{BETNR}l.art where sifra = ?";
        CsvBuilder b = new CsvBuilder(sql("dijelovi", q));
        // jedan parametar = GRP velikim slovima ('KB5' nije sifra 'S'), a zamjena {GRP}/{BETNR} mora dati kb5d7l.art
        IllegalStateException e = assertThrows(IllegalStateException.class,
            () -> b.build(conn, "kb5", 7, "proizvodi", List.of("dijelovi"), hist(), "2026-11-17"));
        assertTrue(e.getMessage().contains("nijedan redak"));
    }

    @Test
    void viseDatotekaJednogIzvoraSeSpaja() throws Exception {
        Files.createDirectories(dir.resolve("sql/dijelovi"));
        Files.writeString(dir.resolve("sql/dijelovi/01.sql"), SELECT.replace("order by sifra", "where sifra = 'F1'"));
        Files.writeString(dir.resolve("sql/dijelovi/02.sql"), SELECT.replace("order by sifra", "where sifra = 'F2'"));
        CsvBuilder b = new CsvBuilder(dir.resolve("sql"));
        assertEquals(3, build(b, List.of("dijelovi")).split("\n").length);
    }
}
