<?php
require_once __DIR__ . '/baglanti.php';
redisOturumBaslat();
require_once __DIR__ . '/veritabani_islemleri.php';

if (!isset($_SESSION['kullanici_id']) || (int)$_SESSION['kullanici_id'] <= 0) {
    header("Location: index.php");
    exit;
}

$kullaniciId = (int)$_SESSION['kullanici_id'];
$aktifRol = kullaniciRolAl($kullaniciId);
$_SESSION['rol'] = $aktifRol;
$_SESSION['kullanici_rol'] = $aktifRol;

if ($aktifRol !== 'yonetici') {
    $_SESSION['yetki_uyarisi'] = "Bu sayfadaki işlemleri yalnızca yöneticiler yapabilir.";
    header("Location: menu.php");
    exit;
}

$mesaj = "";
$hata = "";

function menuDuzenlenebilirMi(array $menuSatiri): bool {
    return true;
}

// Yeni Menü Oluşturma (Yalnızca 'yonetici' rolü)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['menu_ekle'])) {
    $yemekhane_id = (int)($_POST['yemekhane_id'] ?? 0);
    $ogun_id = (int)($_POST['ogun_id'] ?? 0);
    $tarih = trim((string)($_POST['tarih'] ?? ''));
    $ogrenci_fiyat = (float)($_POST['ogrenci_fiyati'] ?? 30.00);
    $personel_fiyat = (float)($_POST['personel_fiyati'] ?? 45.00);
    $secilen_yemekler = array_map('intval', $_POST['yemekler'] ?? []);

    if ($yemekhane_id > 0 && $ogun_id > 0 && $tarih !== '') {
        try {
            $dbPrimary = getPrimaryConnection();
            $stmtKontrol = $dbPrimary->prepare("
                SELECT id FROM menuler
                WHERE yemekhane_id = ? AND tarih = ? AND ogun_id = ?
            ");
            $stmtKontrol->execute([$yemekhane_id, $tarih, $ogun_id]);

            if ($stmtKontrol->rowCount() > 0) {
                $hata = "Bu yemekhane, tarih ve öğün için zaten bir menü mevcut.";
            } else {
                $dbPrimary->beginTransaction();
                $stmtIns = $dbPrimary->prepare("
                    INSERT INTO menuler (yemekhane_id, tarih, ogun_id, ogrenci_fiyati, personel_fiyati)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$yemekhane_id, $tarih, $ogun_id, $ogrenci_fiyat, $personel_fiyat]);
                $menu_id = (int)$dbPrimary->lastInsertId();

                if (!empty($secilen_yemekler)) {
                    $stmtYemekIns = $dbPrimary->prepare("
                        INSERT IGNORE INTO menu_yemekleri (menu_id, yemek_id) VALUES (?, ?)
                    ");
                    foreach ($secilen_yemekler as $yemek_id) {
                        if ($yemek_id > 0) {
                            $stmtYemekIns->execute([$menu_id, $yemek_id]);
                        }
                    }
                }
                $dbPrimary->commit();

                redisMenuSil($yemekhane_id, $tarih, $ogun_id);
                menuOnbellekTemizle();

                $mesaj = "Yeni menü ve seçilen yemekler başarıyla oluşturuldu!";
            }
        } catch (Throwable $e) {
            if (isset($dbPrimary) && $dbPrimary->inTransaction()) {
                $dbPrimary->rollBack();
            }
            $hata = "Menü eklenirken hata oluştu: " . temizSqlHatasi($e);
        }
    } else {
        $hata = "Lütfen yemekhane, öğün ve tarih alanlarını doldurun.";
    }
}

