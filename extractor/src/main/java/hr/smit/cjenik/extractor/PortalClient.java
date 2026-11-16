package hr.smit.cjenik.extractor;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.security.SecureRandom;
import java.time.Duration;
import java.util.ArrayList;
import java.util.HexFormat;
import java.util.List;
import java.util.zip.GZIPOutputStream;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;

/**
 * Potpisani HTTPS zahtjevi prema portalu (protokol: docs/CSV-spec.md). Kanonski niz:
 * METHOD \n REQUEST_URI \n TIMESTAMP \n NONCE \n SHA256_HEX(tijelo kako je poslano); potpis = hex(HMAC-SHA256(tajna, niz)).
 */
public final class PortalClient {
    public record Result(int status, String body) {
    }

    public record Location(String grp, int betnr, List<String> feeds, List<String> sources) {
    }

    private static final SecureRandom RNG = new SecureRandom();
    private static final ObjectMapper JSON = new ObjectMapper();

    private final String baseUrl;
    private final String keyId;
    private final String secret;
    private final HttpClient http = HttpClient.newBuilder().connectTimeout(Duration.ofSeconds(30)).build();

    public PortalClient(String baseUrl, String keyId, String secret) {
        this.baseUrl = baseUrl.replaceAll("/+$", "");
        this.keyId = keyId;
        this.secret = secret;
    }

    /** Potpis za dani zahtjev (javno radi testova usporedbe s PHP implementacijom). */
    public static String sign(String secret, String method, String uri, String ts, String nonce, String bodySha256Hex) {
        try {
            Mac mac = Mac.getInstance("HmacSHA256");
            mac.init(new SecretKeySpec(secret.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
            String canonical = method.toUpperCase() + "\n" + uri + "\n" + ts + "\n" + nonce + "\n" + bodySha256Hex;
            return HexFormat.of().formatHex(mac.doFinal(canonical.getBytes(StandardCharsets.UTF_8)));
        } catch (Exception e) {
            throw new IllegalStateException(e);
        }
    }

    public static String sha256Hex(byte[] bytes) {
        try {
            return HexFormat.of().formatHex(MessageDigest.getInstance("SHA-256").digest(bytes));
        } catch (NoSuchAlgorithmException e) {
            throw new IllegalStateException(e);
        }
    }

    static byte[] gzip(byte[] raw) throws IOException {
        ByteArrayOutputStream bos = new ByteArrayOutputStream();
        try (GZIPOutputStream gz = new GZIPOutputStream(bos)) {
            gz.write(raw);
        }
        return bos.toByteArray();
    }

    private static String nonce() {
        byte[] b = new byte[12];
        RNG.nextBytes(b);
        return HexFormat.of().formatHex(b);
    }

    /** Ne baca iznimku za HTTP greske; mrezna greska = status 0. */
    public Result request(String method, String pathAndQuery, byte[] csv) {
        try {
            byte[] payload = csv == null ? new byte[0] : gzip(csv);
            String sha = sha256Hex(payload);
            String ts = Long.toString(System.currentTimeMillis() / 1000);
            String nonce = nonce();
            HttpRequest.Builder rb = HttpRequest.newBuilder(URI.create(baseUrl + pathAndQuery))
                .timeout(Duration.ofSeconds(120))
                .header("User-Agent", "cjenik-extractor/1.0")
                .header("X-Key-Id", keyId)
                .header("X-Timestamp", ts)
                .header("X-Nonce", nonce)
                .header("X-Content-SHA256", sha)
                .header("X-Signature", sign(secret, method, pathAndQuery, ts, nonce, sha));
            if (payload.length > 0) {
                rb.header("Content-Type", "text/csv; charset=utf-8").header("Content-Encoding", "gzip");
                rb.method(method.toUpperCase(), HttpRequest.BodyPublishers.ofByteArray(payload));
            } else {
                rb.method(method.toUpperCase(), HttpRequest.BodyPublishers.noBody());
            }
            HttpResponse<String> r = http.send(rb.build(), HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
            return new Result(r.statusCode(), r.body());
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return new Result(0, "prekinuto");
        } catch (Exception e) {
            return new Result(0, e.getClass().getSimpleName() + ": " + e.getMessage());
        }
    }

    /** Aktivne lokacije i cjenici koje ovaj kljuc smije slati (GET /api/v1/locations). */
    public List<Location> locations() throws IOException {
        Result r = request("GET", "/api/v1/locations", null);
        if (r.status() != 200) {
            throw new IOException("Ne mogu dohvatiti lokacije: HTTP " + r.status() + " " + r.body());
        }
        List<Location> out = new ArrayList<>();
        JsonNode root = JSON.readTree(r.body());
        for (JsonNode l : root.path("locations")) {
            List<String> feeds = new ArrayList<>();
            l.path("feeds").forEach(f -> feeds.add(f.asText()));
            List<String> sources = new ArrayList<>();
            l.path("sources").forEach(s -> sources.add(s.asText()));
            out.add(new Location(l.path("grp").asText(), l.path("betnr").asInt(), feeds, sources));
        }
        return out;
    }

    public Result put(String grp, int betnr, String feed, String csv) {
        return request("PUT", "/api/v1/" + grp.toLowerCase() + "/" + betnr + "/" + feed, csv.getBytes(StandardCharsets.UTF_8));
    }
}
