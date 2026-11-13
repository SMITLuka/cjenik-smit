package hr.smit.cjenik.extractor;

import java.time.Duration;
import java.time.ZonedDateTime;

/**
 * Ulaz. RUN_ONCE=true: jedan prolaz pa izlaz (izlazni kod 1 ako je nesto neuspjelo).
 * Inace servis radi stalno i pokrece prolaz svaki dan u RUN_AT (zadano 07:00, vremenska zona TZ).
 */
public final class Main {
    private Main() {
    }

    public static void main(String[] args) throws Exception {
        Config cfg;
        try {
            cfg = Config.fromEnv(System.getenv());
        } catch (RuntimeException e) {
            Log.error("Konfiguracija: " + e.getMessage());
            System.exit(2);
            return;
        }
        Log.info("cjenik-extractor: portal=" + (cfg.portalUrl.isEmpty() ? "-" : cfg.portalUrl)
            + ", AS400=" + (cfg.fromDir != null ? "FROM_DIR" : cfg.as400Host + " (korisnik " + cfg.as400User + ")")
            + ", SQL_DIR=" + cfg.sqlDir + ", runAt=" + cfg.runAt + ", zona=" + cfg.zone);

        if (cfg.runOnce) {
            System.exit(runSafely(cfg) == 0 ? 0 : 1);
            return;
        }
        if (cfg.runOnStart) {
            runSafely(cfg);
        }
        while (true) {
            ZonedDateTime now = ZonedDateTime.now(cfg.zone);
            ZonedDateTime next = now.with(cfg.runAt);
            if (!next.isAfter(now)) {
                next = next.plusDays(1);
            }
            Duration wait = Duration.between(now, next);
            Log.info("Sljedeci prolaz: " + next.toLocalDateTime() + " (za " + wait.toMinutes() + " min)");
            Thread.sleep(wait.toMillis());
            runSafely(cfg);
        }
    }

    private static int runSafely(Config cfg) {
        try {
            return new Extractor(cfg).run();
        } catch (Exception e) {
            Log.error("Prolaz nije uspio: " + e.getMessage());
            return 1;
        }
    }
}