// Mevcut Menüyü Düzenleme (Yalnızca 'yonetici' rolü)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['menu_guncelle'])) {
    $duzenle_menu_id = (int)($_POST['duzenle_menu_id'] ?? 0);
    $ogrenci_fiyat = (float)($_POST['ogrenci_fiyati'] ?? 30.00);
    $personel_fiyat = (float)($_POST['personel_fiyati'] ?? 45.00);
    $secilen_yemekler = array_map('intval', $_POST['duzenle_yemekler'] ?? []);

    if ($duzenle_menu_id > 0) {
        try {
            $dbPrimary = getPrimaryConnection();
            $stmtHedef = $dbPrimary->prepare("
                SELECT m.id, m.yemekhane_id, m.tarih, m.ogun_id, og.son_saat
                FROM menuler m
                JOIN ogunler og ON og.id = m.ogun_id
                WHERE m.id = ?
            ");
            $stmtHedef->execute([$duzenle_menu_id]);
            $hedefMenu = $stmtHedef->fetch();

            if (!$hedefMenu) {
                $hata = "Düzenlenecek menü bulunamadı.";
            } else {
                $dbPrimary->beginTransaction();

                $stmtUp = $dbPrimary->prepare("
                    UPDATE menuler
                    SET ogrenci_fiyati = ?, personel_fiyati = ?
                    WHERE id = ?
                ");
                $stmtUp->execute([$ogrenci_fiyat, $personel_fiyat, $duzenle_menu_id]);

                $stmtSil = $dbPrimary->prepare("DELETE FROM menu_yemekleri WHERE menu_id = ?");
                $stmtSil->execute([$duzenle_menu_id]);

                if (!empty($secilen_yemekler)) {
                    $stmtEk = $dbPrimary->prepare("INSERT IGNORE INTO menu_yemekleri (menu_id, yemek_id) VALUES (?, ?)");
                    foreach ($secilen_yemekler as $yemek_id) {
                        if ($yemek_id > 0) {
                            $stmtEk->execute([$duzenle_menu_id, $yemek_id]);
                        }
                    }
                }

                $dbPrimary->commit();

                redisMenuSil(
                    (int)($hedefMenu['yemekhane_id'] ?? 0),
                    (string)($hedefMenu['tarih'] ?? ''),
                    (int)($hedefMenu['ogun_id'] ?? 0)
                );
                menuOnbellekTemizle();

                $mesaj = "Menü (#{$duzenle_menu_id}) başarıyla güncellendi ve önbellek temizlendi!";
            }
        } catch (Throwable $e) {
            if (isset($dbPrimary) && $dbPrimary->inTransaction()) {
                $dbPrimary->rollBack();
            }
            $hata = "Menü güncellenirken hata oluştu: " . temizSqlHatasi($e);
        }
    }
}

$yemekhaneler = [];
$ogunler = [];
$tumYemekler = [];
$menulerRapor = [];
$duzenlenecekMenu = null;
$duzenlenecekYemekIdler = [];

try {
    $dbReplica = getReplicaConnection();
    $yemekhaneler = $dbReplica->query("SELECT id, ad, kapasite FROM yemekhaneler ORDER BY id")->fetchAll();
    $ogunler = $dbReplica->query("SELECT id, ad, son_saat FROM ogunler ORDER BY sira")->fetchAll();
    $tumYemekler = $dbReplica->query("
        SELECT y.id, y.ad, y.kalori, k.ad AS kategori_adi
        FROM yemekler y
        JOIN yemek_kategorileri k ON k.id = y.kategori_id
        ORDER BY k.sira, y.ad
    ")->fetchAll();

    $menulerRapor = yoneticiRaporGetir();

    $duzenleId = isset($_GET['duzenle']) ? (int)$_GET['duzenle'] : 0;
    if ($duzenleId > 0) {
        $stmtD = $dbReplica->prepare("
            SELECT m.*, og.son_saat, og.ad AS ogun_adi
            FROM menuler m
            JOIN ogunler og ON og.id = m.ogun_id
            WHERE m.id = ?
        ");
        $stmtD->execute([$duzenleId]);
        $adayMenu = $stmtD->fetch();

        if ($adayMenu) {
            $duzenlenecekMenu = $adayMenu;
            $stmtDY = $dbReplica->prepare("SELECT yemek_id FROM menu_yemekleri WHERE menu_id = ?");
            $stmtDY->execute([$duzenleId]);
            $duzenlenecekYemekIdler = array_map('intval', $stmtDY->fetchAll(PDO::FETCH_COLUMN));
        }
    }
} catch (Throwable $e) {
    $hata = $hata ?: ("Veriler yüklenemedi: " . temizSqlHatasi($e));
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yönetici Paneli - Menü Yönetimi</title>
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
            max-width: 950px;
            margin: auto;
            background: #14151a;
            padding: 28px;
            border-radius: 16px;
            border: 1px solid #262833;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.45);
        }
        h2 {
            font-size: 23px;
            font-weight: 700;
            margin: 0 0 22px 0;
            border-left: 5px solid #ec4899;
            padding-left: 14px;
            color: #ffffff;
        }
        h3 {
            margin: 0 0 12px 0;
            font-size: 17px;
            color: #ffffff;
        }
        form {
            background: #1b1d25;
            border: 1px solid #2a2d3a;
            padding: 20px;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
        }
        .form-group { margin-bottom: 16px; }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
            color: #e5e7eb;
            font-size: 13.5px;
        }
        input, select {
            width: 100%;
            padding: 10px 12px;
            box-sizing: border-box;
            border: 1px solid #353949;
            background: #121318;
            color: #f9fafb;
            border-radius: 8px;
            font-size: 13.5px;
        }
        .fiyat-satir {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }
        .yemek-secim-box {
            max-height: 175px;
            overflow-y: auto;
            border: 1px solid #2a2d3a;
            padding: 12px;
            border-radius: 10px;
            background: #13151b;
        }
        .yemek-secim-box label {
            font-weight: normal;
            display: inline-block;
            margin-right: 12px;
            margin-bottom: 6px;
            color: #dddddd;
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
            margin-top: 8px;
        }
        button:hover { opacity: 0.92; }
        .btn-duzenle {
            background: #222530;
            color: #f472b6;
            border: 1px solid #353949;
            padding: 6px 11px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .btn-duzenle:hover {
            background: linear-gradient(135deg, #c2185b, #e91e63);
            color: #ffffff;
            border-color: transparent;
        }
        .tablo-sar { overflow-x: auto; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
            background: #1b1d25;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #2a2d3a;
        }
        th, td {
            border: 1px solid #262936;
            padding: 11px 13px;
            text-align: left;
            font-size: 13px;
            color: #e5e7eb;
        }
        th {
            background-color: #222530;
            color: #f9a8d4;
            font-weight: 600;
        }
        tr:nth-child(even) td {
            background-color: #161820;
        }
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
        .duzenle-kutu {
            background: #1b1d25;
            border: 1px solid #ec4899;
            padding: 20px;
            border-radius: 14px;
            margin-bottom: 24px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
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
            <?php if ($aktifRol === 'yonetici'): ?>
                <a href="yonetici.php">👑 Yönetici Paneli</a>
            <?php endif; ?>
            <a href="index.php?cikis=1">🚪 Çıkış Yap</a>
        </div>

        <h2>Yönetici Paneli - Menü Yönetimi ve Raporlar</h2>

        <?php if ($mesaj !== ''): ?><div class="basari"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata !== ''): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <?php if ($duzenlenecekMenu): ?>
            <div class="duzenle-kutu">
                <h3>Menü Düzenle (#<?= (int)$duzenlenecekMenu['id'] ?> — <?= htmlspecialchars($duzenlenecekMenu['tarih']) ?> / <?= htmlspecialchars($duzenlenecekMenu['ogun_adi']) ?>)</h3>
                <form method="POST">
                    <input type="hidden" name="duzenle_menu_id" value="<?= (int)$duzenlenecekMenu['id'] ?>">
                    <div class="fiyat-satir form-group">
                        <div>
                            <label>Öğrenci Fiyatı (TL):</label>
                            <input type="number" step="0.01" name="ogrenci_fiyati" value="<?= htmlspecialchars($duzenlenecekMenu['ogrenci_fiyati']) ?>" required>
                        </div>
                        <div>
                            <label>Personel Fiyatı (TL):</label>
                            <input type="number" step="0.01" name="personel_fiyati" value="<?= htmlspecialchars($duzenlenecekMenu['personel_fiyati']) ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Menüdeki Yemekler:</label>
                        <div class="yemek-secim-box">
                            <?php foreach ($tumYemekler as $yemek): ?>
                                <label>
                                    <input type="checkbox" name="duzenle_yemekler[]" value="<?= (int)$yemek['id'] ?>"
                                        <?= in_array((int)$yemek['id'], $duzenlenecekYemekIdler, true) ? 'checked' : '' ?>>
                                    <?= htmlspecialchars($yemek['ad']) ?> (<?= (int)$yemek['kalori'] ?> kcal)
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button type="submit" name="menu_guncelle">Değişiklikleri Kaydet</button>
                    <a href="yonetici.php" class="btn-duzenle" style="margin-left:8px;">İptal</a>
                </form>
            </div>
        <?php endif; ?>

        <h3>Yeni Menü Oluştur</h3>
        <form method="POST">
            <div class="form-group">
                <label>Yemekhane:</label>
                <select name="yemekhane_id" required>
                    <option value="">-- Yemekhane Seçin --</option>
                    <?php foreach ($yemekhaneler as $y): ?>
                        <option value="<?= (int)$y['id'] ?>"><?= htmlspecialchars($y['ad']) ?> (Kapasite: <?= (int)$y['kapasite'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Öğün:</label>
                <select name="ogun_id" required>
                    <option value="">-- Öğün Seçin --</option>
                    <?php foreach ($ogunler as $og): ?>
                        <option value="<?= (int)$og['id'] ?>"><?= htmlspecialchars($og['ad']) ?> (Son Saat: <?= htmlspecialchars(substr((string)$og['son_saat'], 0, 5)) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Tarih:</label>
                <input type="date" name="tarih" required>
            </div>

            <div class="fiyat-satir form-group">
                <div>
                    <label>Öğrenci Fiyatı (TL):</label>
                    <input type="number" step="0.01" name="ogrenci_fiyati" value="30.00" required>
                </div>
                <div>
                    <label>Personel Fiyatı (TL):</label>
                    <input type="number" step="0.01" name="personel_fiyati" value="45.00" required>
                </div>
            </div>

            <div class="form-group">
                <label>Bu Menüde Çıkacak Yemekler:</label>
                <div class="yemek-secim-box">
                    <?php foreach ($tumYemekler as $yemek): ?>
                        <label>
                            <input type="checkbox" name="yemekler[]" value="<?= (int)$yemek['id'] ?>">
                            <?= htmlspecialchars($yemek['ad']) ?> (<?= (int)$yemek['kalori'] ?> kcal)
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" name="menu_ekle">Menüyü ve Yemekleri Kaydet</button>
        </form>

        <h3 style="margin-top: 30px;">Menü Doluluk ve Ortalama Puan Raporu</h3>
        <div class="tablo-sar">
            <table>
                <tr>
                    <th>Tarih</th>
                    <th>Yemekhane</th>
                    <th>Öğün</th>
                    <th>Doluluk</th>
                    <th>Ort. Puan</th>
                    <th>İşlem</th>
                </tr>
                <?php if (empty($menulerRapor)): ?>
                    <tr><td colspan="6">Henüz eklenmiş bir menü bulunmuyor.</td></tr>
                <?php else: ?>
                    <?php foreach ($menulerRapor as $rapor): ?>
                        <tr>
                            <td><?= htmlspecialchars($rapor['tarih']) ?></td>
                            <td><?= htmlspecialchars($rapor['yemekhane']) ?></td>
                            <td><?= htmlspecialchars($rapor['ogun']) ?></td>
                            <td><?= (int)$rapor['rezerve'] ?> / <?= (int)$rapor['kapasite'] ?> (<?= htmlspecialchars($rapor['doluluk_yuzde']) ?>%)</td>
                            <td>
                                <?php if ($rapor['ortalama_puan'] !== null): ?>
                                    <?= htmlspecialchars($rapor['ortalama_puan']) ?> / 5 (<?= (int)$rapor['oy_sayisi'] ?> oy)
                                <?php else: ?>
                                    Oy yok
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($rapor['duzenlenebilir'])): ?>
                                    <a href="yonetici.php?duzenle=<?= (int)$rapor['menu_id'] ?>" class="btn-duzenle">Düzenle</a>
                                <?php else: ?>
                                    <span style="color: #777;">Süresi Doldu</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>
</body>
</html>