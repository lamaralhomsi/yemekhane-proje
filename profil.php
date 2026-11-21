<?php
require_once __DIR__ . '/baglanti.php';
redisOturumBaslat();
require_once __DIR__ . '/veritabani_islemleri.php';

if (!isset($_SESSION['kullanici_id']) || (int)$_SESSION['kullanici_id'] <= 0) {
    header("Location: index.php");
    exit;
}

$kullanici_id = (int)$_SESSION['kullanici_id'];
$kullanici_rol = strtolower(trim((string)kullaniciRolAl($kullanici_id)));
$_SESSION['rol'] = $kullanici_rol;
$_SESSION['kullanici_rol'] = $kullanici_rol;

$mesaj = "";
$hata = "";
$tumAlerjenler = gecerliAlerjenListesi();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alerjen_kaydet'])) {
    $secilenler = $_POST['alerjenler'] ?? [];
    if (!is_array($secilenler)) {
        $secilenler = [];
    }
    try {
        kullaniciAlerjenleriniKaydet($kullanici_id, $secilenler);
        $mesaj = "Alerjen tercihleriniz güncellendi! Menü ekranlarında bu alerjenler için uyarı gösterilecektir.";
    } catch (Throwable $e) {
        $hata = "Alerjenler kaydedilirken hata oluştu: " . temizSqlHatasi($e);
    }
}

$seciliAlerjenler = kullaniciAlerjenleriniGetir($kullanici_id);
$kullaniciBilgi = null;
try {
    $db = getReplicaConnection();
    $stmt = $db->prepare("SELECT ad, soyad, eposta, rol, bakiye FROM kullanicilar WHERE id = ? LIMIT 1");
    $stmt->execute([$kullanici_id]);
    $kullaniciBilgi = $stmt->fetch() ?: null;
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yemekhane - Profil ve Alerjen Tercihleri</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: radial-gradient(circle at top, #1f121d 0%, #0b0b0e 65%);
            color: #f3f4f6;
            margin: 0;
            padding: 20px 14px;
            line-height: 1.5;
        }
        .container {
            max-width: 900px;
            margin: auto;
            background: #14151a;
            padding: 28px;
            border-radius: 16px;
            border: 1px solid #262833;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
        }
        .nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid #262833;
        }
        .nav a {
            background: #1d1f27;
            border: 1px solid #2e313f;
            padding: 8px 13px;
            border-radius: 8px;
            text-decoration: none;
            color: #f472b6;
            font-weight: 600;
            font-size: 12.5px;
            transition: all 0.2s ease;
        }
        .nav a:hover {
            background: linear-gradient(135deg, #c2185b, #e91e63);
            color: #ffffff;
            border-color: transparent;
        }
        .page-title {
            font-size: 23px;
            font-weight: 700;
            margin-bottom: 22px;
            border-left: 5px solid #ec4899;
            padding-left: 14px;
            color: #ffffff;
        }
        .profil-kutu {
            background: linear-gradient(180deg, #1b1d25 0%, #171920 100%);
            border: 1px solid #2a2d3a;
            padding: 18px 20px;
            border-radius: 14px;
            margin-bottom: 22px;
            font-size: 14px;
            color: #e5e7eb;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
        }
        form {
            background: linear-gradient(180deg, #1b1d25 0%, #171920 100%);
            border: 1px solid #2a2d3a;
            padding: 22px;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
        }
        h3 {
            margin: 0 0 8px 0;
            font-size: 18px;
            color: #ffffff;
        }
        .alerjen-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 10px;
            margin: 16px 0 20px;
        }
        .alerjen-kart {
            background: #13151b;
            border: 1px solid #252834;
            padding: 11px 14px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-size: 13.5px;
            color: #f3f4f6;
            transition: border-color 0.2s ease, background 0.2s ease;
        }
        .alerjen-kart:hover {
            border-color: #ec4899;
            background: #181a22;
        }
        .alerjen-kart input {
            width: 17px;
            height: 17px;
            accent-color: #e91e63;
            cursor: pointer;
        }
        button {
            background: linear-gradient(135deg, #c2185b, #e91e63);
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13.5px;
        }
        button:hover { opacity: 0.92; }
        .basari {
            color: #6ee7b7;
            font-weight: 600;
            padding: 12px 14px;
            background: rgba(16, 185, 129, 0.14);
            border: 1px solid rgba(16, 185, 129, 0.4);
            border-radius: 10px;
            margin-bottom: 16px;
        }
        .hata {
            color: #fca5a5;
            font-weight: 600;
            padding: 12px 14px;
            background: rgba(239, 68, 68, 0.14);
            border: 1px solid rgba(239, 68, 68, 0.4);
            border-radius: 10px;
            margin-bottom: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="menu.php">🍽️ Günün Menüsü</a>
            <a href="haftalik_rezervasyon.php">📅 Haftalık Menü ve Rezervasyon</a>
            <a href="bakiye.php">💰 Bakiye İşlemleri</a>
            <a href="rezervasyonlarim.php">📅 Rezervasyonlarım</a>
            <a href="degerlendirme.php">⭐ Menü Puanla</a>
            <a href="profil.php">⚠️ Profil / Alerjenler</a>
            <?php if ($kullanici_rol === 'yonetici'): ?>
                <a href="yonetici.php">👑 Yönetici Paneli</a>
            <?php endif; ?>
            <a href="index.php?cikis=1">🚪 Çıkış Yap</a>
        </div>

        <div class="page-title">Profil ve Alerjen Tercihleri</div>

        <?php if ($mesaj !== ''): ?><div class="basari"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata !== ''): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <?php if ($kullaniciBilgi): ?>
            <div class="profil-kutu">
                <strong>Ad Soyad:</strong> <?= htmlspecialchars(($kullaniciBilgi['ad'] ?? '') . ' ' . ($kullaniciBilgi['soyad'] ?? '')) ?> &nbsp;|&nbsp;
                <strong>Rol:</strong> <?= htmlspecialchars(strtoupper((string)$kullanici_rol)) ?> &nbsp;|&nbsp;
                <strong>Bakiye:</strong> <?= number_format((float)($kullaniciBilgi['bakiye'] ?? 0), 2) ?> TL
            </div>
        <?php endif; ?>

        <form method="POST">
            <h3>Alerjenlerinizi Seçin</h3>
            <p style="color:#bbbbbb; font-size:13px;">Seçtiğiniz alerjenleri içeren yemekler menü ekranlarında uyarı ile gösterilecektir.</p>

            <div class="alerjen-grid">
                <?php foreach ($tumAlerjenler as $kod => $etiket): ?>
                    <?php $isaretli = in_array($kod, $seciliAlerjenler, true); ?>
                    <label class="alerjen-kart">
                        <input type="checkbox" name="alerjenler[]" value="<?= htmlspecialchars($kod) ?>" <?= $isaretli ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($etiket) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <button type="submit" name="alerjen_kaydet" value="1">Alerjen Tercihlerini Kaydet</button>
        </form>
    </div>
</body>
</html>