package hr.smit.cjenik.extractor;

import java.math.BigDecimal;
import java.sql.Date;
import java.sql.Timestamp;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.util.regex.Pattern;

/** Pretvorba vrijednosti iz baze u tekst celije CSV-a i ciscenje teksta. */
public final class Cells {
    private static final Pattern CONTROL = Pattern.compile("[\\x00-\\x1F\\x7F]+");
    private static final Pattern LEADING_FORMULA = Pattern.compile("^[=+@\\s]+");

    private Cells() {
    }

    public static String of(Object v) {
        if (v == null) {
            return "";
        }
        if (v instanceof BigDecimal d) {
            return RowRules.fmt(d);
        }
        if (v instanceof Double || v instanceof Float) {
            return RowRules.fmt(BigDecimal.valueOf(((Number) v).doubleValue()));
        }
        if (v instanceof Timestamp t) {
            return t.toLocalDateTime().toLocalDate().toString();
        }
        if (v instanceof Date d) {
            return d.toLocalDate().toString();
        }
        if (v instanceof LocalDateTime t) {
            return t.toLocalDate().toString();
        }
        if (v instanceof LocalDate d) {
            return d.toString();
        }
        return clean(String.valueOf(v));
    }

    /** Kontrolni znakovi (i novi redovi) -> razmak; trim. */
    public static String clean(String s) {
        return CONTROL.matcher(s).replaceAll(" ").trim();
    }

    /** Portal odbija tekst koji pocinje s = + @ (zastita od formula injectiona). */
    public static String text(String s) {
        return LEADING_FORMULA.matcher(s).replaceFirst("");
    }

    /** Polje CSV-a: navodnici samo kad treba. */
    public static String csv(String s) {
        if (s.indexOf(';') >= 0 || s.indexOf('"') >= 0 || s.indexOf('\r') >= 0 || s.indexOf('\n') >= 0) {
            return '"' + s.replace("\"", "\"\"") + '"';
        }
        return s;
    }
}
