package hr.smit.cjenik.extractor;

import java.nio.file.Path;
import java.sql.Connection;
import java.sql.PreparedStatement;
import java.sql.ResultSet;
import java.sql.ResultSetMetaData;
import java.sql.Statement;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Izvrsava SQL datoteke nad bazom (samo SELECT) i gradi jedan CSV cjenika prema specifikaciji portala.
 * Cjenik 'proizvodi' nastaje spajanjem izvora 'dijelovi' i 'vozila' (isti stupci) u jednu datoteku; 'radovi' je
 * zaseban cjenik s jednim izvorom.
 */
public final class CsvBuilder {
    private final Path sqlDir;

    public CsvBuilder(Path sqlDir) {
        this.sqlDir = sqlDir;
    }

    /**
     * Stupci se mapiraju PO IMENU (alias u SELECT-u). Obavezni stupci moraju postojati; neobavezne i izvedene
     * (vidi Feeds) servis dopunjuje. Parametri '?' (ako ih ima u upitu): 1) GRP velikim slovima, 2) BETNR.
     * Za svaki izvor SQL datoteke su u SQL_DIR/&lt;izvor&gt;/*.sql (ili SQL_DIR/&lt;izvor&gt;.sql). Izvor bez redaka je u redu
     * ako neki drugi izvor ima redaka; ako nema nijedan redak, to je greska.
     *
     * @param sources    izvori koji se spajaju u cjenik (npr. dijelovi i vozila)
     * @param historyDir mapa povijesti cijena; datoteka po lokaciji i izvoru (grp_betnr_izvor.tsv)
     */
    public String build(Connection conn, String grp, int betnr, String feed, List<String> sources, Path historyDir,
                        String today) throws Exception {
        List<String> cols = Feeds.columns(feed);
        StringBuilder sb = new StringBuilder(String.join(";", cols)).append('\n');
        int total = 0;
        for (String source : sources) {
            Path historyFile = historyDir.resolve(grp + "_" + betnr + "_" + source + ".tsv");
            PriceHistory hist = PriceHistory.load(historyFile);
            int sourceRows = 0;
            for (Path file : SqlFiles.files(sqlDir, source)) {
                String sql = SqlFiles.expand(SqlFiles.read(file), grp, betnr);
                int qm = (int) sql.chars().filter(c -> c == '?').count();
                int fileRows;
                if (qm == 0) {
                    try (Statement st = conn.createStatement()) {
                        st.setQueryTimeout(1800);
                        st.setFetchSize(1000);
                        try (ResultSet rs = st.executeQuery(sql)) {
                            fileRows = consume(rs, cols, hist, today, sb, file.getFileName().toString());
                        }
                    }
                } else {
                    try (PreparedStatement ps = conn.prepareStatement(sql)) {
                        ps.setQueryTimeout(1800);
                        ps.setFetchSize(1000);
                        ps.setString(1, grp.toUpperCase());
                        if (qm >= 2) {
                            ps.setInt(2, betnr);
                        }
                        try (ResultSet rs = ps.executeQuery()) {
                            fileRows = consume(rs, cols, hist, today, sb, file.getFileName().toString());
                        }
                    }
                }
                sourceRows += fileRows;
                Log.info("  " + grp + "/" + betnr + "/" + feed + ": " + source + "/" + file.getFileName() + " -> " + fileRows + " redaka");
            }
            if (hist.isDirty()) {
                hist.save(historyFile, today);
            }
            if (sourceRows == 0 && sources.size() > 1) {
                Log.warn("  " + grp + "/" + betnr + "/" + feed + ": izvor " + source + " nema redaka");
            }
            total += sourceRows;
        }
        if (total == 0) {
            throw new IllegalStateException("SQL za " + grp + "/" + betnr + "/" + feed + " nije vratio nijedan redak.");
        }
        return sb.toString();
    }

    private int consume(ResultSet rs, List<String> cols, PriceHistory hist, String today, StringBuilder sb,
                        String fileName) throws Exception {
        ResultSetMetaData md = rs.getMetaData();
        Map<String, Integer> idx = new HashMap<>();
        for (int i = 1; i <= md.getColumnCount(); i++) {
            idx.put(md.getColumnLabel(i).toLowerCase(), i);
        }
        // najnizu cijenu u 30 dana racuna servis iz povijesti samo ako je SQL ne vraca
        PriceHistory rowHist = idx.containsKey("najniza_cijena_30_dana") ? null : hist;
        for (Map.Entry<String, String> f : Feeds.FALLBACK.entrySet()) {
            if (!idx.containsKey(f.getKey()) && idx.containsKey(f.getValue())) {
                idx.put(f.getKey(), idx.get(f.getValue()));
            }
        }
        for (String c : cols) {
            if (!idx.containsKey(c) && !Feeds.OPTIONAL.contains(c) && !Feeds.DERIVED.contains(c)) {
                throw new IllegalStateException(fileName + ": SQL ne vraca kolonu '" + c + "' (alias u SELECT-u).");
            }
        }
        Set<String> sqlCols = new HashSet<>(idx.keySet());
        int n = 0;
        while (rs.next()) {
            Map<String, String> vals = new LinkedHashMap<>();
            for (String c : cols) {
                String s = idx.containsKey(c) ? Cells.of(rs.getObject(idx.get(c))) : "";
                if (Feeds.TEXT.contains(c)) {
                    s = Cells.text(s);
                }
                vals.put(c, s);
            }
            // prazan naziv (u bazi ima artikala bez naziva) -> zamjenjuje se sifrom
            for (String nc : List.of("naziv", "naziv_usluge")) {
                if (vals.containsKey(nc) && vals.get(nc).isEmpty() && vals.containsKey("sifra")) {
                    vals.put(nc, vals.get("sifra"));
                }
            }
            RowRules.complete(vals, sqlCols, cols, rowHist, today);
            boolean first = true;
            for (String c : cols) {
                if (!first) {
                    sb.append(';');
                }
                sb.append(Cells.csv(vals.get(c)));
                first = false;
            }
            sb.append('\n');
            n++;
        }
        return n;
    }
}
