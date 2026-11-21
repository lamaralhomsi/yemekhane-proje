<?php
require_once __DIR__ . '/baglanti.php';
redisOturumBaslat();
require_once __DIR__ . '/veritabani_islemleri.php';

if (!isset($_SESSION['kullanici_id']) || (int)$_SESSION['kullanici_id'] <= 0) {
    header("Location: index.php");
    exit;
}

$mesaj = "";
$hata = "";
$kullanici_id = (int)$_SESSION['kullanici_id'];
$kullaniciRol = strtolower(trim((string)kullaniciRolAl($kullanici_id)));
$_SESSION['rol'] = $kullaniciRol;
$_SESSION['kullanici_rol'] = $kullaniciRol;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['menu_id'])) {
    $menu_id = (int)$_POST['menu_id'];
    $porsiyon = strtolower(trim((string)($_POST['porsiyon'] ?? 'tam')));
    if (!in_array($porsiyon, ['tam', 'yarim'], true)) {
        $porsiyon = 'tam';
    }

    try {
        $sonuc = rezervasyonYapVeBakiyeOku($kullanici_id, $menu_id, $porsiyon);
        $ucret = number_format((float)($sonuc['ucret'] ?? 0), 2);
        $bakiye = number_format((float)($sonuc['guncel_bakiye'] ?? 0), 2);
        $porsiyonEtiket = (($sonuc['porsiyon'] ?? $porsiyon) === 'yarim') ? 'Yarım Porsiyon (%60)' : 'Tam Porsiyon';
        $mesaj = "Rezervasyonunuz oluşturuldu! ({$porsiyonEtiket}) Ücret: {$ucret} TL | Güncel Bakiye (Kaynak): {$bakiye} TL";
    } catch (Throwable $e) {
        $hata = temizSqlHatasi($e);
    }
}

$menuler = [];
$aktifRezervasyonlar = [];
$kullaniciAlerjenleri = kullaniciAlerjenleriniGetir($kullanici_id);
$alerjenEtiketleri = gecerliAlerjenListesi();

