package hr.smit.cjenik.extractor;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.sql.Connection;
import java.time.LocalDate;
import java.time.ZonedDateTime;
import java.util.ArrayList;
import java.util.List;

/** Jedan prolaz: za svaku lokaciju i cjenik izvuce podatke, napravi CSV i posalje ga portalu (ili spremi uz DRY_RUN). */
public final class Extractor {
    private record Target(String grp, int betnr, String feed) {
        String label() {
            return grp + "/" + betnr + "/" + feed;
        }
    }

    private final Config cfg;
    private final PortalClient portal;
    private final CsvBuilder builder;

    public Extractor(Config cfg) {
        this.cfg = cfg;
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
                            conn = As400.connect(cfg);
                        }
                        String today = today();
                        Path hf = cfg.stateDir.resolve("history").resolve(t.grp + "_" + t.betnr + "_" + t.feed + ".tsv");
                        csv = builder.build(conn, t.grp, t.betnr, t.feed, PriceHistory.load(hf), hf, today);
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
        if (!cfg.only.isEmpty()) {
            String[] p = cfg.only.split("/");
            if (p.length == 2) {
                for (String f : List.of("dijelovi", "vozila", "radovi")) {
                    out.add(new Target(p[0], Integer.parseInt(p[1]), f));
                }
            } else {
                // samo koncesija (npr. ONLY=ka4): lokacije s portala
                for (PortalClient.Location l : requirePortal().locations()) {
                    if (l.grp().equalsIgnoreCase(cfg.only)) {
                        l.feeds().forEach(f -> out.add(new Target(l.grp(), l.betnr(), f)));
                    }
                }
            }
        } else {
            for (PortalClient.Location l : requirePortal().locations()) {
                l.feeds().forEach(f -> out.add(new Target(l.grp(), l.betnr(), f)));
            }
        }
        if (!cfg.feed.isEmpty()) {
            out.removeIf(t -> !t.feed.equals(cfg.feed));
        }
        return out;
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
