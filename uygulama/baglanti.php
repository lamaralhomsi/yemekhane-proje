<?php
date_default_timezone_set('Europe/Istanbul');

function getPrimaryConnection() {
    $host = 'mysql-primary';
    $db   = 'yemekhane';
    $user = 'root';
    $pass = 'rootpassword';
    $port = '3306';

    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function getReplicaConnection() {
    $host = 'mysql-replica';
    $db   = 'yemekhane';
    $user = 'root';
    $pass = 'rootpassword';
    $port = '3306';

    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->query("SELECT 1 FROM kullanicilar LIMIT 1");
        return $pdo;
    } catch (Exception $e) {
        return getPrimaryConnection();
    }
}

function getRedisConnection() {
    try {
        if (class_exists('Redis')) {
            $redis = new Redis();
            $redis->connect('yemekhane-redis-1', 6379, 1.5);
            return $redis;
        }
    } catch (Exception $e) {
    }
    return null;
}

function gunSonunaKalanSaniye(string $tarih = ''): int {
    $hedefTarih = $tarih !== '' ? $tarih : date('Y-m-d');
    $gunSonu = strtotime($hedefTarih . ' 23:59:59');
    $fark = ($gunSonu !== false) ? ($gunSonu - time()) : 3600;
    return max(60, $fark);
}

function redisMenuAnahtari(int $yemekhaneId, string $tarih, int $ogunId): string {
    return "menu:{$yemekhaneId}:{$tarih}:{$ogunId}";
}

function redisMenuSil(int $yemekhaneId, string $tarih, int $ogunId): void {
    $redis = getRedisConnection();
    if (!$redis) {
        return;
    }
    try {
        if ($yemekhaneId > 0 && $tarih !== '' && $ogunId > 0) {
            $redis->del(redisMenuAnahtari($yemekhaneId, $tarih, $ogunId));
        }
        $tarihDeseni = $tarih !== '' ? $tarih : '*';
        $eslesenler = $redis->keys("menu:*:{$tarihDeseni}:*");
        if (is_array($eslesenler) && !empty($eslesenler)) {
            $redis->del($eslesenler);
        }
    } catch (Throwable $e) {
    }
}

function redisOturumBaslat(): void {
    if (!empty($GLOBALS['redis_oturum_basladi']) && is_array($_SESSION)) {
        return;
    }

    $ttl = 1800; // 30 dakika
    $cookieAd = 'YEMEKHANE_OTURUM';
    ini_set('session.gc_maxlifetime', (string)$ttl);

    $redis = getRedisConnection();
    $token = $_COOKIE[$cookieAd] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{32,64}$/', $token)) {
        $token = bin2hex(random_bytes(24));
    }

    if (!headers_sent()) {
        setcookie($cookieAd, $token, [
            'expires' => time() + $ttl,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    $_COOKIE[$cookieAd] = $token;

    $GLOBALS['aktif_oturum_token'] = $token;
    $GLOBALS['aktif_oturum_veri'] = [];

    if ($redis) {
        try {
            $anahtar = "oturum:{$token}";
            $ham = $redis->get($anahtar);
            if (is_string($ham) && $ham !== '') {
                $coz = json_decode($ham, true);
                if (is_array($coz)) {
                    $GLOBALS['aktif_oturum_veri'] = $coz;
                }
            }
            $redis->setex($anahtar, $ttl, json_encode($GLOBALS['aktif_oturum_veri'], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
        }
    }

    $_SESSION = &$GLOBALS['aktif_oturum_veri'];
    $GLOBALS['redis_oturum_basladi'] = true;

    register_shutdown_function(function () use ($ttl) {
        $token = $GLOBALS['aktif_oturum_token'] ?? '';
        if ($token === '' || !empty($GLOBALS['oturum_silindi'])) {
            return;
        }
        $redis = getRedisConnection();
        if ($redis) {
            try {
                $anahtar = "oturum:{$token}";
                $veri = $GLOBALS['aktif_oturum_veri'] ?? [];
                $redis->setex($anahtar, $ttl, json_encode($veri, JSON_UNESCAPED_UNICODE));
            } catch (Throwable $e) {
            }
        }
    });
}

function redisOturumSonlandir(): void {
    $ttl = 1800;
    $cookieAd = 'YEMEKHANE_OTURUM';
    $token = $GLOBALS['aktif_oturum_token'] ?? ($_COOKIE[$cookieAd] ?? '');
    $GLOBALS['oturum_silindi'] = true;
    $GLOBALS['aktif_oturum_veri'] = [];
    $_SESSION = [];

    $redis = getRedisConnection();
    if ($redis && is_string($token) && $token !== '') {
        try {
            $redis->del("oturum:{$token}");
        } catch (Throwable $e) {
        }
    }

    if (!headers_sent()) {
        setcookie($cookieAd, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    unset($_COOKIE[$cookieAd], $_COOKIE[$ttl]);
}

function temizSqlHatasi(Throwable $e): string {
    $msg = $e->getMessage();
    if (preg_match('/SQLSTATE\[45000\]:\s*(?:\d+\s+)?(.*)/u', $msg, $m)) {
        return trim($m[1]);
    }
    return $msg;
}