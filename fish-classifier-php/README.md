# FISH-CLF // Klasifikasi Ikan Indonesia (PHP)

Aplikasi web klasifikasi 5 jenis ikan (**Bawal Putih, Nila, Pari, Tongkol, Tuna**) dengan
**inference CNN 100% PHP murni** (tanpa Python saat runtime). Upload gambar → prediksi + top-3 confidence.

Project UAS Pemrograman Web. Memenuhi 3 syarat utama:

1. **Autentikasi (Login/Register/Logout)** berbasis session + password bcrypt.
2. **CRUD** pada dua entitas: **Riwayat Prediksi** (per-user) & **Katalog Ikan** (khusus admin).
3. **Desain custom** bergaya *retro-terminal / neo-brutalist* — bukan template generik.

---

## Akun Bawaan (buat demo ke dosen)

| Username | Password | Role  | Bisa apa                                    |
|----------|----------|-------|---------------------------------------------|
| `admin`  | `admin123` | admin | semua + **CRUD Katalog Ikan**             |
| `demo`   | `demo123`  | user  | prediksi + **CRUD Riwayat** miliknya       |

Atau daftar akun baru sendiri lewat halaman **Daftar** (otomatis role `user`).

---

## Fitur & Halaman

| Halaman              | File                   | Akses            | Fungsi                                        |
|----------------------|------------------------|------------------|-----------------------------------------------|
| Prediksi             | `index.php`            | publik (guest ok)| upload gambar → prediksi CNN (Create riwayat)  |
| Katalog Ikan         | `catalog.php`          | publik           | lihat data master ikan (Read)                  |
| Riwayat Prediksi     | `history.php`          | **wajib login**  | list / edit label & catatan / hapus (R-U-D)    |
| Kelola Katalog       | `catalog_manage.php`   | **admin only**   | tambah / edit / hapus jenis ikan (C-R-U-D)     |
| Masuk / Daftar       | `login.php` `register.php` | publik        | autentikasi session                            |
| API prediksi (JSON)  | `predict.php`          | publik           | POST gambar → JSON (dipakai front-end)         |

Gating login: **prediksi bebas dicoba siapa saja**, tapi menyimpan/mengelola riwayat & katalog
butuh login. Katalog hanya bisa di-CRUD oleh role **admin**.

Keamanan: password di-hash **bcrypt**, query pakai **PDO prepared statement** (anti SQL-injection),
form aksi CRUD dilindungi **token CSRF**, output di-escape (`htmlspecialchars`) anti XSS.

---

## Struktur

```
fish-classifier-php/
├── public/                     # web root (docroot Apache ke sini)
│   ├── index.php               # halaman prediksi (UI upload)
│   ├── login.php / register.php / logout.php   # autentikasi (form)
│   ├── history.php             # CRUD riwayat prediksi
│   ├── catalog.php             # katalog ikan (read publik)
│   ├── catalog_manage.php      # CRUD katalog (admin)
│   ├── predict.php             # API JSON prediksi
│   ├── classes.php             # API info model & kelas
│   ├── _layout.php             # partial header/nav + footer (dipakai semua halaman)
│   ├── _denied.php             # halaman 403 (akses admin)
│   └── assets/                 # style.css (tema brutalist) + app.js
├── src/
│   ├── Auth.php                # session, login/logout, role, CSRF
│   ├── Database.php            # koneksi PDO + log prediksi
│   ├── WeightLoader.php / Preprocess.php / FishCNN.php / Classifier.php  # engine CNN
├── weights/                    # bobot model (.bin + .json)
├── database/fish_classifier.sql  # skema + seed (users, fish_catalog, predictions)
├── config.php                  # konfigurasi (resolusi, DB, dll)
└── cli.php                     # inference lewat terminal
```

---

## Cara Menjalankan (XAMPP)

1. Copy folder ini ke `C:\xampp\htdocs\fish-classifier-php` (atau symlink).
2. Start **Apache** + **MySQL** di XAMPP Control Panel.
3. Buka `http://localhost/phpmyadmin` → tab **Import** → pilih `database/fish_classifier.sql` → **Go**.
   (Database `fish_classifier` + tabel + akun demo otomatis dibuat.)
4. Set document root ke folder `public/`, atau akses langsung:
   `http://localhost/fish-classifier-php/public/`
5. Login pakai akun `admin / admin123` buat lihat semua fitur.

Butuh **PHP 8.x + ekstensi GD** (`php -m | grep gd`). Konfigurasi DB ada di `config.php`
(default XAMPP: host `127.0.0.1`, user `root`, password kosong).

### Alternatif tanpa Apache (built-in server)
```bash
php -S localhost:8000 -t public
# buka http://localhost:8000
```
(Tetap perlu MySQL nyala + import SQL biar login & CRUD jalan.)

---

## Deploy Online (biar temen bisa akses tanpa install apa-apa)

### Railway (rekomendasi — PHP + MySQL sekaligus, ada free tier)

Semua sudah otomatis: config baca env variable, database auto-import saat start.
Temen kamu cukup:

1. Push folder ini ke repo **GitHub**.
2. Buka [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub repo** → pilih repo ini.
   Railway auto-detect `Dockerfile` (PHP + Apache). Biarin build jalan.
3. Di project yang sama: **New** → **Database** → **Add MySQL**.
4. Klik service **app** (yang dari repo) → tab **Variables** → **New Variable**:
   - Name: `DATABASE_URL`
   - Value: `${{MySQL.MYSQL_URL}}`  ← ketik persis gini (auto nyambung ke MySQL).
   - (opsional) tambah `FISH_IMG_SIZE` = `128` biar inference cepat.
5. Service app otomatis re-deploy. Saat start, database **auto-import** (tabel + akun demo dibuat sendiri).
6. Tab **Settings** → **Networking** → **Generate Domain** → dapat URL publik.
   Buka `https://namamu.up.railway.app` → login `admin / admin123`. Selesai.

> Tidak perlu phpMyAdmin, tidak perlu import SQL manual — `tools/migrate.php` jalan otomatis.

### Render.com (alternatif, juga Docker + free tier)

1. Push ke GitHub → [render.com](https://render.com) → **New → Web Service** → connect repo.
2. Environment: **Docker** (auto dari `Dockerfile`).
3. **New → PostgreSQL/MySQL** — Render free tier pakai Postgres; untuk MySQL pakai add-on eksternal
   (mis. [Railway MySQL] atau [PlanetScale]) lalu set `DATABASE_URL` di Environment.
4. Set env `DATABASE_URL` = connection string MySQL kamu → deploy.

### ⚠️ Netlify TIDAK BISA dipakai untuk project ini

Netlify hanya menjalankan **situs statis + serverless function JavaScript/Go** — **tidak ada runtime PHP**.
Aplikasi ini butuh server PHP + MySQL (login, session, CRUD, inference CNN), jadi **mustahil** jalan di Netlify.
Kalau dicoba, yang muncul cuma kode PHP mentah / error. Pakai **Railway** atau **Render** di atas.

---

## Catatan Teknis Model

- CNN *from scratch* 4 conv-block, val_acc ~83%, output identik PyTorch (selisih ~2e-7).
- BatchNorm di-*fold* ke conv saat load; conv pakai tap-based accumulation (cepat).
- Resolusi inference diatur di `config.php` (`img_size`): 224 (akurat) / 160 (default) / 128 (cepat).
- Regenerasi bobot (opsional, butuh Python sekali): `python tools/export_weights.py`.
