<?php
declare(strict_types=1);

require __DIR__ . '/cli.php';

use Cjenik\Clock;
use Cjenik\Db;
use Cjenik\Support;

// Upotreba:  php app/bin/create-admin.php <korisnik>            (lozinku upisi kad se zatrazi)
//            php app/bin/create-admin.php <korisnik> --password-stdin   (lozinka iz stdin-a)
$username = trim((string)($argv[1] ?? ''));
if (preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username) !== 1) {
    fwrite(STDERR, "Upotreba: php create-admin.php <korisnik (3-60 znakova: slova, brojevi . _ -)> [--password-stdin]\n");
    exit(1);
}
if (in_array('--password-stdin', $argv, true)) {
    $password = rtrim((string)fgets(STDIN), "\r\n");
} else {
    fwrite(STDERR, 'Lozinka (min. 12 znakova; unos je vidljiv): ');
    $password = rtrim((string)fgets(STDIN), "\r\n");
}
// PowerShell/Windows pri pipe-anju zna dodati UTF-8 BOM na pocetak — nije dio lozinke.
$password = preg_replace('/^\xEF\xBB\xBF/', '', $password) ?? $password;
if (strlen($password) < 12) {
    fwrite(STDERR, "Lozinka mora imati barem 12 znakova.\n");
    exit(1);
}
if (Db::val('SELECT COUNT(*) FROM admins WHERE username = ?', [$username]) > 0) {
    Db::run('UPDATE admins SET pass_hash = ?, totp_secret_enc = NULL, totp_confirmed = 0, totp_last_step = 0, disabled = 0 WHERE username = ?', [password_hash($password, PASSWORD_DEFAULT), $username]);
    Support::audit('cli', 'admin.reset', $username);
    echo "Lozinka resetirana, 2FA ce se ponovno postaviti pri prvoj prijavi.\n";
} else {
    Db::run('INSERT INTO admins (username, pass_hash, created_at) VALUES (?,?,?)', [$username, password_hash($password, PASSWORD_DEFAULT), Clock::utcStamp()]);
    Support::audit('cli', 'admin.create', $username);
    echo "Admin '$username' je kreiran. 2FA se postavlja pri prvoj prijavi.\n";
}
