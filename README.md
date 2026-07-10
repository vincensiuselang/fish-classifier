# 🐟 Klasifikasi Ikan Indonesia

Project machine learning end-to-end buat klasifikasi 5 jenis ikan Indonesia (**Bawal Putih, Nila, Pari, Tongkol, Tuna**) pake PyTorch, dari training sampai deployment ke web pake FastAPI + frontend.

## Struktur Project

```
PROJECT PWL/
├── dataset/                    # Data ikan (train/ + validation/)
│   ├── train/
│   │   ├── Bawal Putih/
│   │   ├── Nila/
│   │   ├── Pari/
│   │   ├── Tongkol/
│   │   └── Tuna/
│   └── validation/
│       └── ...
├── src/                        # Module ML (modular)
│   ├── config.py               # Semua hyperparameter & path
│   ├── data_loader.py          # DataLoader + augmentation + handle imbalance
│   ├── models/
│   │   ├── cnn_scratch.py      # CNN custom from scratch
│   │   └── transfer_learning.py # EfficientNet-B0 / MobileNetV2 / ResNet pretrained
│   ├── prepare_dataset.py      # Re-split dataset by base-image (anti-leakage)
│   ├── train.py                # Training loop (transfer = 2-fase fine-tuning)
│   ├── evaluate.py             # Confusion matrix + classification report
│   └── predict.py              # Single image inference (+ confidence threshold & TTA)
├── app/                        # Deployment
│   ├── backend/
│   │   └── main.py             # FastAPI server
│   └── frontend/
│       ├── index.html          # UI upload + result
│       ├── style.css
│       └── script.js
├── models/                     # Saved checkpoints (auto-generated)
├── logs/                       # Training history + confusion matrix
├── notebook/
│   └── eda.ipynb
├── requirements.txt
└── README.md
```

## Setup

```bash
# 1. Bikin virtual env (recommended)
python -m venv venv
venv\Scripts\activate          # Windows
# source venv/bin/activate     # Linux/Mac

# 2. Install dependencies
pip install -r requirements.txt
```

## ⚠️ PENTING — Baca Dulu Sebelum Retrain (v3)

Versi ini benerin masalah **"val acc tinggi tapi tes foto asli ngaco"**. Akar masalahnya
BUKAN overfitting biasa, tapi:
1. **Data leakage** — versi augmentasi (Roboflow `.rf.`) dari gambar yang sama nyebar
   ke train DAN validation, jadi skor val palsu.
2. **Domain gap** — dataset kecil & seragam, model hafal karakteristik dataset bukan ikannya.
3. **Transform val salah** — `Resize((224,224))` nge-squish aspect ratio.
4. **Backbone freeze total** — model gak pernah belajar fitur khusus ikan.

Yang diubah: re-split anti-leakage, EfficientNet-B0 + **fine-tuning 2-fase**, transform
val diperbaiki (Resize 256 → CenterCrop 224), confidence threshold + TTA saat inference.

### Alur retrain yang bener (WAJIB urut)

```bash
# LANGKAH 0 (sekali): rapiin dataset — bikin backup zip, re-split by base-image,
#                     val di-dedupe biar skornya jujur. TIMPA folder dataset lama.
python -m src.prepare_dataset

# LANGKAH 1: train transfer learning (2-fase, otomatis). Butuh GPU biar cepet.
python -m src.train --model transfer
#   (opsional ganti backbone: --backbone resnet50 / mobilenet_v2)

# LANGKAH 2: cek performa jujur (confusion matrix + per-class accuracy)
python -m src.evaluate --model transfer

# LANGKAH 3: tes 1 gambar dari CLI
python -m src.predict --image path/ke/ikan.jpg --model transfer
```

Catatan: kalau confidence < `CONFIDENCE_THRESHOLD` (default 0.55 di config.py), hasil
ditandai **"Tidak yakin"** — ini normal buat foto di luar distribusi (mis. gambar Google
random / bukan 5 ikan itu). Atur threshold & backbone di `src/config.py`.

## Cara Pakai

Langkah Nyalain Backend
Buka terminal baru (PowerShell / CMD), terus copy-paste ini satu per satu:
1. Masuk ke folder project
bashcd "C:\Users\User\Downloads\PROJECT PWL"
2. Install dependencies (sekali aja, kalau belum pernah)
bashpip install -r requirements.txt
Tunggu sampe selesai. Ini bakal install PyTorch, FastAPI, dll. Bisa makan waktu 2-5 menit.
3. Jalanin backend
uvicorn app.backend.main:app --reload --host 0.0.0.0 --port 8000
Yang lo cari di output:
INFO:     Will watch for changes in these directories: ['C:\\Users\\User\\Downloads\\PROJECT PWL']
INFO:     Uvicorn running on http://0.0.0.0:8000 (Press CTRL+C to quit)
INFO:     Started reloader process
INFO:     Started server process
INFO:     Application startup complete.
Kalau muncul kayak gitu = aman. JANGAN TUTUP TERMINAL INI, biarin jalan.
4. Test lagi di browser
Buka http://localhost:8000 — harusnya muncul JSON.

⚠️ TAPI INGET — Predict Bakal Tetap Error Kalau Model Belum Di-train
Backend bakal jalan, tapi pas lo klik "Prediksi Sekarang" dengan model CNN from Scratch, bakal error 503 karena file models/cnn_scratch_best.pth belum ada.
Cek dulu apakah lo udah train:
bashdir "C:\Users\User\Downloads\PROJECT PWL\models"
Kalau kosong / cuma ada folder = lo belum train. Wajib train dulu sebelum bisa predict.
Cara Train (terminal terpisah lagi)
bashcd "C:\Users\User\Downloads\PROJECT PWL"

# Train transfer learning (lebih cepet, ~5-10 menit)
python -m src.train --model transfer

# Train CNN scratch (lebih lama, ~15-30 menit)
python -m src.train --model cnn_scratch

Summary Flow yang Bener
Jadi lo butuh 3 terminal kebuka bareng:
TerminalKerjaannyaCommand1Backend APIuvicorn app.backend.main:app --reload2Frontend servercd app/frontend && python -m http.server 55003Buat training (sekali doang)python -m src.train --model transfer
Coba step 1-3 dulu (nyalain backend), terus screenshot output terminal-nya. Kalau ada error pas pip 