try {
    $kullaniciRol = strtolower(trim((string)kullaniciRolAl($kullanici_id)));
    $_SESSION['rol'] = $kullaniciRol;
    $_SESSION['kullanici_rol'] = $kullaniciRol;

    $dolulukSatirlari = haftalikMenuleriGetir();
    $menuIds = array_map(fn($r) => (int)$r['menu_id'], $dolulukSatirlari);
    $yemekGruplari = menulerinYemekleriniGetir($menuIds);

    $gunIsimleri = [
        'Monday' => 'Pazartesi', 'Tuesday' => 'Salı', 'Wednesday' => 'Çarşamba',
        'Thursday' => 'Perşembe', 'Friday' => 'Cuma', 'Saturday' => 'Cumartesi', 'Sunday' => 'Pazar'
    ];

    foreach ($dolulukSatirlari as $row) {
        $mId = (int)$row['menu_id'];
        $ogunAdi = (string)($row['ogun'] ?? '');
        $kahvaltiMi = (stripos($ogunAdi, 'kahvalt') !== false);
        $tamFiyat = ($kullaniciRol === 'ogrenci') ? (float)$row['ogrenci_fiyati'] : (float)$row['personel_fiyati'];
        $yarimFiyat = round($tamFiyat * 0.60, 2);

        $turkishDay = $gunIsimleri[date('l', strtotime($row['tarih']))] ?? '';
        $yemekler = $yemekGruplari[$mId] ?? [];
        $toplamKalori = 0;
        $menuEslesenAlerjenler = [];

        foreach ($yemekler as &$y) {
            $toplamKalori += (int)($y['kalori'] ?? 0);
            $hamAlerjen = strtolower((string)($y['alerjenler'] ?? ''));
            $parcalar = array_filter(array_map('trim', explode(',', $hamAlerjen)));
            $eslesen = [];
            foreach ($parcalar as $p) {
                if (in_array($p, $kullaniciAlerjenleri, true)) {
                    $eslesen[] = $alerjenEtiketleri[$p] ?? $p;
                    $menuEslesenAlerjenler[$p] = $alerjenEtiketleri[$p] ?? $p;
                }
            }
            $y['eslesen_alerjenler'] = array_values(array_unique($eslesen));
            $y['tum_alerjenler'] = array_values(array_unique($parcalar));
        }
        unset($y);

        $menuler[] = [
            'id' => $mId,
            'formatli_tarih' => date('d.m.Y', strtotime($row['tarih'])) . ($turkishDay ? " - {$turkishDay}" : ''),
            'yemekhane_adi' => (string)($row['yemekhane'] ?? ''),
            'ogun_adi' => $ogunAdi,
            'kahvalti_mi' => $kahvaltiMi,
            'son_saat' => substr((string)($row['son_saat'] ?? '00:00:00'), 0, 5),
            'sure_doldu' => (bool)$row['sure_doldu'],
            'kapasite' => (int)$row['kapasite'],
            'bos_yer' => max(0, (int)$row['bos_yer']),
            'tam_fiyat' => $tamFiyat,
            'yarim_fiyat' => $yarimFiyat,
            'yemekler' => $yemekler,
            'toplam_kalori' => $toplamKalori,
            'menu_alerjen_uyarisi' => array_values($menuEslesenAlerjenler)
        ];
    }

    $aktifRezervasyonlar = aktifRezervasyonMenuIdleri($kullanici_id);
} catch (Throwable $e) {
    $hata = $hata ?: ("Menüler yüklenemedi: " . temizSqlHatasi($e));
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yemekhane - Haftalık Menü ve Rezervasyon</title>
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
        .menu-card {
            background: linear-gradient(180deg, #1b1d25 0%, #171920 100%);
            border: 1px solid #2a2d3a;
            padding: 22px;
            margin-bottom: 20px;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
        }
        .menu-card h3 {
            margin: 0 0 12px 0;
            font-size: 18px;
            color: #ffffff;
        }
        .detay-rozet {
            background: #222530;
            border: 1px solid #303444;
            padding: 12px 14px;
            border-radius: 10px;
            margin: 12px 0 16px 0;
            display: flex;
            flex-wrap: wrap;
            gap: 10px 16px;
            font-size: 13px;
            color: #e5e7eb;
        }
        .detay-rozet span {
            color: #f472b6;
            font-weight: 700;
        }
        .yemek-listesi {
            list-style: none;
            margin: 0 0 16px 0;
            padding: 0;
            display: grid;
            gap: 8px;
            color: #dddddd;
        }
        .yemek-listesi li {
            margin-bottom: 0;
            background: #13151b;
            border: 1px solid #252834;
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 14px;
            color: #f3f4f6;
        }
        .alerjen-uyari {
            display: inline-block;
            margin-left: 8px;
            padding: 3px 9px;
            border-radius: 999px;
            background: rgba(244, 63, 94, 0.2);
            border: 1px solid rgba(244, 63, 94, 0.55);
            color: #fda4af;
            font-size: 11.5px;
            font-weight: 600;
        }
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
        .porsiyon-secim {
            margin-top: 14px;
            padding: 12px 14px;
            background: #222530;
            border-radius: 10px;
            border: 1px solid #303444;
            font-size: 13px;
            color: #e5e7eb;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 16px;
        }
        .porsiyon-secim label {
            margin-right: 0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .bilgi-kutu {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }
        .toplam-kalori, .bos-yer {
            font-weight: 700;
            padding: 7px 12px;
            border-radius: 8px;
            font-size: 13px;
        }
        .toplam-kalori {
            color: #f472b6;
            background: rgba(236, 72, 153, 0.12);
            border: 1px solid rgba(236, 72, 153, 0.28);
        }
        .bos-yer {
            color: #34d399;
            background: rgba(52, 211, 153, 0.12);
            border: 1px solid rgba(52, 211, 153, 0.28);
        }
        button {
            background: linear-gradient(135deg, #c2185b, #e91e63);
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            margin-top: 14px;
        }
        button:hover { opacity: 0.92; }
        .pasif-btn {
            background: #252834;
            color: #8b90a3;
            border: 1px solid #323646;
            cursor: not-allowed;
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
            <?php if ($kullaniciRol === 'yonetici'): ?>
                <a href="yonetici.php">👑 Yönetici Paneli</a>
            <?php endif; ?>
            <a href="index.php?cikis=1">🚪 Çıkış Yap</a>
        </div>

        <div class="page-title">Haftalık Menü ve Rezervasyon Yönetimi</div>

        <?php if ($mesaj !== ''): ?><div class="basari"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata !== ''): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <?php if (empty($menuler)): ?>
            <p>Görüntülenecek menü bulunamadı.</p>
        <?php else: ?>
            <?php foreach ($menuler as $menu): ?>
                <?php
                    $zatenRezerve = in_array($menu['id'], $aktifRezervasyonlar, true);
                    $kontenjanDoldu = ($menu['bos_yer'] <= 0);
                    $pasif = ($menu['sure_doldu'] || $zatenRezerve || $kontenjanDoldu);
                ?>
                <div class="menu-card" style="<?= $pasif ? 'opacity: 0.65; background: #141414;' : '' ?>">
                    <h3>🗓️ Tarih: <?= htmlspecialchars($menu['formatli_tarih']) ?></h3>

                    <?php if (!empty($menu['menu_alerjen_uyarisi'])): ?>
                        <div class="menu-alerjen-banner">
                            ⚠️ Alerjen Uyarısı: Bu menüde seçili alerjenlerinizden (<?= htmlspecialchars(implode(', ', $menu['menu_alerjen_uyarisi'])) ?>) bulunan yemekler var!
                        </div>
                    <?php endif; ?>

                    <div class="detay-rozet">
                        <div>📍 Yemekhane: <span><?= htmlspecialchars($menu['yemekhane_adi']) ?></span></div>
                        <div>🍽️ Öğün: <span><?= htmlspecialchars($menu['ogun_adi']) ?></span></div>
                        <div>🏷️ Tam Fiyat (<?= htmlspecialchars($kullaniciRol) ?>): <span><?= number_format($menu['tam_fiyat'], 2) ?> TL</span></div>
                        <?php if (!$menu['kahvalti_mi']): ?>
                            <div>🥗 Yarım Porsiyon (%60): <span><?= number_format($menu['yarim_fiyat'], 2) ?> TL</span></div>
                        <?php else: ?>
                            <div>☀️ Kahvaltı: <span>Yalnızca Tam Porsiyon</span></div>
                        <?php endif; ?>
                        <div>⏰ Son Saat: <span><?= htmlspecialchars($menu['son_saat']) ?></span></div>
                    </div>

                    <ul class="yemek-listesi">
                        <?php foreach ($menu['yemekler'] as $yemek): ?>
                            <li>
                                <?= htmlspecialchars((string)($yemek['yemek'] ?? '')) ?> (<?= (int)($yemek['kalori'] ?? 0) ?> Kalori)
                                <?php if (!empty($yemek['eslesen_alerjenler'])): ?>
                                    <span class="alerjen-uyari">⚠️ Alerjen Uyarısı: <?= htmlspecialchars(implode(', ', $yemek['eslesen_alerjenler'])) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div class="bilgi-kutu">
                        <span class="toplam-kalori">Toplam Kalori: <?= (int)$menu['toplam_kalori'] ?> kcal</span>
                        <span class="bos-yer">🟢 Kalan Boş Yer: <?= (int)$menu['bos_yer'] ?> / <?= (int)$menu['kapasite'] ?> Kişi</span>
                    </div>

                    <?php if ($zatenRezerve): ?>
                        <button type="button" class="pasif-btn" disabled>✓ Bu Öğün Rezerve Edildi</button>
                    <?php elseif ($menu['sure_doldu']): ?>
                        <button type="button" class="pasif-btn" disabled>⏰ Rezervasyon Süresi Doldu</button>
                    <?php elseif ($kontenjanDoldu): ?>
                        <button type="button" class="pasif-btn" disabled>❌ Yemekhane Kapasitesi Dolu</button>
                    <?php else: ?>
                        <form method="POST">
                            <input type="hidden" name="menu_id" value="<?= (int)$menu['id'] ?>">
                            <div class="porsiyon-secim">
                                <strong>Porsiyon Seçimi:</strong>
                                <label>
                                    <input type="radio" name="porsiyon" value="tam" checked>
                                    Tam Porsiyon (<?= number_format($menu['tam_fiyat'], 2) ?> TL)
                                </label>
                                <?php if (!$menu['kahvalti_mi']): ?>
                                    <label>
                                        <input type="radio" name="porsiyon" value="yarim">
                                        Yarım Porsiyon - %60 (<?= number_format($menu['yarim_fiyat'], 2) ?> TL)
                                    </label>
                                <?php endif; ?>
                            </div>
                            <button type="submit">Rezervasyon Yap</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>