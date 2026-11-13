package hr.smit.cjenik.extractor;

import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.charset.CharacterCodingException;
import java.nio.charset.Charset;
import java.nio.charset.CodingErrorAction;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.regex.Pattern;
import java.util.stream.Stream;

/**
 * SQL datoteke po cjeniku: mapa SQL_DIR/&lt;cjenik&gt;/*.sql (svaka se izvrsi, redci se spoje; redoslijed po imenu)
 * ili jedna datoteka SQL_DIR/&lt;cjenik&gt;.sql. U upitu {GRP} (mala slova) i {BETNR} se zamjenjuju po lokaciji.
 */
public final class SqlFiles {
    private static final Pattern GRP = Pattern.compile("^[A-Za-z0-9]{2,8}$");

    private SqlFiles() {
    }

    public static List<Path> files(Path sqlDir, String feed) throws IOException {
        Path dir = sqlDir.resolve(feed);
        if (Files.isDirectory(dir)) {
            List<Path> out = new ArrayList<>();
            try (Stream<Path> s = Files.list(dir)) {
                s.filter(p -> p.getFileName().toString().toLowerCase().endsWith(".sql"))
                    .sorted(Comparator.comparing(p -> p.getFileName().toString()))
                    .forEach(out::add);
            }
            if (out.isEmpty()) {
                throw new IOException("Mapa " + dir + " nema nijedan .sql");
            }
            return out;
        }
        Path single = sqlDir.resolve(feed + ".sql");
        if (Files.isRegularFile(single)) {
            return List.of(single);
        }
        throw new IOException("Nema SQL-a za cjenik '" + feed + "': ni " + single + " ni mapa " + dir);
    }

    /** UTF-8; ako nije valjan UTF-8 (spremljen iz Windows alata), Windows-1250. */
    public static String read(Path file) throws IOException {
        byte[] bytes = Files.readAllBytes(file);
        try {
            String s = StandardCharsets.UTF_8.newDecoder()
                .onMalformedInput(CodingErrorAction.REPORT)
                .onUnmappableCharacter(CodingErrorAction.REPORT)
                .decode(ByteBuffer.wrap(bytes)).toString();
            return s.startsWith("﻿") ? s.substring(1) : s;
        } catch (CharacterCodingException e) {
            return new String(bytes, Charset.forName("windows-1250"));
        }
    }

    /** Uklanja retke komentara (--), zamjenjuje {GRP} i {BETNR}. Vrijednosti se provjeravaju (nema SQL injectiona). */
    public static String expand(String sql, String grp, int betnr) {
        if (grp == null || !GRP.matcher(grp).matches()) {
            throw new IllegalArgumentException("Neispravan GRP '" + grp + "'");
        }
        if (betnr < 1 || betnr > 99999) {
            throw new IllegalArgumentException("Neispravan BETNR '" + betnr + "'");
        }
        StringBuilder sb = new StringBuilder();
        for (String line : sql.split("\\R", -1)) {
            if (line.stripLeading().startsWith("--")) {
                continue;
            }
            sb.append(line).append('\n');
        }
        return sb.toString().replace("{GRP}", grp.toLowerCase()).replace("{BETNR}", Integer.toString(betnr));
    }
}
