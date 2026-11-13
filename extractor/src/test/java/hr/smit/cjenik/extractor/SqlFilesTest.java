package hr.smit.cjenik.extractor;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.nio.charset.Charset;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

class SqlFilesTest {
    @Test
    void zamjenaOznakaIUklanjanjeKomentara() {
        String sql = "-- komentar {GRP}\nselect * from {GRP}d{BETNR}l.ets\n  -- jos jedan\nwhere x = 1";
        String out = SqlFiles.expand(sql, "KB5", 21);
        assertEquals("select * from kb5d21l.ets\nwhere x = 1\n", out);
    }

    @Test
    void neispravnaOznakaSeOdbija() {
        assertThrows(IllegalArgumentException.class, () -> SqlFiles.expand("select 1", "kb5; drop table x", 1));
        assertThrows(IllegalArgumentException.class, () -> SqlFiles.expand("select 1", "k", 1));
        assertThrows(IllegalArgumentException.class, () -> SqlFiles.expand("select 1", "kb5", 0));
        assertThrows(IllegalArgumentException.class, () -> SqlFiles.expand("select 1", "kb5", 100000));
    }

    @Test
    void mapaSaViseDatotekaPoImenu(@TempDir Path dir) throws Exception {
        Files.createDirectories(dir.resolve("dijelovi"));
        Files.writeString(dir.resolve("dijelovi/02-b.sql"), "select 2");
        Files.writeString(dir.resolve("dijelovi/01-a.sql"), "select 1");
        Files.writeString(dir.resolve("dijelovi/readme.txt"), "ne");
        List<Path> f = SqlFiles.files(dir, "dijelovi");
        assertEquals(List.of("01-a.sql", "02-b.sql"), f.stream().map(p -> p.getFileName().toString()).toList());
    }

    @Test
    void jednaDatotekaIGreskaKadNedostaje(@TempDir Path dir) throws Exception {
        Files.writeString(dir.resolve("vozila.sql"), "select 1");
        assertEquals(1, SqlFiles.files(dir, "vozila").size());
        assertThrows(java.io.IOException.class, () -> SqlFiles.files(dir, "radovi"));
    }

    @Test
    void citanjeUtf8ISRezervomWindows1250(@TempDir Path dir) throws Exception {
        Path u = dir.resolve("u.sql");
        Files.write(u, "select 'čćž' from t".getBytes(java.nio.charset.StandardCharsets.UTF_8));
        assertTrue(SqlFiles.read(u).contains("čćž"));
        Path w = dir.resolve("w.sql");
        Files.write(w, "-- uskladštena\nselect 1".getBytes(Charset.forName("windows-1250")));
        assertTrue(SqlFiles.read(w).contains("uskladštena"));
        Path bom = dir.resolve("b.sql");
        Files.write(bom, new byte[]{(byte) 0xEF, (byte) 0xBB, (byte) 0xBF, 's', 'e', 'l', 'e', 'c', 't'});
        assertFalse(SqlFiles.read(bom).startsWith("﻿"));
    }
}
