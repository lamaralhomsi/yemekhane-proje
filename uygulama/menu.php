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

$kullaniciAlerjenleri = kullaniciAlerjenleriniGetir($kullanici_id);
$alerjenEtiketleri = gecerliAlerjenListesi();

$seciliTarih = $_GET['tarih'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $seciliTarih)) {
    $seciliTarih = date('Y-m-d');
}

$menuler = [];
$hata = "";

try {
    $tumSatirlar = haftalikMenuleriGetir();
    $gunSatirlari = array_values(array_filter($tumSatirlar, fn($r) => (string)($r['tarih'] ?? '') === $seciliTarih));
    $menuIds = array_map(fn($r) => (int)$r['menu_id'], $gunSatirlari);
    $yemekGruplari = menulerinYemekleriniGetir($menuIds);

    foreach ($gunSatirlari as $row) {
        $mId = (int)$row['menu_id'];
        $ogunAdi = (string)($row['ogun'] ?? '');
        $kahvaltiMi = (stripos($ogunAdi, 'kahvalt') !== false);
        $yemekler = $yemekGruplari[$mId] ?? [];
        $toplamKalori = 0;
        $uyarilar = [];

        foreach ($yemekler as &$y) {
            $toplamKalori += (int)($y['kalori'] ?? 0);
            $hamAlerjen = strtolower((string)($y['alerjenler'] ?? ''));
            $parcalar = array_filter(array_map('trim', explode(',', $hamAlerjen)));
            $eslesen = [];
            foreach ($parcalar as $p) {
                if (in_array($p, $kullaniciAlerjenleri, true)) {
                    $etiket = $alerjenEtiketleri[$p] ?? $p;
                    $eslesen[] = $etiket;
                    $uyarilar[$p] = $etiket;
                }
            }
            $y['eslesen_alerjenler'] = array_values(array_unique($eslesen));
        }
        unset($y);

        $menuler[] = [
            'id' => $mId,
            'tarih' => (string)$row['tarih'],
            'yemekhane' => (string)($row['yemekhane'] ?? ''),
            'ogun' => $ogunAdi,
            'kahvalti_mi' => $kahvaltiMi,
            'son_saat' => substr((string)($row['son_saat'] ?? '00:00:00'), 0, 5),
            'ogrenci_fiyati' => (float)($row['ogrenci_fiyati'] ?? 0),
            'personel_fiyati' => (float)($row['personel_fiyati'] ?? 0),
            'bos_yer' => max(0, (int)($row['bos_yer'] ?? 0)),
            'kapasite' => (int)($row['kapasite'] ?? 0),
            'toplam_kalori' => $toplamKalori,
            'yemekler' => $yemekler,
            'uyarilar' => array_values($uyarilar),
        ];
    }
} catch (Throwable $e) {
    $hata = "Menüler yüklenemedi: " . temizSqlHatasi($e);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yemekhane - Günün Menüsü</title>
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
        .header-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 22px;
        }
        .page-title {
            font-size: 23px;
            font-weight: 700;
            margin: 0;
            border-left: 5px solid #ec4899;
            padding-left: 14px;
            color: #ffffff;
        }
        .filtre-form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            background: #1b1d24;
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #2b2e3b;
        }
        input[type="date"] {
            padding: 8px 12px;
            background: #121318;
            color: #f9fafb;
            border: 1px solid #353949;
            border-radius: 8px;
            font-size: 13px;
        }
        button {
            background: linear-gradient(135deg, #c2185b, #e91e63);
            color: #ffffff;
            border: none;
            padding: 9px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
        }
        button:hover { opacity: 0.92; }
        .menu-card {
            background: linear-gradient(180deg, #1b1d25 0%, #171920 100%);
            border: 1px solid #2a2d3a;
            padding: 22px;
            margin-bottom: 20px;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
        }
        .menu-top {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .menu-top h3 {
            margin: 0;
            font-size: 18px;
            color: #ffffff;
        }
        .saat-rozet {
            background: rgba(236, 72, 153, 0.14);
            color: #f9a8d4;
            border: 1px solid rgba(236, 72, 153, 0.35);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .meta-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
        }
        .meta-chip {
            background: #222530;
            border: 1px solid #303444;
            color: #e5e7eb;
            padding: 7px 12px;
            border-radius: 9px;
            font-size: 13px;
        }
        .meta-chip strong { color: #34d399; }
        .meta-chip.yarim strong { color: #fbbf24; }
        .menu-alerjen-banner {
            background: rgba(244, 63, 94, 0.14);
            border: 1px solid rgba(244, 63, 94, 0.45);
            color: #fda4af;
            padding: 12px 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            font-size: 13.5px;
            font-weight: 600;
        }
        .yemek-listesi {
            list-style: none;
            margin: 0 0 18px 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }
        .yemek-item {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            background: #13151b;
            border: 1px solid #252834;
            padding: 10px 14px;
            border-radius: 10px;
        }
        .yemek-sol {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }
        .yemek-ad {
            color: #f3f4f6;
            font-weight: 500;
            font-size: 14px;
        }
        .kalori-etiket {
            color: #9ca3af;
            font-size: 12.5px;
            background: #1d2029;
            padding: 3px 9px;
            border-radius: 999px;
        }
        .alerjen-uyari {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            background: rgba(244, 63, 94, 0.2);
            border: 1px solid rgba(244, 63, 94, 0.55);
            color: #fda4af;
            font-size: 11.5px;
            font-weight: 600;
        }
        .menu-alt {
            display: flex;
            justify-content: flex-end;
            padding-top: 8px;
            border-top: 1px solid #262936;
        }
        .toplam-kalori {
            color: #f472b6;
            background: rgba(236, 72, 153, 0.12);
            border: 1px solid rgba(236, 72, 153, 0.28);
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 13px;
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
        .bos-durum {
            text-align: center;
            padding: 28px;
            background: #1a1c24;
            border-radius: 12px;
            border: 1px dashed #2f3342;
            color: #9ca3af;
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

        <div class="header-row">
            <h2 class="page-title">Günün Menüsü (<?= htmlspecialchars($seciliTarih) ?>)</h2>
            <form method="GET" class="filtre-form">
                <input type="date" name="tarih" value="<?= htmlspecialchars($seciliTarih) ?>">
                <button type="submit">Tarihi Göster</button>
            </form>
        </div>

        <?php if ($hata !== ''): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <?php if (empty($menuler)): ?>
            <div class="bos-durum">Seçili tarih için menü bulunamadı.</div>
        <?php else: ?>
            <?php foreach ($menuler as $m): ?>
                <div class="menu-card">
                    <div class="menu-top">
                        <h3><?= htmlspecialchars($m['yemekhane']) ?> — <?= htmlspecialchars($m['ogun']) ?></h3>
                        <span class="saat-rozet">⏰ Son Saat: <?= htmlspecialchars($m['son_saat']) ?></span>
                    </div>

                    <?php if (!empty($m['uyarilar'])): ?>
                        <div class="menu-alerjen-banner">
                            ⚠️ Alerjen Uyarısı: Seçtiğiniz alerjenler (<?= htmlspecialchars(implode(', ', $m['uyarilar'])) ?>) bu menüde bulunuyor!
                        </div>
                    <?php endif; ?>

                    <div class="meta-bar">
                        <div class="meta-chip">Öğrenci: <strong><?= number_format($m['ogrenci_fiyati'], 2) ?> TL</strong></div>
                        <div class="meta-chip">Personel: <strong><?= number_format($m['personel_fiyati'], 2) ?> TL</strong></div>
                        <?php if (!$m['kahvalti_mi']): ?>
                            <div class="meta-chip yarim">Yarım Porsiyon: <strong>%60</strong></div>
                        <?php else: ?>
                            <div class="meta-chip">Porsiyon: <strong>Yalnızca Tam Porsiyon</strong></div>
                        <?php endif; ?>
                        <div class="meta-chip">Kalan Yer: <strong><?= (int)$m['bos_yer'] ?> / <?= (int)$m['kapasite'] ?></strong></div>
                    </div>

                    <ul class="yemek-listesi">
                        <?php foreach ($m['yemekler'] as $y): ?>
                            <li class="yemek-item">
                                <div class="yemek-sol">
                                    <span class="yemek-ad"><?= htmlspecialchars($y['yemek']) ?></span>
                                    <?php if (!empty($y['eslesen_alerjenler'])): ?>
                                        <span class="alerjen-uyari">⚠️ Alerjen Uyarısı: <?= htmlspecialchars(implode(', ', $y['eslesen_alerjenler'])) ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="kalori-etiket"><?= (int)$y['kalori'] ?> kcal</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="menu-alt">
                        <span class="toplam-kalori">Toplam Kalori: <?= (int)$m['toplam_kalori'] ?> kcal</span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>