<?php
require_once __DIR__ . '/veritabani_islemleri.php';

class YemekhaneKontrolcu {
    public function girisIsle(string $eposta, string $sifre): array {
        $eposta = trim($eposta);
        if ($eposta === '' || $sifre === '') {
            return ['ok' => false, 'hata' => 'Lütfen e-posta ve şifre alanlarını doldurun.'];
        }

        $kullanici = kullaniciGetirEposta($eposta);
        if (!$kullanici) {
            return ['ok' => false, 'hata' => 'Bu e-posta adresine ait kayıt bulunamadı!'];
        }
        if ((int)$kullanici['aktif'] !== 1) {
            return ['ok' => false, 'hata' => 'Hesabınız pasif durumda.'];
        }
        if (!password_verify($sifre, (string)$kullanici['sifre_hash'])) {
            return ['ok' => false, 'hata' => 'Hatalı şifre girdiniz!'];
        }

        return ['ok' => true, 'kullanici' => $kullanici];
    }

    public function rezervasyonIsle(int $kullaniciId, int $menuId): array {
        $sonuc = rezervasyonYapVeBakiyeOku($kullaniciId, $menuId);
        $ucret = number_format((float)($sonuc['ucret'] ?? 0), 2);
        $bakiye = number_format((float)($sonuc['guncel_bakiye'] ?? 0), 2);

        return [
            'ok' => true,
            'mesaj' => "Rezervasyonunuz oluşturuldu! Ücret: {$ucret} TL | Güncel Bakiye (Kaynak): {$bakiye} TL",
            'guncel_bakiye' => (float)($sonuc['guncel_bakiye'] ?? 0)
        ];
    }
}