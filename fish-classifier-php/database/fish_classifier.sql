-- ============================================================
-- Fish Classifier - Database Schema
-- Untuk XAMPP (MySQL / MariaDB) via phpMyAdmin
--
-- Cara pakai:
--   1. Start Apache + MySQL di XAMPP Control Panel
--   2. Buka http://localhost/phpmyadmin
--   3. Tab "Import" -> pilih file ini -> Go
--
-- AKUN BAWAAN (buat demo / login):
--   admin  / admin123   (role admin  -> bisa kelola Katalog Ikan)
--   demo   / demo123    (role user   -> user biasa)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `fish_classifier`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `fish_classifier`;

-- Reset biar import ulang bersih (urutan drop penting karena foreign key)
DROP TABLE IF EXISTS `predictions`;
DROP TABLE IF EXISTS `fish_catalog`;
DROP TABLE IF EXISTS `users`;

-- ------------------------------------------------------------
-- users: akun login (password = bcrypt hash). role: user / admin
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `email`         VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('user','admin') NOT NULL DEFAULT 'user',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password: admin123 & demo123 (bcrypt, cocok dengan password_verify PHP)
INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`) VALUES
  (1, 'admin', 'admin@fish.local', '$2b$10$aeuZvY2a/NHdb34TOtaoAemDtJKT2BveHbHJHYdWLyQGF0Sok.gyC', 'admin'),
  (2, 'demo',  'demo@fish.local',  '$2b$10$Wsp.mBHHZXkgNiEbRiFJOuOzcWYDq536fWWJ/KTsyGSz8xmPvhfbG', 'user');

-- ------------------------------------------------------------
-- fish_catalog: master jenis ikan (CRUD oleh admin)
-- ------------------------------------------------------------
CREATE TABLE `fish_catalog` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(50)  NOT NULL,
  `scientific_name` VARCHAR(100) DEFAULT NULL,
  `habitat`         VARCHAR(100) DEFAULT NULL,
  `description`     TEXT         DEFAULT NULL,
  `avg_weight_kg`   DECIMAL(6,2) DEFAULT NULL,
  `created_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_catalog_name` (`name`),
  KEY `idx_catalog_created_by` (`created_by`),
  CONSTRAINT `fk_catalog_user`
    FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `fish_catalog`
  (`name`, `scientific_name`, `habitat`, `description`, `avg_weight_kg`, `created_by`)
VALUES
  ('Bawal Putih', 'Pampus argenteus', 'Laut pesisir', 'Ikan bertubuh pipih lebar berwarna keperakan, dagingnya lembut dan populer di pasar Indonesia.', 0.50, 1),
  ('Nila', 'Oreochromis niloticus', 'Air tawar', 'Ikan budidaya air tawar paling umum, tahan banting, dagingnya tebal dan sedikit duri.', 0.40, 1),
  ('Pari', 'Dasyatis sp.', 'Dasar laut', 'Ikan bertubuh pipih dengan sirip melebar seperti sayap, hidup di dasar perairan.', 5.00, 1),
  ('Tongkol', 'Euthynnus affinis', 'Laut lepas', 'Ikan pelagis perenang cepat, kerabat tuna, dipakai untuk ikan kaleng dan pindang.', 2.00, 1),
  ('Tuna', 'Thunnus sp.', 'Laut dalam', 'Predator laut besar bernilai ekonomi tinggi, dagingnya merah padat, favorit ekspor.', 25.00, 1);

-- ------------------------------------------------------------
-- predictions: riwayat prediksi. label = koreksi kelas (Update), note = catatan
-- ------------------------------------------------------------
CREATE TABLE `predictions` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`         INT UNSIGNED  DEFAULT NULL,
  `image_name`      VARCHAR(255)  NOT NULL,
  `predicted_class` VARCHAR(50)   NOT NULL,
  `confidence`      DECIMAL(6,5)  NOT NULL,
  `top_k`           TEXT          DEFAULT NULL,
  `label`           VARCHAR(50)   DEFAULT NULL,
  `note`            VARCHAR(255)  DEFAULT NULL,
  `model_name`      VARCHAR(30)   NOT NULL DEFAULT 'cnn_scratch',
  `input_size`      SMALLINT UNSIGNED NOT NULL DEFAULT 160,
  `elapsed_ms`      INT UNSIGNED  NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_predictions_user` (`user_id`),
  KEY `idx_predictions_class` (`predicted_class`),
  KEY `idx_predictions_created` (`created_at`),
  CONSTRAINT `fk_predictions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contoh riwayat buat testing tampilan (milik user 'demo', id=2)
INSERT INTO `predictions`
  (`user_id`, `image_name`, `predicted_class`, `confidence`, `top_k`, `label`, `note`, `model_name`, `input_size`, `elapsed_ms`)
VALUES
  (2, 'contoh_tuna.jpg', 'Tuna', 0.93215,
   '[{"class":"Tuna","confidence":0.93215},{"class":"Tongkol","confidence":0.05121},{"class":"Nila","confidence":0.01043}]',
   NULL, 'hasil bagus, gambar jernih', 'cnn_scratch', 160, 6812),
  (2, 'contoh_nila.jpg', 'Nila', 0.88410,
   '[{"class":"Nila","confidence":0.8841},{"class":"Bawal Putih","confidence":0.07332},{"class":"Pari","confidence":0.02514}]',
   'Nila', NULL, 'cnn_scratch', 160, 7104);
