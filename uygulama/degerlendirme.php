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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['menu_id'])) {
    $menu_id = (int)$_POST['menu_id'];
    $puan = (int)($_POST['puan'] ?? 0);
    $yorum = trim($_POST['yorum'] ?? '');

    if ($puan >= 1 && $puan <= 5) {
        try {
            $dbPrimary = getPrimaryConnection();

            $stmtKullanildi = $dbPrimary->prepare("
                SELECT id FROM rezervasyonlar
                WHERE kullanici_id = ? AND menu_id = ? AND durum = 'kullanildi'
                LIMIT 1
            ");
            $stmtKullanildi->execute([$kullanici_id, $menu_id]);

            if ($stmtKullanildi->rowCount() === 0) {
                $hata = "Yalnızca kullanılmış menüleri değerlendirebilirsiniz.";
            } else {
                $stmtKontrol = $dbPrimary->prepare("
                    SELECT id FROM degerlendirmeler
                    WHERE kullanici_id = ? AND menu_id = ?
                ");
                $stmtKontrol->execute([$kullanici_id, $menu_id]);

                if ($stmtKontrol->rowCount() > 0) {
                    $stmtUp = $dbPrimary->prepare("
                        UPDATE degerlendirmeler
                        SET puan = ?, yorum = ?
                        WHERE kullanici_id = ? AND menu_id = ?
                    ");
                    $stmtUp->execute([$puan, $yorum, $kullanici_id, $menu_id]);
                    $mesaj = "Menü değerlendirmeniz güncellendi!";
                } else {
                    $stmtIns = $dbPrimary->prepare("
                        INSERT INTO degerlendirmeler (kullanici_id, menu_id, puan, yorum)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmtIns->execute([$kullanici_id, $menu_id, $puan, $yorum]);
                    $mesaj = "Menü değerlendirmeniz başarıyla kaydedildi!";
                }
            }
        } catch (Throwable $e) {
            $hata = "Değerlendirme kaydedilirken hata oluştu: " . temizSqlHatasi($e);
        }
    } else {
        $hata = "Lütfen 1 ile 5 arasında geçerli bir puan seçin.";
    }
}

$rezerveMenuler = [];
$yapilanDegerlendirmeler = [];

try {
    $dbReplica = getReplicaConnection();

    $stmtRez = $dbReplica->prepare("
        SELECT m.id AS menu_id, m.tarih, og.sira AS ogun_sira, yh.ad AS yemekhane_adi, og.ad AS ogun_adi
        FROM rezervasyonlar r
        JOIN menuler m ON r.menu_id = m.id
        JOIN yemekhaneler yh ON m.yemekhane_id = yh.id
        JOIN ogunler og ON m.ogun_id = og.id
        WHERE r.kullanici_id = ? AND r.durum = 'kullanildi'
        GROUP BY m.id, m.tarih, og.sira, yh.ad, og.ad
        ORDER BY m.tarih DESC, og.sira DESC
    ");
    $stmtRez->execute([$kullanici_id]);
    $rezerveMenuler = $stmtRez->fetchAll();

    $stmtDeg = $dbReplica->prepare("
        SELECT d.menu_id, d.puan, d.yorum, m.tarih, yh.ad AS yemekhane_adi, og.ad AS ogun_adi
        FROM degerlendirmeler d
        JOIN menuler m ON d.menu_id = m.id
        JOIN yemekhaneler yh ON m.yemekhane_id = yh.id
        JOIN ogunler og ON m.ogun_id = og.id
        WHERE d.kullanici_id = ?
        ORDER BY m.tarih DESC, og.sira DESC
    ");
    $stmtDeg->execute([$kullanici_id]);
    $yapilanDegerlendirmeler = $stmtDeg->fetchAll();
} catch (Throwable $e) {
    $hata = $hata ?: ("Veriler yüklenemedi: " . temizSqlHatasi($e));
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yemekhane - Menü Puanla ve Değerlendir</title>
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
        form {
            background: #1b1d25;
            border: 1px solid #2a2d3a;
            padding: 20px;
            border-radius: 14px;
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.25);
        }
        .form-group { margin-bottom: 18px; }
        label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
            color: #e5e7eb;
            font-size: 13.5px;
        }
        select, textarea {
            width: 100%;
            padding: 11px 12px;
            box-sizing: border-box;
            border: 1px solid #353949;
            background: #121318;
            color: #f9fafb;
            border-radius: 8px;
            font-size: 13.5px;
        }
        textarea { resize: vertical; height: 95px; }
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

        <h2>Menü Puanlama ve Yorum Paneli</h2>

        <?php if ($mesaj !== ''): ?><div class="basari"><?= htmlspecialchars($mesaj) ?></div><?php endif; ?>
        <?php if ($hata !== ''): ?><div class="hata"><?= htmlspecialchars($hata) ?></div><?php endif; ?>

        <h3>Menüye Puan Ver (1 - 5)</h3>
        <?php if (empty($rezerveMenuler)): ?>
            <p style="color: #888;">Değerlendirme yapabilmek için kullanılmış (`kullanildi`) bir menü rezervasyonunuz bulunmalıdır.</p>
        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label>Kullandığınız Menüyü Seçin:</label>
                    <select name="menu_id" required>
                        <option value="">-- Menü Seçin --</option>
                        <?php foreach ($rezerveMenuler as $rm): ?>
                            <option value="<?= (int)$rm['menu_id'] ?>">
                                Tarih: <?= htmlspecialchars($rm['tarih']) ?> | <?= htmlspecialchars($rm['yemekhane_adi']) ?> - <?= htmlspecialchars($rm['ogun_adi']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Puanınız:</label>
                    <select name="puan" required>
                        <option value="">-- Puan Seçin --</option>
                        <option value="5">⭐⭐⭐⭐⭐ (5 - Mükemmel)</option>
                        <option value="4">⭐⭐⭐⭐ (4 - Çok İyi)</option>
                        <option value="3">⭐⭐⭐ (3 - Orta)</option>
                        <option value="2">⭐⭐ (2 - Kötü)</option>
                        <option value="1">⭐ (1 - Çok Kötü)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Yorumunuz (İsteğe bağlı):</label>
                    <textarea name="yorum" placeholder="Yemekler nasıldı? Görüşlerinizi yazın..."></textarea>
                </div>

                <button type="submit">Değerlendirmeyi Gönder</button>
            </form>
        <?php endif; ?>

        <h3 style="margin-top: 30px;">
            Daha Önce Yaptığınız Değerlendirmeler
            <span style="font-size: 12px; font-weight: normal; color: #aaaaaa;">(Yeni değerlendirme sonrası tablonun güncellenmesi için sayfayı yenileyin)</span>
        </h3>
        <table>
            <tr>
                <th>Tarih</th>
                <th>Yemekhane</th>
                <th>Öğün</th>
                <th>Puan</th>
                <th>Yorum</th>
            </tr>
            <?php if (empty($yapilanDegerlendirmeler)): ?>
                <tr><td colspan="5">Henüz yaptığınız bir değerlendirme bulunmuyor.</td></tr>
            <?php else: ?>
                <?php foreach ($yapilanDegerlendirmeler as $deg): ?>
                    <tr>
                        <td><?= htmlspecialchars($deg['tarih']) ?></td>
                        <td><?= htmlspecialchars($deg['yemekhane_adi']) ?></td>
                        <td><?= htmlspecialchars($deg['ogun_adi']) ?></td>
                        <td><span style="color: #ff4081; font-weight: bold;"><?= str_repeat('⭐', (int)$deg['puan']) ?> (<?= (int)$deg['puan'] ?>/5)</span></td>
                        <td><?= htmlspecialchars($deg['yorum'] ?: 'Yorum yapılmamış') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </table>
    </div>
</body>
</html>