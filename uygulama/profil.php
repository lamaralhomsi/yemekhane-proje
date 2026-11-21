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
        body { font-family: Arial, sans-serif; background-color: #0b0b0b; color: #ffffff; margin: 0; padding: 15px; }
        .container { max-width: 850px; margin: auto; background: #161616; padding: 25px; border-radius: 12px; border: 1px solid #2a2a2a; }
        .page-title { font-size: 22px; font-weight: bold; margin-bottom: 20px; border-left: 5px solid #e91e63; padding-left: 12px; }
        .nav { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #2a2a2a; }
        .nav a { background: #222222; border: 1px solid #333333; padding: 8px 12px; border-radius: 6px; text-decoration: none; color: #e91e63; font-weight: bold; font-size: 12px; }
        .nav a:hover { background: #c2185b; color: #ffffff; }
        .profil-kutu { background: #1f1f1f; border: 1px solid #2a2a2a; padding: 16px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; color: #dddddd; }
        .alerjen-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin: 15px 0 20px; }
        .alerjen-kart { background: #1f1f1f; border: 1px solid #333; padding: 12px; border-radius: 8px; display: flex; align-items: center; gap: 10px; cursor: pointer; }
        .alerjen-kart input { width: 18px; height: 18px; accent-color: #e91e63; cursor: pointer; }
        button { background: #c2185b; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        button:hover { background: #e91e63; }
        .basari { color: #2ed573; font-weight: bold; padding: 12px; background: rgba(46, 213, 115, 0.1); border-radius: 6px; margin-bottom: 15px; }
        .hata { color: #ff6b6b; font-weight: bold; padding: 12px; background: rgba(255, 107, 107, 0.1); border-radius: 6px; margin-bottom: 15px; }
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