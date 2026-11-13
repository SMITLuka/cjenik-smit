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
        }
    }

    @AfterEach
    void tearDown() throws Exception {
        conn.close();
    }

    private Path sql(String body) throws Exception {
        Files.createDirectories(dir.resolve("sql"));
        Files.writeString(dir.resolve("sql/dijelovi.sql"), body);
        return dir.resolve("sql");
    }

    private static final String SELECT = "-- komentar\nselect naziv, sifra, marka, 1 as neto_kolicina, 'KOM' as jedinica_mjere,\n"
        + "mpc as maloprodajna_cijena, sid as sidrena_cijena, ean as barkod, 'dostupno' as dostupnost\nfrom art order by sifra";

    @Test
    void gradiCsvPremaSpecifikaciji() throws Exception {
        CsvBuilder b = new CsvBuilder(sql(SELECT));
        Path hf = dir.resolve("state/h.tsv");
        String csv = b.build(conn, "kb5", 1, "dijelovi", PriceHistory.load(hf), hf, "2026-11-17");
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
        assertTrue(Files.isRegularFile(hf), "povijest je spremljena");
    }

    @Test
    void ciscenjeNazivaIMarke() throws Exception {
        CsvBuilder b = new CsvBuilder(sql(SELECT));
        Path hf = dir.resolve("state/h.tsv");
        String csv = b.build(conn, "kb5", 1, "dijelovi", PriceHistory.load(hf), hf, "2026-11-17");
        String[] lines = csv.split("\n");
        assertTrue(lines[2].startsWith("EVIL();F2;Dacia;"), lines[2]);   // nema vodeceg =
        assertTrue(lines[2].contains(";;;") || lines[2].contains(";ne;"), lines[2]);
        assertTrue(lines[3].startsWith("F3;F3;Geely;"), lines[3]);        // prazan naziv -> sifra; GL -> Geely
        assertTrue(lines[3].contains(";ne;;"), lines[3]);                 // 30 > 25: poskupljenje, nije akcija
    }

    @Test
    void nazivSNavodnicimaIKontrolnimZnakom() throws Exception {
        CsvBuilder b = new CsvBuilder(sql(SELECT));
        Path hf = dir.resolve("state/h.tsv");
        String csv = b.build(conn, "kb5", 1, "dijelovi", PriceHistory.load(hf), hf, "2026-11-17");
        String f4 = csv.split("\n")[4];
        assertTrue(f4.startsWith("\"Vijak  M8; \"\"x\"\"\";F4;Ostale marke;"), f4);
    }

    @Test
    void nedostajucaObaveznaKolonaJeGreska() throws Exception {
        CsvBuilder b = new CsvBuilder(sql("select naziv, sifra from art"));
        Path hf = dir.resolve("state/h.tsv");
        Exception e = assertThrows(IllegalStateException.class,
            () -> b.build(conn, "kb5", 1, "dijelovi", PriceHistory.load(hf), hf, "2026-11-17"));
        assertTrue(e.getMessage().contains("ne vraca kolonu"), e.getMessage());
    }

    @Test
    void nulaRedakaJeGreska() throws Exception {
        CsvBuilder b = new CsvBuilder(sql(SELECT.replace("from art", "from art where 1 = 0").replace("order by sifra", "")));
        Path hf = dir.resolve("state/h.tsv");
        Exception e = assertThrows(IllegalStateException.class,
            () -> b.build(conn, "kb5", 1, "dijelovi", PriceHistory.load(hf), hf, "2026-11-17"));
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
        CsvBuilder b = new CsvBuilder(sql(q));
        // jedan parametar = GRP velikim slovima ('KB5' nije sifra 'S'), a zamjena {GRP}/{BETNR} mora dati kb5d7l.art
        Path hf = dir.resolve("state/h.tsv");
        IllegalStateException e = assertThrows(IllegalStateException.class,
            () -> b.build(conn, "kb5", 7, "dijelovi", PriceHistory.load(hf), hf, "2026-11-17"));
        assertTrue(e.getMessage().contains("nijedan redak"));
    }

    @Test
    void viseDatotekaSeSpajaUJedanCsv() throws Exception {
        Files.createDirectories(dir.resolve("sql/dijelovi"));
        Files.writeString(dir.resolve("sql/dijelovi/01.sql"), SELECT.replace("order by sifra", "where sifra = 'F1'"));
        Files.writeString(dir.resolve("sql/dijelovi/02.sql"), SELECT.replace("order by sifra", "where sifra = 'F2'"));
        CsvBuilder b = new CsvBuilder(dir.resolve("sql"));
        Path hf = dir.resolve("state/h.tsv");
        String csv = b.build(conn, "kb5", 1, "dijelovi", PriceHistory.load(hf), hf, "2026-11-17");
        assertEquals(3, csv.split("\n").length);
    }
}
