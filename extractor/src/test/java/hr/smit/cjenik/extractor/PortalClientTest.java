package hr.smit.cjenik.extractor;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import com.sun.net.httpserver.HttpServer;
import java.io.ByteArrayInputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.CopyOnWriteArrayList;
import java.util.concurrent.atomic.AtomicInteger;
import java.util.zip.GZIPInputStream;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

class PortalClientTest {
    private HttpServer server;
    private String base;
    private final List<Map<String, String>> seen = new CopyOnWriteArrayList<>();
    private final List<String> bodies = new CopyOnWriteArrayList<>();
    private final AtomicInteger failFirst = new AtomicInteger(0);

    @BeforeEach
    void start() throws Exception {
        server = HttpServer.create(new InetSocketAddress("127.0.0.1", 0), 0);
        server.createContext("/", ex -> {
            Map<String, String> h = new ConcurrentHashMap<>();
            ex.getRequestHeaders().forEach((k, v) -> h.put(k.toLowerCase(), v.get(0)));
            h.put("_method", ex.getRequestMethod());
            h.put("_uri", ex.getRequestURI().toString());
            byte[] raw = ex.getRequestBody().readAllBytes();
            h.put("_sha", PortalClient.sha256Hex(raw));
            seen.add(h);
            if ("gzip".equals(h.get("content-encoding"))) {
                bodies.add(new String(new GZIPInputStream(new ByteArrayInputStream(raw)).readAllBytes(), StandardCharsets.UTF_8));
            }
            int status = failFirst.getAndUpdate(n -> n > 0 ? n - 1 : 0) > 0 ? 503 : 200;
            byte[] out = ("{\"ok\":" + (status == 200) + ",\"locations\":[{\"grp\":\"ka4\",\"betnr\":1,\"feeds\":[\"dijelovi\",\"vozila\"]},"
                + "{\"grp\":\"kb5\",\"betnr\":21,\"feeds\":[\"dijelovi\"]}]}").getBytes(StandardCharsets.UTF_8);
            ex.sendResponseHeaders(status, out.length);
            ex.getResponseBody().write(out);
            ex.close();
        });
        server.start();
        base = "http://127.0.0.1:" + server.getAddress().getPort();
    }

    @AfterEach
    void stop() {
        server.stop(0);
    }

    @Test
    void potpisJeIdentican_PhpImplementaciji() {
        // vrijednost izracunata PHP-om: Cjenik\Hmac::sign(...) za iste ulaze
        String sha = PortalClient.sha256Hex("tijelo".getBytes(StandardCharsets.UTF_8));
        assertEquals("cac9ecf3d110f8222c4973406c1e0a48edd15e1fbb7e2881497c67076844e561", sha);
        assertEquals("d4e95d380267f08f33d782ae1241f48c08943eee0bcc15ae66fbdf9ca6d69ae2",
            PortalClient.sign("tajna-za-test-123", "PUT", "/api/v1/ka4/1/dijelovi", "1794600000", "0123456789abcdef01234567", sha));
    }

    @Test
    void putSaljeGzipIPotpisaneZaglavlja() {
        PortalClient c = new PortalClient(base + "/", "k_0123456789abcdef", "tajna-za-test-123");
        PortalClient.Result r = c.put("KA4", 1, "dijelovi", "naziv;sifra\nA;1\n");
        assertEquals(200, r.status());
        Map<String, String> h = seen.get(0);
        assertEquals("PUT", h.get("_method"));
        assertEquals("/api/v1/ka4/1/dijelovi", h.get("_uri"));
        assertEquals("k_0123456789abcdef", h.get("x-key-id"));
        assertEquals("gzip", h.get("content-encoding"));
        assertEquals(h.get("_sha"), h.get("x-content-sha256"));       // SHA-256 tijela kako je poslano (gzip)
        assertEquals(PortalClient.sign("tajna-za-test-123", "PUT", "/api/v1/ka4/1/dijelovi",
            h.get("x-timestamp"), h.get("x-nonce"), h.get("x-content-sha256")), h.get("x-signature"));
        assertTrue(h.get("x-nonce").matches("[0-9a-f]{24}"));
        assertTrue(h.get("x-timestamp").matches("\\d{10}"));
        assertEquals("naziv;sifra\nA;1\n", bodies.get(0));
    }

    @Test
    void lokacijeSePotpisujuBezTijela() throws Exception {
        PortalClient c = new PortalClient(base, "k_0123456789abcdef", "tajna-za-test-123");
        List<PortalClient.Location> l = c.locations();
        assertEquals(2, l.size());
        assertEquals("ka4", l.get(0).grp());
        assertEquals(List.of("dijelovi", "vozila"), l.get(0).feeds());
        assertEquals(21, l.get(1).betnr());
        Map<String, String> h = seen.get(0);
        assertEquals("GET", h.get("_method"));
        assertEquals("e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855", h.get("x-content-sha256"));
    }

    @Test
    void mreznaGreskaJeStatusNula() {
        PortalClient c = new PortalClient("http://127.0.0.1:1", "k_0123456789abcdef", "x");
        assertEquals(0, c.put("ka4", 1, "dijelovi", "a\n").status());
    }

    @Test
    void serverskaGreskaVracaStatusIBaremNoviNoncePoZahtjevu() throws Exception {
        failFirst.set(1);
        Config cfg = Config.fromEnv(Map.of("PORTAL_URL", base, "PORTAL_KEY_ID", "k_0123456789abcdef", "PORTAL_SECRET", "s",
            "FROM_DIR", System.getProperty("java.io.tmpdir"), "RETRIES", "3", "DEADLINE", "23:59", "TZ", "Europe/Zagreb"));
        PortalClient.Result first = new PortalClient(cfg.portalUrl, cfg.keyId, cfg.secret).put("ka4", 1, "dijelovi", "a\n");
        assertEquals(503, first.status());
        PortalClient.Result second = new PortalClient(cfg.portalUrl, cfg.keyId, cfg.secret).put("ka4", 1, "dijelovi", "a\n");
        assertEquals(200, second.status());
        assertEquals(2, seen.size());
        assertTrue(!seen.get(0).get("x-nonce").equals(seen.get(1).get("x-nonce")), "svaki zahtjev ima novi nonce");
    }
}
