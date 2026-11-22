USE yemekhane;
DROP PROCEDURE IF EXISTS sp_rezervasyon_yap;
DELIMITER $$
CREATE PROCEDURE sp_rezervasyon_yap(
    IN p_kullanici_id INT UNSIGNED,
    IN p_menu_id      INT UNSIGNED,
    IN p_porsiyon     VARCHAR(10)
)
BEGIN
    DECLARE v_rol          VARCHAR(10)   DEFAULT NULL;
    DECLARE v_aktif        TINYINT       DEFAULT NULL;
    DECLARE v_bakiye       DECIMAL(10,2) DEFAULT NULL;
    DECLARE v_son          DATETIME      DEFAULT NULL;
    DECLARE v_kapasite     INT           DEFAULT NULL;
    DECLARE v_ogun_adi     VARCHAR(20)   DEFAULT NULL;
    DECLARE v_ogr_fiyat    DECIMAL(8,2)  DEFAULT NULL;
    DECLARE v_per_fiyat    DECIMAL(8,2)  DEFAULT NULL;
    DECLARE v_temel_ucret  DECIMAL(8,2)  DEFAULT NULL;
    DECLARE v_ucret        DECIMAL(8,2)  DEFAULT NULL;
    DECLARE v_pors         VARCHAR(10)   DEFAULT 'tam';
    DECLARE v_dolu         INT           DEFAULT 0;
    DECLARE v_mevcut_id    INT UNSIGNED  DEFAULT NULL;
    DECLARE v_mevcut_durum VARCHAR(12)   DEFAULT NULL;
    DECLARE v_rez_id       INT UNSIGNED  DEFAULT NULL;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;
    DECLARE CONTINUE HANDLER FOR NOT FOUND BEGIN END;

    SET v_pors = LOWER(TRIM(COALESCE(p_porsiyon, 'tam')));
    IF v_pors NOT IN ('tam', 'yarim') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gecersiz porsiyon secimi.';
    END IF;

    START TRANSACTION;

    SELECT rol, aktif, bakiye INTO v_rol, v_aktif, v_bakiye
      FROM kullanicilar WHERE id = p_kullanici_id FOR UPDATE;
    IF v_rol IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kullanici bulunamadi.';
    END IF;
    IF v_aktif = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hesabiniz pasif durumda.';
    END IF;

    SELECT TIMESTAMP(m.tarih, o.son_saat), yh.kapasite, o.ad, m.ogrenci_fiyati, m.personel_fiyati
      INTO v_son, v_kapasite, v_ogun_adi, v_ogr_fiyat, v_per_fiyat
      FROM menuler m
      JOIN ogunler o       ON o.id  = m.ogun_id
      JOIN yemekhaneler yh ON yh.id = m.yemekhane_id
     WHERE m.id = p_menu_id
       FOR UPDATE;
    IF v_son IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Menu bulunamadi.';
    END IF;
    IF NOW() >= v_son THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bu menu icin rezervasyon suresi doldu.';
    END IF;
    IF v_pors = 'yarim' AND LOWER(TRIM(v_ogun_adi)) LIKE '%kahvalt%' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Kahvalti ogununde yarim porsiyon secilemez.';
    END IF;

    SELECT id, durum INTO v_mevcut_id, v_mevcut_durum
      FROM rezervasyonlar
     WHERE kullanici_id = p_kullanici_id AND menu_id = p_menu_id
       FOR UPDATE;
    IF v_mevcut_durum IN ('aktif', 'kullanildi') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bu menu icin zaten rezervasyonunuz var.';
    END IF;

    SELECT COUNT(*) INTO v_dolu
      FROM rezervasyonlar
     WHERE menu_id = p_menu_id AND durum IN ('aktif', 'kullanildi');
    IF v_dolu >= v_kapasite THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Yemekhane kapasitesi dolu.';
    END IF;

    SET v_temel_ucret = IF(v_rol = 'ogrenci', v_ogr_fiyat, v_per_fiyat);
    IF v_pors = 'yarim' THEN
        SET v_ucret = ROUND(v_temel_ucret * 0.60, 2);
    ELSE
        SET v_ucret = v_temel_ucret;
    END IF;

    IF v_bakiye < v_ucret THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bakiye yetersiz.';
    END IF;

    IF v_mevcut_id IS NULL THEN
        INSERT INTO rezervasyonlar (kullanici_id, menu_id, durum, ucret, porsiyon)
        VALUES (p_kullanici_id, p_menu_id, 'aktif', v_ucret, v_pors);
        SET v_rez_id = LAST_INSERT_ID();
    ELSE
        UPDATE rezervasyonlar
           SET durum = 'aktif', ucret = v_ucret, porsiyon = v_pors, olusturma = NOW(),
               iptal_zamani = NULL, kullanma_zamani = NULL
         WHERE id = v_mevcut_id;
        SET v_rez_id = v_mevcut_id;
    END IF;

    UPDATE kullanicilar SET bakiye = bakiye - v_ucret WHERE id = p_kullanici_id;

    INSERT INTO bakiye_hareketleri (kullanici_id, tur, tutar, rezervasyon_id, aciklama)
    VALUES (
        p_kullanici_id,
        'rezervasyon',
        -v_ucret,
        v_rez_id,
        CONCAT('Rezervasyon (', IF(v_pors = 'yarim', 'Yarim Porsiyon', 'Tam Porsiyon'), ')')
    );

    COMMIT;

    SELECT v_rez_id AS rezervasyon_id, v_ucret AS ucret, v_pors AS porsiyon, v_bakiye - v_ucret AS bakiye;
END$$
DELIMITER ;