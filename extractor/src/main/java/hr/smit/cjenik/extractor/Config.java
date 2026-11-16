package hr.smit.cjenik.extractor;

import java.nio.file.Path;
import java.time.LocalTime;
import java.time.ZoneId;
import java.util.Arrays;
import java.util.List;
import java.util.Map;

/** Konfiguracija iskljucivo iz varijabli okruzenja (u slici i repou nema tajni). */
public final class Config {
    public final String portalUrl;
    public final String keyId;
    public final String secret;
    public final String as400Host;
    public final String as400User;
    public final String as400Password;
    public final String as400Params;
    public final Path sqlDir;
    public final Path stateDir;
    public final Path outDir;
    public final Path fromDir;
    public final LocalTime runAt;
    public final LocalTime deadline;
    public final ZoneId zone;
    public final int retries;
    public final String only;
    public final String feed;
    public final List<String> sources;
    public final boolean dryRun;
    public final boolean runOnce;
    public final boolean runOnStart;

    private Config(Map<String, String> e) {
        String fromDirS = opt(e, "FROM_DIR", "");
        this.fromDir = fromDirS.isEmpty() ? null : Path.of(fromDirS);
        this.dryRun = bool(e, "DRY_RUN", false);
        this.runOnce = bool(e, "RUN_ONCE", false);
        this.runOnStart = bool(e, "RUN_ON_START", false);

        // Portal je obavezan samo kad se stvarno salje (DRY_RUN radi i bez njega uz ONLY ili FROM_DIR).
        boolean needPortal = !dryRun;
        this.portalUrl = needPortal || e.containsKey("PORTAL_URL") ? req(e, "PORTAL_URL") : "";
        this.keyId = needPortal ? req(e, "PORTAL_KEY_ID") : opt(e, "PORTAL_KEY_ID", "");
        this.secret = needPortal ? req(e, "PORTAL_SECRET") : opt(e, "PORTAL_SECRET", "");

        boolean needAs400 = fromDir == null;
        this.as400Host = needAs400 ? req(e, "AS400_HOST") : opt(e, "AS400_HOST", "");
        this.as400User = needAs400 ? req(e, "AS400_USER") : opt(e, "AS400_USER", "");
        this.as400Password = needAs400 ? req(e, "AS400_PASSWORD") : opt(e, "AS400_PASSWORD", "");
        this.as400Params = opt(e, "AS400_JDBC_PARAMS", "extended metadata=false;keep alive=true;libraries=QGPL;prompt=false;errors=full;date format=iso");

        this.sqlDir = Path.of(opt(e, "SQL_DIR", "/config/sql"));
        this.stateDir = Path.of(opt(e, "STATE_DIR", "/data/state"));
        this.outDir = Path.of(opt(e, "OUT_DIR", "/data/out"));
        this.runAt = LocalTime.parse(opt(e, "RUN_AT", "07:00"));
        this.deadline = LocalTime.parse(opt(e, "DEADLINE", "07:45"));
        this.zone = ZoneId.of(opt(e, "TZ", "Europe/Zagreb"));
        this.retries = Integer.parseInt(opt(e, "RETRIES", "4"));
        this.only = opt(e, "ONLY", "").toLowerCase();
        this.feed = opt(e, "FEED", "");
        if (!feed.isEmpty() && !Feeds.isFeed(feed)) {
            throw new IllegalArgumentException("FEED mora biti proizvodi ili radovi.");
        }
        // neobavezno: ogranici izvore (npr. SOURCES=dijelovi) kad portal ne daje popis (ONLY/FROM_DIR proba)
        String src = opt(e, "SOURCES", "");
        this.sources = src.isEmpty() ? List.of()
            : Arrays.stream(src.toLowerCase().split(",")).map(String::trim).filter(s -> !s.isEmpty()).toList();
        for (String s : sources) {
            if (!Feeds.isSource(s)) {
                throw new IllegalArgumentException("SOURCES: nepoznat izvor '" + s + "' (dijelovi, vozila, radovi).");
            }
        }
    }

    public static Config fromEnv(Map<String, String> env) {
        return new Config(env);
    }

    private static String req(Map<String, String> e, String k) {
        String v = e.get(k);
        if (v == null || v.isBlank()) {
            throw new IllegalArgumentException("Nedostaje varijabla okruzenja " + k + ".");
        }
        return v;
    }

    private static String opt(Map<String, String> e, String k, String d) {
        String v = e.get(k);
        return (v == null || v.isBlank()) ? d : v.trim();
    }

    private static boolean bool(Map<String, String> e, String k, boolean d) {
        String v = e.get(k);
        if (v == null || v.isBlank()) {
            return d;
        }
        return switch (v.trim().toLowerCase()) {
            case "1", "true", "yes", "on" -> true;
            default -> false;
        };
    }
}
