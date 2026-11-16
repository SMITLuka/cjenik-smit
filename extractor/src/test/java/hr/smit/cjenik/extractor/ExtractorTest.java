package hr.smit.cjenik.extractor;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import com.sun.net.httpserver.HttpServer;
import java.io.ByteArrayInputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.Statement;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.zip.GZIPInputStream;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

/** Orkestracija: lazni portal javlja cjenike i izvore po lokaciji, baza je H2. Provjerava sto se salje i kamo. */
class ExtractorTest {
    @TempDir
    Path dir;
    HttpServer server;
    String base;
    final List<String> puts = new CopyOnWriteArrayList<>();
    final Map<String, String> bodies = new java.util.concurrent.ConcurrentHashMap<>();
    String locationsJson;
    Connection conn;

    @BeforeEach
    void start() throws Exception {
        conn = DriverManager.getConnection("jdbc:h2:mem:e" + System.nanoTime() + ";DB_CLOSE_DELAY=-1");
        try (Statement st = conn.createStatement()) {
            st.execute("create table art (naziv varchar(50), sifra varchar(20), marka varchar(5), mpc decimal(12,2))");
            st.execute("insert into art values ('Filter', 'F1', 'RE', 10.00)");
            st.execute("create table voz (naziv varchar(50), sifra varchar(20), marka varchar(5), mpc decimal(12,2))");
            st.execute("insert into voz values ('Clio', '161', 'RE', 9000.00)");
        }
        Files.createDirectories(dir.resolve("sql"));
        String cols = "naziv, sifra, marka, 1 as neto_kolicina, 'KOM' as jedinica_mjere, mpc as maloprodajna_cijena, mpc as sidrena_cijena, 'dostupno' as dostupnost";
        Files.writeString(dir.resolve("sql/dijelovi.sql"), "select " + cols + " from art");
        Files.writeString(dir.resolve("sql/vozila.sql"), "select " + cols + " from voz");

        server = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        server.createContext("/", ex -> {
            String m = ex.getRequestMethod() + " " + ex.getRequestURI();
            byte[] raw = ex.getRequestBody().readAllBytes();
            byte[] out;
            if (ex.getRequestMethod().equals("GET")) {
                out = locationsJson.getBytes(StandardCharsets.UTF_8);
            } else {
                puts.add(m);
                bodies.put(m, new String(new GZIPInputStream(new ByteArrayInputStream(raw)).readAllBytes(), StandardCharsets.UTF_8));
                out = "{\"ok\":true}".getBytes(StandardCharsets.UTF_8);
            }
            ex.sendResponseHeaders(200, out.length);
            ex.getResponseBody().write(out);
            ex.close();
        });
        server.start();
        base = "http://127.0.0.1:" + server.getAddress().getPort();
    }

    @AfterEach
    void stop() throws Exception {
        server.stop(0);
        conn.close();
    }

    private Config cfg(Map<String, String> extra) {
        Map<String, String> env = new HashMap<>();
        env.put("PORTAL_URL", base);
        env.put("PORTAL_KEY_ID", "k_0123456789abcdef");
        env.put("PORTAL_SECRET", "s");
        env.put("AS400_HOST", "h");
        env.put("AS400_USER", "u");
        env.put("AS400_PASSWORD", "p");
        env.put("SQL_DIR", dir.resolve("sql").toString());
        env.put("STATE_DIR", dir.resolve("state").toString());
        env.put("OUT_DIR", dir.resolve("out").toString());
        env.put("RETRIES", "1");
        env.put("TZ", "Europe/Zagreb");
        env.putAll(extra);
        return Config.fromEnv(env);
    }

