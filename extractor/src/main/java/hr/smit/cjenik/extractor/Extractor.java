package hr.smit.cjenik.extractor;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.sql.SQLException;
import java.time.LocalDate;
import java.time.ZonedDateTime;
import java.util.ArrayList;
import java.util.List;

/** Jedan prolaz: za svaku lokaciju i cjenik izvuce podatke, napravi CSV i posalje ga portalu (ili spremi uz DRY_RUN). */
public final class Extractor {
    private record Target(String grp, int betnr, String feed, List<String> sources) {
        String label() {
            return grp + "/" + betnr + "/" + feed;
        }
    }

    /** Otvara vezu prema bazi (u testovima zamjenjiva). */
    @FunctionalInterface
    interface ConnectionFactory {
        Connection open() throws SQLException;
    }

    private final Config cfg;
    private final PortalClient portal;
    private final CsvBuilder builder;
    private final ConnectionFactory connect;

    public Extractor(Config cfg) {
        this(cfg, () -> As400.connect(cfg));
    }

    Extractor(Config cfg, ConnectionFactory connect) {
        this.cfg = cfg;
        this.connect = connect;
        this.portal = cfg.portalUrl.isEmpty() ? null : new PortalClient(cfg.portalUrl, cfg.keyId, cfg.secret);
        this.builder = new CsvBuilder(cfg.sqlDir);
    }

    /** @return broj neuspjelih cjenika (0 = sve u redu) */
    public int run() throws Exception {
        Log.info("Start (dryRun=" + cfg.dryRun + ", only=" + cfg.only + ", feed=" + cfg.feed
            + (cfg.fromDir != null ? ", fromDir=" + cfg.fromDir : "") + ")");
        List<Target> targets = targets();
        Log.info("Za obradu: " + targets.size() + " cjenika");

        int ok = 0;
        List<String> failed = new ArrayList<>();
        Connection conn = null;
        try {
            for (Target t : targets) {
                try {
                    String csv;
                    if (cfg.fromDir != null) {
                        Path f = cfg.fromDir.resolve(t.grp + "_" + t.betnr + "_" + t.feed + ".csv");
                        if (!Files.isRegularFile(f)) {
                            Log.warn(t.label() + ": nema " + f + ", preskacem");
                            continue;
                        }
                        csv = Files.readString(f, StandardCharsets.UTF_8);
                    } else {
                        if (conn == null || conn.isClosed()) {
                            conn = connect.open();
                        }
                        csv = builder.build(conn, t.grp, t.betnr, t.feed, t.sources, cfg.stateDir.resolve("history"), today());
                    }
                    if (cfg.dryRun) {
                        Path dir = cfg.outDir.resolve(today());
                        Files.createDirectories(dir);
                        Files.writeString(dir.resolve(t.grp + "_" + t.betnr + "_" + t.feed + ".csv"), csv, StandardCharsets.UTF_8);
                        Log.info(t.label() + ": DryRun, CSV spremljen u " + dir);
                        ok++;
                    } else if (send(t, csv)) {
                        Log.info(t.label() + ": OK");
                        ok++;
                    } else {
                        Log.error(t.label() + ": NIJE poslano");
                        failed.add(t.label());
                    }
                } catch (Exception e) {
                    Log.error(t.label() + ": " + e.getMessage());
                    failed.add(t.label());
                    // veza mozda vise nije valjana: sljedeci cjenik otvara novu
                    if (conn != null) {
                        try {
                            conn.close();
                        } catch (Exception ignored) {
                            // ignoriraj
                        }
                        conn = null;
                    }
                }
            }
        } finally {
            if (conn != null) {
                try {
                    conn.close();
                } catch (Exception ignored) {
                    // ignoriraj
                }
            }
        }
        Log.info("Gotovo: OK=" + ok + ", neuspjelo=" + failed.size());
        if (!failed.isEmpty()) {
            Log.error("Neuspjelo: " + String.join(", ", failed));
        }
        return failed.size();
    }

    private String today() {
        return LocalDate.now(cfg.zone).toString();
    }

    private List<Target> targets() throws IOException {
        List<Target> out = new ArrayList<>();
        if (cfg.only.contains("/")) {
            String[] p = cfg.only.split("/");
            for (String feed : List.of("proizvodi", "radovi")) {
                add(out, p[0], Integer.parseInt(p[1]), feed, Feeds.sourcesOf(feed));
            }
        } else {
            // sve lokacije (ili samo koncesija ONLY=ka4) s portala; portal javlja cjenike i ukljucene izvore
            for (PortalClient.Location l : requirePortal().locations()) {
                if (!cfg.only.isEmpty() && !l.grp().equalsIgnoreCase(cfg.only)) {
                    continue;
                }
                for (String feed : l.feeds()) {
                    List<String> src = Feeds.sourcesOf(feed);
                    // stariji portal bez popisa izvora: svi izvori cjenika
                    if (!l.sources().isEmpty()) {
                        src = src.stream().filter(s -> l.sources().contains(s)).toList();
                    }
                    add(out, l.grp(), l.betnr(), feed, src);
                }
            }
        }
        if (!cfg.feed.isEmpty()) {
            out.removeIf(t -> !t.feed.equals(cfg.feed));
        }
        return out;
    }

    private void add(List<Target> out, String grp, int betnr, String feed, List<String> sources) {
        List<String> src = cfg.sources.isEmpty() ? sources : sources.stream().filter(cfg.sources::contains).toList();
        if (src.isEmpty()) {
            Log.warn(grp + "/" + betnr + "/" + feed + ": nema izvora za obradu, preskacem");
            return;
        }
        out.add(new Target(grp, betnr, feed, src));
    }

    private PortalClient requirePortal() {
        if (portal == null) {
            throw new IllegalStateException("PORTAL_URL nije postavljen: popis lokacija dolazi s portala (ili koristi ONLY=grp/broj).");
        }
        return portal;
    }

    private boolean send(Target t, String csv) throws InterruptedException {
        for (int attempt = 1; attempt <= cfg.retries; attempt++) {
            PortalClient.Result r = requirePortal().put(t.grp, t.betnr, t.feed, csv);
            if (r.status() == 200) {
                return true;
            }
            boolean retryable = r.status() == 0 || r.status() >= 500 || r.status() == 429;
            Log.warn(t.label() + " pokusaj " + attempt + "/" + cfg.retries + " -> HTTP " + r.status() + ": " + r.body());
            if (!retryable) {
                return false;
            }
            // kao agent: nakon roka (DEADLINE, npr. 07:45) vise se ne ponavlja
            if (ZonedDateTime.now(cfg.zone).toLocalTime().isAfter(cfg.deadline)) {
                Log.error("Rok (" + cfg.deadline + ") je prosao, odustajem od " + t.label() + ".");
                return false;
            }
            Thread.sleep(Math.min(120, (long) Math.pow(2, attempt) * 2) * 1000L);
        }
        return false;
    }
}
