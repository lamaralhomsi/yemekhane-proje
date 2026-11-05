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

if (isset($_GET['iptal_id'])) {
    $rez_id = (int)$_GET['iptal_id'];
    try {
        $dbPrimary = getPrimaryConnection();
        $stmtIptal = $dbPrimary->prepare("CALL sp_rezervasyon_iptal(?, ?)");
        $stmtIptal->execute([$kullanici_id, $rez_id]);
        $sonuc = $stmtIptal->fetch();
        $stmtIptal->closeCursor();
        menuOnbellekTemizle();
        $iade = isset($sonuc['iade']) ? number_format((float)$sonuc['iade'], 2) : '0.00';
        $bakiye = isset($sonuc['bakiye']) ? number_format((float)$sonuc['bakiye'], 2) : '0.00';
        $mesaj = "Rezervasyon iptal edildi. İade: {$iade} TL | Güncel Bakiye: {$bakiye} TL";
    } catch (Throwable $e) {
        $hata = temizSqlHatasi($e);
    }
}

$rezervasyonlar = [];
try {
    $dbReplica = getReplicaConnection();
    $stmt = $dbReplica->prepare("
        SELECT r.id AS rezervasyon_id, m.tarih, og.son_saat, yh.ad AS yemekhane_adi, og.ad AS ogun_adi,
               r.durum, r.ucret, COALESCE(r.porsiyon, 'tam') AS porsiyon,
               (NOW() < TIMESTAMP(m.tarih, og.son_saat)) AS iptal_edilebilir
        FROM rezervasyonlar r
        JOIN menuler m ON r.menu_id = m.id
        JOIN yemekhaneler yh ON m.yemekhane_id = yh.id
        JOIN ogunler og ON m.ogun_id = og.id
        WHERE r.kullanici_id = ?
        ORDER BY m.tarih DESC, og.sira DESC
    ");
    $stmt->execute([$kullanici_id]);
    $hamListe = $stmt->fetchAll();

    $simdi = time();
    foreach ($hamListe as $r) {
        $sonZaman = strtotime((string)$r['tarih'] . ' ' . (string)$r['son_saat']);
        $gecmisMi = ($sonZaman !== false && $simdi >= $sonZaman) || ((int)$r['iptal_edilebilir'] === 0);
        $r['gecmis_mi'] = $gecmisMi;
        $r['iptal_edilebilir'] = ($r['durum'] === 'aktif' && !$gecmisMi);
        $rezervasyonlar[] = $r;
    }
} catch (Throwable $e) {
    $hata = "Rezervasyonlar yüklenemedi: " . temizSqlHatasi($e);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yemekhane - Rezervasyonlarım</title>
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
        .page-title {
            font-size: 23px;
            font-weight: 700;
            margin-bottom: 22px;
            border-left: 5px solid #ec4899;
            padding-left: 14px;
            color: #ffffff;
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
        .badge-aktif { color: #34d399; font-weight: 700; }
        .badge-kullanildi { color: #fbbf24; font-weight: 700; }
        .badge-iptal { color: #f87171; font-weight: 700; }
        .badge-gecmis { color: #9ca3af; font-weight: 700; }
        .btn-iptal {
            background: linear-gradient(135deg, #c2185b, #e91e63);
            color: #ffffff;
            padding: 6px 12px;
            text-decoration: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        .btn-iptal:hover { opacity: 0.92; }
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

        <div class="page-title">Rezervasyonlarım</div>

        <?php if ($mesaj !== ''): ?><div class="basari"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata !== ''): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <table>
            <tr>
                <th>Tarih</th>
                <th>Yemekhane</th>
                <th>Öğün</th>
                <th>Porsiyon</th>
                <th>Ücret</th>
                <th>Durum</th>
                <th>İşlem</th>
            </tr>
            <?php if (empty($rezervasyonlar)): ?>
                <tr><td colspan="7">Henüz rezervasyonunuz bulunmuyor.</td></tr>
            <?php else: ?>
                <?php foreach ($rezervasyonlar as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['tarih']) ?></td>
                        <td><?= htmlspecialchars($r['yemekhane_adi']) ?></td>
                        <td><?= htmlspecialchars($r['ogun_adi']) ?></td>
                        <td><?= (($r['porsiyon'] ?? 'tam') === 'yarim') ? 'Yarım Porsiyon' : 'Tam Porsiyon' ?></td>
                        <td><?= number_format((float)$r['ucret'], 2) ?> TL</td>
                        <td>
                            <?php if ($r['durum'] === 'aktif' && !$r['gecmis_mi']): ?>
                                <span class="badge-aktif">● Aktif</span>
                            <?php elseif ($r['durum'] === 'aktif' && $r['gecmis_mi']): ?>
                                <span class="badge-gecmis">● Süresi Geçti</span>
                            <?php elseif ($r['durum'] === 'kullanildi'): ?>
                                <span class="badge-kullanildi">● Kullanıldı</span>
                            <?php else: ?>
                                <span class="badge-iptal">● İptal</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['iptal_edilebilir']): ?>
                                <a href="rezervasyonlarim.php?iptal_id=<?= (int)$r['rezervasyon_id'] ?>" class="btn-iptal" onclick="return confirm('İptal etmek istediğinize emin misiniz?');">İptal Et</a>
                            <?php else: ?>
                                <span style="color: #666;">İşlem Yapılamaz</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>