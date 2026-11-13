package hr.smit.cjenik.extractor;

import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;

/** Jednostavan log na stdout (Docker/Coolify ga hvataju). Tajne se nikad ne ispisuju. */
public final class Log {
    private static final DateTimeFormatter F = DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss");

    private Log() {
    }

    public static void info(String msg) {
        System.out.println(LocalDateTime.now().format(F) + " [INFO] " + msg);
    }

    public static void warn(String msg) {
        System.out.println(LocalDateTime.now().format(F) + " [WARN] " + msg);
    }

    public static void error(String msg) {
        System.err.println(LocalDateTime.now().format(F) + " [ERROR] " + msg);
    }
}
