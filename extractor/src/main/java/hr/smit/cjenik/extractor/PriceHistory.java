package hr.smit.cjenik.extractor;

import java.io.IOException;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.StandardCopyOption;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

/**
 * Povijest cijena za "najnizu cijenu u zadnjih 30 dana" (za dijelove u bazi nema zurnala cijena).
 * Datoteka (isti oblik kao kod PowerShell agenta, pa se stara povijest moze preuzeti): jedan red po PROMJENI cijene,
 * "kljuc TAB yyyy-MM-dd TAB cijena".
 */
public final class PriceHistory {
    public static final int DAYS = 30;

    private record Entry(String date, BigDecimal price) {
    }

    private final Map<String, List<Entry>> data = new HashMap<>();
    private boolean dirty;

    public static PriceHistory load(Path file) throws IOException {
        PriceHistory h = new PriceHistory();
        if (Files.isRegularFile(file)) {
            for (String line : Files.readAllLines(file, StandardCharsets.UTF_8)) {
                String[] p = line.split("\t", -1);
                if (p.length != 3) {
                    continue;
                }
                try {
                    h.data.computeIfAbsent(p[0], k -> new ArrayList<>()).add(new Entry(p[1], new BigDecimal(p[2])));
                } catch (NumberFormatException ignored) {
                    // preskoci neispravan red
                }
            }
        }
        return h;
    }

    public boolean isDirty() {
        return dirty;
    }

    private static String windowStart(String today) {
        return LocalDate.parse(today).minusDays(DAYS).toString();
    }

    /** Najnizi iznos u zadnjih 30 dana: trenutna cijena, cijene koje su poceli vrijediti u prozoru i ona s pocetka prozora. */
    public BigDecimal lowest30(String key, BigDecimal current, String today) {
        String start = windowStart(today);
        BigDecimal min = current;
        List<Entry> list = data.get(key);
        if (list != null) {
            BigDecimal atStart = null;
            for (Entry e : list) {
                if (e.date().compareTo(start) <= 0) {
                    atStart = e.price();
                } else if (e.price().compareTo(min) < 0) {
                    min = e.price();
                }
            }
            if (atStart != null && atStart.compareTo(min) < 0) {
                min = atStart;
            }
        }
        return min;
    }

    /** Biljezi trenutnu cijenu (samo ako se razlikuje od zadnje zabiljezene). */
    public boolean update(String key, BigDecimal current, String today) {
        List<Entry> list = data.computeIfAbsent(key, k -> new ArrayList<>());
        if (!list.isEmpty() && list.get(list.size() - 1).price().compareTo(current) == 0) {
            return false;
        }
        list.add(new Entry(today, current));
        dirty = true;
        return true;
    }

    /** Sprema povijest; stari unosi se brisu, osim cijene koja je vrijedila na pocetku prozora. */
    public void save(Path file, String today) throws IOException {
        String start = windowStart(today);
        List<String> lines = new ArrayList<>();
        for (Map.Entry<String, List<Entry>> k : data.entrySet()) {
            List<Entry> list = k.getValue();
            int keepFrom = 0;
            for (int i = 0; i < list.size(); i++) {
                if (list.get(i).date().compareTo(start) <= 0) {
                    keepFrom = i;
                }
            }
            for (int i = keepFrom; i < list.size(); i++) {
                lines.add(k.getKey() + "\t" + list.get(i).date() + "\t" + RowRules.fmt(list.get(i).price()));
            }
        }
        Files.createDirectories(file.getParent());
        Path tmp = file.resolveSibling(file.getFileName() + ".tmp");
        Files.write(tmp, lines, StandardCharsets.UTF_8);
        Files.move(tmp, file, StandardCopyOption.REPLACE_EXISTING);
        dirty = false;
    }
}
