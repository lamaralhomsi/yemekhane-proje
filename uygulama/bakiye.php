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
$kullanici_rol = strtolower(trim((string)kullaniciRolAl($kullanici_id)));
$_SESSION['rol'] = $kullanici_rol;
$_SESSION['kullanici_rol'] = $kullanici_rol;
$sonYazmaOldu = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tutar'])) {
    $tutar = (float)$_POST['tutar'];
    try {
        $dbPrimary = getPrimaryConnection();
        $stmtProc = $dbPrimary->prepare("CALL sp_bakiye_yukle(?, ?)");
        $stmtProc->execute([$kullanici_id, $tutar]);
        $sonuc = $stmtProc->fetch();
        $stmtProc->closeCursor();
        $yeniBakiye = isset($sonuc['bakiye']) ? number_format((float)$sonuc['bakiye'], 2) : '';
        $mesaj = "Başarıyla " . number_format($tutar, 2) . " TL bakiye yüklendi!" . ($yeniBakiye !== '' ? " Güncel Bakiye: {$yeniBakiye} TL" : '');
        $sonYazmaOldu = true;
    } catch (Throwable $e) {
        $hata = temizSqlHatasi($e);
    }
}

$guncelBakiye = 0.0;
$hareketler = [];

try {
    $dbOkuma = $sonYazmaOldu ? getPrimaryConnection() : getReplicaConnection();
    $stmtKullanici = $dbOkuma->prepare("SELECT bakiye FROM kullanicilar WHERE id = ?");
    $stmtKullanici->execute([$kullanici_id]);
    $kullanici = $stmtKullanici->fetch();
    $guncelBakiye = (float)($kullanici['bakiye'] ?? 0);

    $dbReplica = getReplicaConnection();
    $stmtHareket = $dbReplica->prepare("
        SELECT id, tur, tutar, aciklama, olusturma
        FROM bakiye_hareketleri
        WHERE kullanici_id = ?
        ORDER BY id DESC
        LIMIT 15
    ");
    $stmtHareket->execute([$kullanici_id]);
    $hareketler = $stmtHareket->fetchAll();
} catch (Throwable $e) {
    $hata = $hata ?: ("Veriler yüklenemedi: " . temizSqlHatasi($e));
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yemekhane - Bakiye İşlemleri</title>
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
        .bakiye-kutu {
            background: linear-gradient(180deg, #1b1d25 0%, #171920 100%);
            border: 1px solid #2a2d3a;
            padding: 20px 22px;
            border-radius: 14px;
            margin-bottom: 22px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
        }
        .bakiye-kutu h3 {
            margin: 0;
            font-size: 16px;
            color: #e5e7eb;
        }
        .bakiye-miktar {
            font-size: 24px;
            font-weight: 700;
            color: #34d399;
            background: rgba(52, 211, 153, 0.12);
            border: 1px solid rgba(52, 211, 153, 0.28);
            padding: 6px 14px;
            border-radius: 10px;
        }
        form {
            background: #1b1d25;
            border: 1px solid #2a2d3a;
            padding: 18px;
            border-radius: 14px;
        }
        input[type="number"] {
            width: 100%;
            padding: 12px;
            box-sizing: border-box;
            background: #121318;
            color: #f9fafb;
            border: 1px solid #353949;
            border-radius: 8px;
            font-size: 14px;
        }
        button {
            background: linear-gradient(135deg, #c2185b, #e91e63);
            color: #ffffff;
            border: none;
            padding: 11px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13.5px;
            margin-top: 12px;
        }
        button:hover { opacity: 0.92; }
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
            <?php if ($kullanici_rol === 'yonetici'): ?>
                <a href="yonetici.php">👑 Yönetici Paneli</a>
            <?php endif; ?>
            <a href="index.php?cikis=1">🚪 Çıkış Yap</a>
        </div>

        <h2>Bakiye İşlemleri ve Cüzdan</h2>

        <?php if ($mesaj): ?><div class="basari"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <div class="bakiye-kutu">
            <h3>Mevcut Bakiyeniz:</h3>
            <div class="bakiye-miktar"><?= number_format($guncelBakiye, 2) ?> TL</div>
        </div>

        <h3>Bakiye Yükle</h3>
        <form method="POST">
            <input type="number" name="tutar" step="0.01" placeholder="Örn: 100 (10 - 1000 TL)" required>
            <button type="submit">Bakiye Yükle</button>
        </form>

        <h3 style="margin-top: 30px;">Son Bakiye Hareketleri</h3>
        <table>
            <tr>
                <th>İşlem ID</th>
                <th>Tutar</th>
                <th>Tür / Açıklama</th>
            </tr>
            <?php if (empty($hareketler)): ?>
                <tr><td colspan="3">Henüz bakiye hareketiniz bulunmuyor.</td></tr>
            <?php else: ?>
                <?php foreach ($hareketler as $h): ?>
                    <?php
                        $tutarVal = (float)$h['tutar'];
                        $islemTuru = strtolower((string)($h['tur'] ?? ''));
                        $isaret = ($tutarVal >= 0) ? '+' : '-';
                        $renk = ($tutarVal >= 0) ? '#2ed573' : '#ff6b6b';
                        $aciklama = $h['aciklama'] ?: ($islemTuru === 'rezervasyon' ? 'Rezervasyon' : ($islemTuru === 'iade' ? 'İade' : 'Yükleme'));
                    ?>
                    <tr>
                        <td><?= (int)$h['id'] ?></td>
                        <td><span style="color: <?= $renk ?>; font-weight: bold;"><?= $isaret . number_format(abs($tutarVal), 2) ?> TL</span></td>
                        <td><?= htmlspecialchars($aciklama) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>