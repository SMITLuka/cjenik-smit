package hr.smit.cjenik.extractor;

import java.sql.Connection;
import java.sql.DriverManager;
import java.sql.SQLException;
import java.util.Properties;

/**
 * Veza na AS400 preko JDBC (jt400). Ista konvencija kao u MIMV-u: jdbc:as400://HOST;parametri.
 * Korisnik treba biti SAMO za citanje; servis izvrsava iskljucivo SELECT iz SQL datoteka.
 */
public final class As400 {
    private As400() {
    }

    public static Connection connect(Config cfg) throws SQLException {
        String url = "jdbc:as400://" + cfg.as400Host + (cfg.as400Params.isEmpty() ? "" : ";" + cfg.as400Params);
        Properties p = new Properties();
        p.setProperty("user", cfg.as400User);
        p.setProperty("password", cfg.as400Password);
        DriverManager.setLoginTimeout(30);
        Connection c = DriverManager.getConnection(url, p);
        c.setReadOnly(true);
        return c;
    }
}
