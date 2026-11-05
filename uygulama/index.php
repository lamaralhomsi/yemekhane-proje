<?php
require_once __DIR__ . '/baglanti.php';
redisOturumBaslat();
require_once __DIR__ . '/veritabani_islemleri.php';

$hata = "";

if (isset($_GET['cikis']) && (int)$_GET['cikis'] === 1) {
    redisOturumSonlandir();
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $eposta = trim($_POST['eposta'] ?? '');
    $sifre = $_POST['sifre'] ?? '';

    if ($eposta !== '' && $sifre !== '') {
        try {
            $kullanici = kullaniciGetirEposta($eposta);

            if (!$kullanici) {
                $hata = "Bu e-posta adresine ait kayıt bulunamadı!";
            } elseif ((int)$kullanici['aktif'] !== 1) {
                $hata = "Hesabınız pasif durumda.";
            } elseif (!password_verify($sifre, (string)$kullanici['sifre_hash'])) {
                $hata = "Hatalı şifre girdiniz!";
            } else {
                $_SESSION['kullanici_id'] = (int)$kullanici['id'];
                $_SESSION['kullanici_ad'] = $kullanici['ad'] . ' ' . $kullanici['soyad'];
                $_SESSION['kullanici_rol'] = $kullanici['rol'];

                header("Location: menu.php");
                exit;
            }
        } catch (Throwable $e) {
            $hata = "Giriş yapılırken bir hata oluştu: " . temizSqlHatasi($e);
        }
    } else {
        $hata = "Lütfen e-posta ve şifre alanlarını doldurun.";
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yemekhane Otomasyonu - Giriş Yap</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #0b0b0b; color: #ffffff; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 15px; box-sizing: border-box; }
        .login-card { background: #161616; padding: 30px; border-radius: 12px; box-shadow: 0 8px 24px rgba(216, 27, 96, 0.2); width: 100%; max-width: 400px; border: 1px solid #2a2a2a; }
        h2 { text-align: center; color: #e91e63; margin-bottom: 25px; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #cccccc; font-size: 14px; }
        input { width: 100%; padding: 12px; background: #222222; border: 1px solid #333333; color: #ffffff; border-radius: 6px; box-sizing: border-box; outline: none; }
        input:focus { border-color: #e91e63; }
        button { width: 100%; background: #c2185b; color: white; border: none; padding: 12px; border-radius: 6px; font-size: 16px; font-weight: bold; cursor: pointer; }
        button:hover { background: #e91e63; }
        .hata { color: #ff6b6b; background: rgba(255, 107, 107, 0.1); border: 1px solid rgba(255, 107, 107, 0.3); padding: 10px; border-radius: 6px; margin-bottom: 18px; text-align: center; font-weight: bold; font-size: 13px; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Yemekhane Girişi</h2>
        <?php if ($hata !== ''): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label>E-posta Adresi:</label>
                <input type="email" name="eposta" required placeholder="ornek@kampus.edu.tr">
            </div>
            <div class="form-group">
                <label>Şifre:</label>
                <input type="password" name="sifre" required placeholder="Şifreniz">
            </div>
            <button type="submit">Giriş Yap</button>
        </form>
    </div>
</body>
</html>