    @Test
    void saljeProizvodeSpojeneIzIzvoraKojeJavljaPortal() throws Exception {
        locationsJson = "{\"ok\":true,\"locations\":["
            + "{\"grp\":\"ka4\",\"betnr\":1,\"naziv\":\"A\",\"feeds\":[\"proizvodi\"],\"sources\":[\"dijelovi\",\"vozila\"]},"
            + "{\"grp\":\"ka4\",\"betnr\":2,\"naziv\":\"B\",\"feeds\":[\"proizvodi\"],\"sources\":[\"dijelovi\"]}]}";
        int failed = new Extractor(cfg(Map.of()), () -> conn).run();
        assertEquals(0, failed);
        assertEquals(List.of("PUT /api/v1/ka4/1/proizvodi", "PUT /api/v1/ka4/2/proizvodi"), puts.stream().sorted().toList());
        String both = bodies.get("PUT /api/v1/ka4/1/proizvodi");
        assertTrue(both.contains("Filter;F1;Renault;") && both.contains("Clio;161;Renault;"), "lokacija 1: dijelovi i vozila u jednoj datoteci");
        String onlyParts = bodies.get("PUT /api/v1/ka4/2/proizvodi");
        assertTrue(onlyParts.contains("Filter;F1;Renault;") && !onlyParts.contains("Clio"), "lokacija 2: samo dijelovi");
    }

    @Test
    void cjenikKojegPortalNeNudiSeNeSalje() throws Exception {
        locationsJson = "{\"ok\":true,\"locations\":[{\"grp\":\"ka4\",\"betnr\":3,\"naziv\":\"C\",\"feeds\":[],\"sources\":[]}]}";
        assertEquals(0, new Extractor(cfg(Map.of()), () -> conn).run());
        assertTrue(puts.isEmpty());
    }

    @Test
    void feedISourcesFilterSuzujuProlaz() throws Exception {
        locationsJson = "{\"ok\":true,\"locations\":[{\"grp\":\"ka4\",\"betnr\":1,\"naziv\":\"A\",\"feeds\":[\"proizvodi\"],\"sources\":[\"dijelovi\",\"vozila\"]}]}";
        assertEquals(0, new Extractor(cfg(Map.of("SOURCES", "vozila", "FEED", "proizvodi")), () -> conn).run());
        String body = bodies.get("PUT /api/v1/ka4/1/proizvodi");
        assertTrue(body.contains("Clio") && !body.contains("Filter"), body);
    }

    @Test
    void dryRunBezPortalaSNaziviLokacije() throws Exception {
        Map<String, String> env = new HashMap<>();
        env.put("DRY_RUN", "true");
        env.put("ONLY", "ka4/1");
        env.put("SOURCES", "dijelovi,vozila");
        env.put("AS400_HOST", "h");
        env.put("AS400_USER", "u");
        env.put("AS400_PASSWORD", "p");
        env.put("SQL_DIR", dir.resolve("sql").toString());
        env.put("STATE_DIR", dir.resolve("state").toString());
        env.put("OUT_DIR", dir.resolve("out").toString());
        env.put("FEED", "proizvodi");
        Config c = Config.fromEnv(env);
        // radovi nema SQL-a, ali FEED=proizvodi ga ne dira
        int failed = new Extractor(c, () -> conn).run();
        assertEquals(0, failed);
        try (var s = Files.walk(dir.resolve("out"))) {
            List<Path> files = s.filter(Files::isRegularFile).toList();
            assertEquals(1, files.size());
            assertEquals("ka4_1_proizvodi.csv", files.get(0).getFileName().toString());
            String csv = Files.readString(files.get(0));
            assertTrue(csv.contains("Filter") && csv.contains("Clio"));
        }
        assertTrue(puts.isEmpty(), "DRY_RUN nista ne salje");
    }

    @Test
    void radoviBezSqlaJavljajuGreskuAliOstatakProlazi() throws Exception {
        locationsJson = "{\"ok\":true,\"locations\":[{\"grp\":\"ka4\",\"betnr\":1,\"naziv\":\"A\",\"feeds\":[\"proizvodi\",\"radovi\"],\"sources\":[\"dijelovi\",\"radovi\"]}]}";
        int failed = new Extractor(cfg(Map.of()), () -> conn).run();
        assertEquals(1, failed, "radovi nemaju SQL");
        assertEquals(List.of("PUT /api/v1/ka4/1/proizvodi"), puts, "proizvodi su ipak poslani");
    }
}
