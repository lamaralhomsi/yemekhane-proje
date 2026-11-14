<?php
require_once __DIR__ . '/baglanti.php';

function redisAl(): ?Redis
{
    $redis = getRedisConnection();
    if ($redis instanceof Redis) {
        return $redis;
    }
    return null;
}

function redisOkuma(string $anahtar): ?array
{
    try {
        $redis = redisAl();
        if (!$redis) {
            return null;
        }
        $veri = $redis->get($anahtar);
        if ($veri === false || $veri === null || $veri === '') {
            return null;
        }
        $cozulmus = json_decode((string)$veri, true);
        return is_array($cozulmus) ? $cozulmus : null;
    } catch (Throwable $e) {
        return null;
    }
}

function redisYazma(string $anahtar, array $veri, int $ttlSaniye = 120): void
{
    try {
        $redis = redisAl();
        if (!$redis) {
            return;
        }
        $redis->setex($anahtar, max(10, $ttlSaniye), json_encode($veri, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
    }
}

function menuOnbellekTemizle(): void
{
    try {
        $redis = redisAl();
        if (!$redis) {
            return;
        }
        foreach (['yemekhane:menu:*', 'menu:*'] as $desen) {
            $anahtarlar = $redis->keys($desen);
            if (is_array($anahtarlar) && !empty($anahtarlar)) {
                $redis->del($anahtarlar);
            }
        }
        $redis->del('yemekhane:yonetici:rapor');
    } catch (Throwable $e) {
    }
}

function kullaniciGetirEposta(string $eposta): ?array
{
    $db = getReplicaConnection();
    $stmt = $db->prepare("
        SELECT id, ad, soyad, eposta, sifre_hash, rol, aktif, bakiye
        FROM kullanicilar
        WHERE eposta = ?
        LIMIT 1
    ");
    $stmt->execute([$eposta]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function kullaniciRolAl(int $kullaniciId): string
{
    if ($kullaniciId <= 0) {
        return 'ogrenci';
    }

    $db = getReplicaConnection();
    $stmt = $db->prepare("SELECT rol FROM kullanicilar WHERE id = ? LIMIT 1");
    $stmt->execute([$kullaniciId]);
    $row = $stmt->fetch();

    $rol = strtolower(trim((string)($row['rol'] ?? 'ogrenci')));
    if (in_array($rol, ['yonetici', 'personel', 'ogrenci'], true)) {
        return $rol;
    }

    return 'ogrenci';
}

function gecerliAlerjenListesi(): array
{
    return [
        'gluten' => 'Gluten',
        'sut' => 'Süt / Laktoz',
        'yumurta' => 'Yumurta',
        'kuruyemis' => 'Kuruyemiş',
        'yer_fistigi' => 'Yer Fıstığı',
        'soya' => 'Soya',
        'balik' => 'Balık',
        'deniz_urunleri' => 'Deniz Ürünleri',
    ];
}

function kullaniciAlerjenleriniGetir(int $kullaniciId): array
{
    if ($kullaniciId <= 0) {
        return [];
    }
    try {
        $db = getReplicaConnection();
        $stmt = $db->prepare("SELECT alerjen FROM kullanici_alerjenleri WHERE kullanici_id = ? ORDER BY alerjen ASC");
        $stmt->execute([$kullaniciId]);
        $liste = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $temiz = strtolower(trim((string)$a));
            if ($temiz !== '') {
                $liste[] = $temiz;
            }
        }
        return array_values(array_unique($liste));
    } catch (Throwable $e) {
        return [];
    }
}

function kullaniciAlerjenleriniKaydet(int $kullaniciId, array $alerjenler): void
{
    $gecerli = array_keys(gecerliAlerjenListesi());
    $secilen = [];
    foreach ($alerjenler as $a) {
        $temiz = strtolower(trim((string)$a));
        if (in_array($temiz, $gecerli, true)) {
            $secilen[] = $temiz;
        }
    }
    $secilen = array_values(array_unique($secilen));

    $db = getPrimaryConnection();
    $db->beginTransaction();
    try {
        $del = $db->prepare("DELETE FROM kullanici_alerjenleri WHERE kullanici_id = ?");
        $del->execute([$kullaniciId]);

        if (!empty($secilen)) {
            $ins = $db->prepare("INSERT INTO kullanici_alerjenleri (kullanici_id, alerjen) VALUES (?, ?)");
            foreach ($secilen as $a) {
                $ins->execute([$kullaniciId, $a]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function kaynaktanBakiyeAl(int $kullaniciId): float
{
    $db = getPrimaryConnection();
    $stmt = $db->prepare("SELECT bakiye FROM kullanicilar WHERE id = ?");
    $stmt->execute([$kullaniciId]);
    $row = $stmt->fetch();
    return (float)($row['bakiye'] ?? 0.0);
}

function replikadanBakiyeAl(int $kullaniciId): float
{
    $db = getReplicaConnection();
    $stmt = $db->prepare("SELECT bakiye FROM kullanicilar WHERE id = ?");
    $stmt->execute([$kullaniciId]);
    $row = $stmt->fetch();
    return (float)($row['bakiye'] ?? 0.0);
}

function rezervasyonYapVeBakiyeOku(int $kullaniciId, int $menuId, string $porsiyon = 'tam'): array
{
    $porsiyonTemiz = strtolower(trim($porsiyon)) === 'yarim' ? 'yarim' : 'tam';
    $db = getPrimaryConnection();
    $stmt = $db->prepare("CALL sp_rezervasyon_yap(?, ?, ?)");
    $stmt->execute([$kullaniciId, $menuId, $porsiyonTemiz]);
    $sonuc = $stmt->fetch() ?: [];
    $stmt->closeCursor();
    menuOnbellekTemizle();
    $sonuc['porsiyon'] = $sonuc['porsiyon'] ?? $porsiyonTemiz;
    $sonuc['guncel_bakiye'] = kaynaktanBakiyeAl($kullaniciId);
    return $sonuc;
}

function haftalikMenuleriGetir(): array
{
    $cacheKey = 'yemekhane:menu:haftalik:' . date('Y-m-d');
    $onbellek = redisOkuma($cacheKey);
    if ($onbellek !== null) {
        $simdi = time();
        foreach ($onbellek as &$satir) {
            $sonZaman = strtotime((string)$satir['tarih'] . ' ' . (string)$satir['son_saat']);
            $satir['sure_doldu'] = ((int)($satir['sure_doldu'] ?? 0) === 1) || ($sonZaman !== false && $simdi >= $sonZaman);
        }
        unset($satir);
        return $onbellek;
    }

    $db = getReplicaConnection();
    $satirlar = $db->query("
        SELECT m.id AS menu_id,
               m.tarih,
               m.yemekhane_id,
               yh.ad AS yemekhane,
               m.ogun_id,
               o.ad AS ogun,
               o.sira AS ogun_sira,
               o.son_saat,
               m.ogrenci_fiyati,
               m.personel_fiyati,
               yh.kapasite,
               COALESCE(rez.rezerve_sayisi, 0) AS rezerve,
               GREATEST(yh.kapasite - COALESCE(rez.rezerve_sayisi, 0), 0) AS bos_yer,
               ROUND((COALESCE(rez.rezerve_sayisi, 0) / NULLIF(yh.kapasite, 0)) * 100, 1) AS doluluk_yuzde,
               TIMESTAMP(m.tarih, o.son_saat) AS rezervasyon_son,
               (NOW() >= TIMESTAMP(m.tarih, o.son_saat)) AS sure_doldu
        FROM menuler m
        JOIN yemekhaneler yh ON yh.id = m.yemekhane_id
        JOIN ogunler o ON o.id = m.ogun_id
        LEFT JOIN (
            SELECT menu_id, COUNT(*) AS rezerve_sayisi
            FROM rezervasyonlar
            WHERE durum IN ('aktif', 'kullanildi')
            GROUP BY menu_id
        ) rez ON rez.menu_id = m.id
        WHERE m.tarih >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        ORDER BY m.tarih ASC, o.sira ASC, m.yemekhane_id ASC
    ")->fetchAll();

    $simdi = time();
    foreach ($satirlar as &$satir) {
        $sonZaman = strtotime((string)$satir['tarih'] . ' ' . (string)$satir['son_saat']);
        $satir['sure_doldu'] = ((int)($satir['sure_doldu'] ?? 0) === 1) || ($sonZaman !== false && $simdi >= $sonZaman);
    }
    unset($satir);

    redisYazma($cacheKey, $satirlar, 120);
    return $satirlar;
}

function menulerinYemekleriniGetir(array $menuIds): array
{
    if (empty($menuIds)) {
        return [];
    }

    $temizIds = array_values(array_unique(array_map('intval', $menuIds)));
    sort($temizIds);
    $cacheKey = 'yemekhane:menu:yemekler:v2:' . md5(implode(',', $temizIds));
    $onbellek = redisOkuma($cacheKey);
    if ($onbellek !== null) {
        return $onbellek;
    }

    $db = getReplicaConnection();
    $placeholders = implode(',', array_fill(0, count($temizIds), '?'));
    $stmt = $db->prepare("
        SELECT my.menu_id,
               y.ad AS yemek,
               yk.sira AS kategori_sira,
               y.kalori,
               COALESCE(y.vejetaryen, 0) AS vejetaryen,
               COALESCE(y.alerjen, '') AS alerjenler
        FROM menu_yemekleri my
        JOIN yemekler y ON y.id = my.yemek_id
        JOIN yemek_kategorileri yk ON yk.id = y.kategori_id
        WHERE my.menu_id IN ($placeholders)
        ORDER BY yk.sira ASC, y.ad ASC
    ");
    $stmt->execute($temizIds);

    $gruplar = [];
    foreach ($stmt->fetchAll() as $row) {
        $gruplar[(int)$row['menu_id']][] = [
            'yemek' => (string)($row['yemek'] ?? ''),
            'kalori' => (int)($row['kalori'] ?? 0),
            'vejetaryen' => (int)($row['vejetaryen'] ?? 0),
            'alerjenler' => (string)($row['alerjenler'] ?? '')
        ];
    }

    redisYazma($cacheKey, $gruplar, 300);
    return $gruplar;
}

function aktifRezervasyonMenuIdleri(int $kullaniciId): array
{
    $db = getReplicaConnection();
    $stmt = $db->prepare("
        SELECT menu_id
        FROM rezervasyonlar
        WHERE kullanici_id = ? AND durum IN ('aktif', 'kullanildi')
    ");
    $stmt->execute([$kullaniciId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function yoneticiRaporGetir(): array
{
    $cacheKey = 'yemekhane:yonetici:rapor';
    $onbellek = redisOkuma($cacheKey);
    if ($onbellek !== null) {
        $simdi = time();
        foreach ($onbellek as &$satir) {
            $sonSaat = (string)($satir['son_saat'] ?? '23:59:59');
            $sonZaman = strtotime((string)$satir['tarih'] . ' ' . $sonSaat);
            $satir['duzenlenebilir'] = ($sonZaman !== false && $simdi < $sonZaman);
        }
        unset($satir);
        return $onbellek;
    }

    $db = getReplicaConnection();
    $satirlar = $db->query("
        SELECT m.id AS menu_id,
               m.tarih,
               m.yemekhane_id,
               yh.ad AS yemekhane,
               m.ogun_id,
               og.ad AS ogun,
               og.son_saat,
               yh.kapasite,
               COALESCE(rez.rezerve_sayisi, 0) AS rezerve,
               GREATEST(yh.kapasite - COALESCE(rez.rezerve_sayisi, 0), 0) AS bos_yer,
               ROUND((COALESCE(rez.rezerve_sayisi, 0) / NULLIF(yh.kapasite, 0)) * 100, 1) AS doluluk_yuzde,
               COALESCE(deg.oy_sayisi, 0) AS oy_sayisi,
               deg.ortalama_puan
        FROM menuler m
        JOIN yemekhaneler yh ON yh.id = m.yemekhane_id
        JOIN ogunler og ON og.id = m.ogun_id
        LEFT JOIN (
            SELECT menu_id, COUNT(*) AS rezerve_sayisi
            FROM rezervasyonlar
            WHERE durum IN ('aktif', 'kullanildi')
            GROUP BY menu_id
        ) rez ON rez.menu_id = m.id
        LEFT JOIN (
            SELECT menu_id, COUNT(*) AS oy_sayisi, ROUND(AVG(puan), 1) AS ortalama_puan
            FROM degerlendirmeler
            GROUP BY menu_id
        ) deg ON deg.menu_id = m.id
        ORDER BY m.tarih DESC, og.sira DESC
        LIMIT 60
    ")->fetchAll();

    $simdi = time();
    foreach ($satirlar as &$satir) {
        $sonSaat = (string)($satir['son_saat'] ?? '23:59:59');
        $sonZaman = strtotime((string)$satir['tarih'] . ' ' . $sonSaat);
        $satir['duzenlenebilir'] = ($sonZaman !== false && $simdi < $sonZaman);
    }
    unset($satir);

    redisYazma($cacheKey, $satirlar, 120);
    return $satirlar;
}