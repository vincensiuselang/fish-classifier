# 🚀 Panduan Deploy Online (Gratis)

Project ini punya **2 aplikasi** yang di-deploy dari **1 repo GitHub**:

| App | Folder | Platform gratis | Butuh apa |
|-----|--------|-----------------|-----------|
| **Python** (FastAPI + PyTorch, upload gambar → prediksi) | root (`app/` + `src/`) | **Render** | cuma repo |
| **PHP** (web app: login, CRUD, katalog) | `fish-classifier-php/` | **Railway** | repo + MySQL |

> Kenapa beda platform? Render free enak buat Python & gak butuh database.
> PHP butuh MySQL — Railway nyediain MySQL gratis (trial credit) + config-nya udah
> otomatis baca env Railway. Dua-duanya connect ke GitHub yang sama.

Yang udah gua siapin: `Dockerfile`, `render.yaml`, `.gitignore`, `.dockerignore`,
`requirements-web.txt`, frontend disajikan langsung sama FastAPI (gak ada lagi URL
Railway mati yang di-hardcode). Tinggal ikutin langkah di bawah.

---

## STEP 0 — Push ke GitHub (sekali aja)

Buka **Terminal** di folder project ini, jalanin satu per satu:

```bash
# Bersihin state git lama (aman, cuma reset metadata git — file lu gak kehapus)
rm -rf .git

git init
git add -A
git commit -m "Deploy: fish classifier (Python + PHP)"
```

Terus bikin repo kosong di https://github.com/new (kasih nama misal `fish-classifier`,
**jangan** centang "Add README"). Habis itu:

```bash
git branch -M main
git remote add origin https://github.com/vincensiuselang/fish-classifier.git
git push -u origin main
```

> Ganti URL-nya sama repo lu. Dataset (81MB) & file `.zip` backup (83MB) **sengaja
> di-exclude** lewat `.gitignore` biar push cepet & gak nabrak limit GitHub. Model
> (`.pth` 16MB + weights PHP) **ikut ke-push** karena dibutuhin buat prediksi.

---

## STEP 1 — Deploy Python ke Render

1. Masuk https://render.com → login pake GitHub.
2. **New +** → **Blueprint** → pilih repo `fish-classifier`.
   Render bakal auto-detect `render.yaml` dan `Dockerfile`.
   *(Alternatif manual: New + → **Web Service** → pilih repo → Runtime **Docker**,
   Root Directory kosongin (root repo), Plan **Free**.)*
3. Klik **Apply / Create**. Build makan waktu ~5–10 menit (install PyTorch CPU).
4. Selesai → dapat URL kayak `https://fish-classifier-py.onrender.com`.
   Buka → langsung muncul UI upload gambar. Coba upload foto ikan → harusnya jalan.

**Yang perlu lu tau soal Render free:**
- Server **tidur** setelah 15 menit nganggur. Request pertama abis tidur butuh
  ~30–60 detik buat "bangun" (cold start). Ini normal di free tier.
- RAM free cuma **512MB**. PyTorch lumayan berat — harusnya muat buat 1 model kecil,
  tapi **kalau build/run gagal karena "out of memory"**, ini plan B:
  - Deploy Python-nya ke **Railway** aja (railpack udah disiapin) — RAM lebih lega, atau
  - Pake **Hugging Face Spaces** (16GB RAM, gratis beneran, paling cocok buat model).
  Bilang aja ke gua kalau kena OOM, nanti gua pinduin.

---

## STEP 2 — Deploy PHP ke Railway

1. Masuk https://railway.app → login GitHub.
2. **New Project** → **Deploy from GitHub repo** → pilih `fish-classifier`.
3. Di service yang kebuat → **Settings** → **Root Directory** → isi `fish-classifier-php`.
   (Railway bakal pake `Dockerfile` di folder itu.)
4. Tambah database: di project → **New** → **Database** → **Add MySQL**.
   Railway otomatis bikin env var `MYSQL*` — **config PHP lu udah auto-baca ini**,
   gak perlu edit apa-apa.
5. Balik ke service PHP → tab **Variables** → pastikan MySQL-nya ke-*reference*.
   Cara paling gampang: klik service PHP → Variables → **Add Reference** → pilih
   variabel dari MySQL (`MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`,
   `MYSQLDATABASE`). Atau kalau ada `MYSQL_URL`, reference itu aja cukup.
6. Deploy jalan otomatis. Skema DB + akun demo **auto-import** pas start
   (lewat `docker-entrypoint.sh` → `migrate.php`), jadi gak usah import manual.
7. **Settings → Networking → Generate Domain** biar dapat URL publik.

**Login demo (udah otomatis ke-seed):**
- Admin: `admin` / `admin123` (bisa kelola Katalog Ikan)
- User: `demo` / `demo123`

### Soal mesin prediksi PHP (penting)

Default PHP nembak ke API Python dulu (cepat + akurat), kalau mati baru fallback ke
CNN murni PHP (jalan tapi lambat ~30 detik). Pilih salah satu di **Variables**:

- **Biar cepet & akurat** (rekomendasi, karena Python-nya udah lu deploy di Step 1):
  ```
  FISH_PY_API = https://fish-classifier-py.onrender.com
  FISH_PY_MODEL = transfer
  ```
  *(inget: kalau Render lagi cold-start, prediksi pertama agak lama)*
- **Biar 100% mandiri** (gak butuh Python sama sekali, tapi tiap prediksi ~30 detik):
  ```
  FISH_INFER = php
  ```

---

## STEP 3 — (Opsional) Rapiin

- **Custom domain**: dua platform kasih subdomain gratis. Cukup buat demo/UAS.
- **Frontend Python** udah pake same-origin, jadi gak perlu diutak-atik lagi.
- Kalau nanti retrain model, tinggal `git add models/ && git commit && git push` →
  Render auto-redeploy.

---

## Kalau Ada Error — Cek Ini Dulu

| Gejala | Kemungkinan | Fix |
|--------|-------------|-----|
| Render build gagal / "out of memory" | 512MB kurang buat torch | Pindah ke Railway/HF Spaces (lihat Step 1 plan B) |
| Prediksi pertama lama banget | Render cold start abis tidur | Normal, tunggu ~30–60s |
| PHP "gagal konek database" | MySQL var belum ke-reference | Ulang Step 2 no.5 |
| PHP prediksi lama ~30s | Lagi pake engine PHP murni | Set `FISH_PY_API` ke URL Render |
| `git push` ditolak "file too large" | Ada file >100MB ke-stage | Pastikan `.gitignore` kepush; jangan add `dataset/` atau `.zip` |

Ada yang nyangkut? Screenshot error-nya, lempar ke gua.
