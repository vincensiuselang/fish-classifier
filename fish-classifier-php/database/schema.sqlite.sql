-- ============================================================
-- Fish Classifier - Skema SQLite (buat deploy gratis di Render)
-- Dipakai otomatis pas FISH_DB=sqlite. Untuk XAMPP lokal tetap
-- pakai fish_classifier.sql (MySQL).
--
-- AKUN BAWAAN: admin/admin123 (admin), demo/demo123 (user)
-- ============================================================

PRAGMA foreign_keys = ON;

DROP TABLE IF EXISTS predictions;
DROP TABLE IF EXISTS fish_catalog;
DROP TABLE IF EXISTS users;

-- ---------- users ----------
CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  username      TEXT NOT NULL UNIQUE,
  email         TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('user','admin')),
  created_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO users (id, username, email, password_hash, role) VALUES
  (1, 'admin', 'admin@fish.local', '$2b$10$aeuZvY2a/NHdb34TOtaoAemDtJKT2BveHbHJHYdWLyQGF0Sok.gyC', 'admin'),
  (2, 'demo',  'demo@fish.local',  '$2b$10$Wsp.mBHHZXkgNiEbRiFJOuOzcWYDq536fWWJ/KTsyGSz8xmPvhfbG', 'user');

-- ---------- fish_catalog ----------
CREATE TABLE fish_catalog (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  name            TEXT NOT NULL UNIQUE,
  scientific_name TEXT,
  habitat         TEXT,
  description     TEXT,
  avg_weight_kg   REAL,
  created_by      INTEGER,
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Ganti MySQL "ON UPDATE CURRENT_TIMESTAMP" dengan trigger.
CREATE TRIGGER trg_catalog_updated AFTER UPDATE ON fish_catalog
FOR EACH ROW BEGIN
  UPDATE fish_catalog SET updated_at = CURRENT_TIMESTAMP WHERE id = OLD.id;
END;

INSERT INTO fish_catalog (name, scientific_name, habitat, description, avg_weight_kg, created_by) VALUES
  ('Bawal Putih', 'Pampus argenteus', 'Laut pesisir', 'Ikan bertubuh pipih lebar berwarna keperakan, dagingnya lembut dan populer di pasar Indonesia.', 0.50, 1),
  ('Nila', 'Oreochromis niloticus', 'Air tawar', 'Ikan budidaya air tawar paling umum, tahan banting, dagingnya tebal dan sedikit duri.', 0.40, 1),
  ('Pari', 'Dasyatis sp.', 'Dasar laut', 'Ikan bertubuh pipih dengan sirip melebar seperti sayap, hidup di dasar perairan.', 5.00, 1),
  ('Tongkol', 'Euthynnus affinis', 'Laut lepas', 'Ikan pelagis perenang cepat, kerabat tuna, dipakai untuk ikan kaleng dan pindang.', 2.00, 1),
  ('Tuna', 'Thunnus sp.', 'Laut dalam', 'Predator laut besar bernilai ekonomi tinggi, dagingnya merah padat, favorit ekspor.', 25.00, 1);

-- ---------- predictions ----------
CREATE TABLE predictions (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id         INTEGER,
  image_name      TEXT NOT NULL,
  predicted_class TEXT NOT NULL,
  confidence      REAL NOT NULL,
  top_k           TEXT,
  label           TEXT,
  note            TEXT,
  model_name      TEXT NOT NULL DEFAULT 'cnn_scratch',
  input_size      INTEGER NOT NULL DEFAULT 160,
  elapsed_ms      INTEGER NOT NULL DEFAULT 0,
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX idx_predictions_user    ON predictions (user_id);
CREATE INDEX idx_predictions_class   ON predictions (predicted_class);
CREATE INDEX idx_predictions_created ON predictions (created_at);

INSERT INTO predictions
  (user_id, image_name, predicted_class, confidence, top_k, label, note, model_name, input_size, elapsed_ms)
VALUES
  (2, 'contoh_tuna.jpg', 'Tuna', 0.93215,
   '[{"class":"Tuna","confidence":0.93215},{"class":"Tongkol","confidence":0.05121},{"class":"Nila","confidence":0.01043}]',
   NULL, 'hasil bagus, gambar jernih', 'cnn_scratch', 160, 6812),
  (2, 'contoh_nila.jpg', 'Nila', 0.88410,
   '[{"class":"Nila","confidence":0.8841},{"class":"Bawal Putih","confidence":0.07332},{"class":"Pari","confidence":0.02514}]',
   'Nila', NULL, 'cnn_scratch', 160, 7104);
