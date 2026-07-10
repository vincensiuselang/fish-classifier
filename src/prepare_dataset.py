"""
Re-split dataset biar JUJUR & bebas leakage. CRASH-SAFE.

Kenapa perlu:
- Dataset ini hasil export Roboflow (nama file ada `_jpg.rf.<hash>`), artinya
  1 gambar asli bisa punya beberapa versi augmentasi.
- Di split lama, versi augmentasi dari gambar yang SAMA nyebar ke train DAN validation
  (kebocoran / data leakage). Akibatnya val_acc keliatan tinggi (95%) padahal
  bohong -> pas tes foto asli, ancur.

Solusi di sini:
1. Backup dataset lama ke zip (jaga-jaga).
2. Kumpulin semua gambar per kelas, grup berdasarkan "base-id" (gambar asli).
3. Split by base-id (bukan by file) -> augmentasi dari 1 gambar gak akan
   nyebrang split. Leakage = 0.
4. Validation di-DEDUPE: cuma 1 copy per base-id (tanpa augmentasi ganda),
   biar skor val = estimasi jujur performa real.
5. Train: ambil SEMUA copy dari base-id train.

PENTING (crash-safe):
- Semua file baru ditulis dulu ke folder STAGING (_train_new / _val_new).
- Folder train/ & validation/ lama BARU dihapus + diganti SETELAH semua copy sukses.
- Jadi kalau proses kepotong di tengah (Ctrl+C / error), dataset asli lu AMAN,
  gak akan kehapus duluan.

Usage:
    python -m src.prepare_dataset                 # split 80/20, crash-safe swap
    python -m src.prepare_dataset --val-ratio 0.2 --seed 42
    python -m src.prepare_dataset --no-backup     # skip bikin zip backup
"""
import argparse
import random
import re
import shutil
from collections import defaultdict
from datetime import datetime
from pathlib import Path

from src import config

# base-id = bagian nama file sebelum "_jpg.rf." (khas Roboflow).
# Kalau pola gak ketemu, fallback ke nama file utuh (dianggap unik).
_RF_PATTERN = re.compile(r"(.+?)_jpg\.rf\.", re.IGNORECASE)
_IMG_EXT = {".jpg", ".jpeg", ".png", ".bmp", ".webp"}


def _base_id(filename: str) -> str:
    m = _RF_PATTERN.match(filename)
    return m.group(1) if m else filename


def _iter_class_dirs(split_dir: Path):
    if not split_dir.exists():
        return
    for cls_dir in sorted(split_dir.iterdir()):
        if cls_dir.is_dir():
            yield cls_dir


def collect_by_class(dataset_dir: Path) -> dict:
    """
    Gabungin semua file dari train + validation lama, grup:
        class -> base_id -> [list Path file]
    """
    pool = defaultdict(lambda: defaultdict(list))
    for split in ("train", "validation"):
        split_dir = dataset_dir / split
        for cls_dir in _iter_class_dirs(split_dir):
            cls = cls_dir.name
            for f in cls_dir.iterdir():
                if f.is_file() and f.suffix.lower() in _IMG_EXT:
                    pool[cls][_base_id(f.name)].append(f)
    return pool


def backup_dataset(dataset_dir: Path) -> Path:
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_path = dataset_dir.parent / f"dataset_backup_{ts}"
    print(f"[backup] Zipping dataset lama -> {backup_path}.zip ...")
    shutil.make_archive(str(backup_path), "zip", root_dir=str(dataset_dir))
    print("[backup] Selesai.")
    return backup_path.with_suffix(".zip")


def resplit(dataset_dir: Path, val_ratio: float, seed: int, do_backup: bool):
    random.seed(seed)

    pool = collect_by_class(dataset_dir)
    if not pool:
        raise SystemExit(
            f"Gak nemu gambar di {dataset_dir}/train atau /validation.\n"
            "Kalau folder-nya kosong (mungkin kehapus proses sebelumnya), extract dulu "
            "backup zip lu (dataset_backup_*.zip) ke folder dataset/."
        )

    if do_backup:
        backup_dataset(dataset_dir)

    train_dir = dataset_dir / "train"
    val_dir = dataset_dir / "validation"
    # Folder STAGING sementara (belum nyentuh yang lama)
    stg_train = dataset_dir / "_train_new"
    stg_val = dataset_dir / "_val_new"
    for d in (stg_train, stg_val):
        if d.exists():
            shutil.rmtree(d)
        d.mkdir(parents=True, exist_ok=True)

    print(f"\n{'Kelas':<14} {'base':>5} {'train_base':>10} {'val_base':>9} "
          f"{'train_files':>11} {'val_files':>9}")
    print("-" * 62)

    grand = defaultdict(int)
    for cls in sorted(pool.keys()):
        base_ids = list(pool[cls].keys())
        random.shuffle(base_ids)

        n_val = max(1, round(len(base_ids) * val_ratio))
        n_val = min(n_val, len(base_ids) - 1) if len(base_ids) > 1 else 0

        val_ids = set(base_ids[:n_val])
        train_ids = set(base_ids[n_val:])

        (stg_train / cls).mkdir(parents=True, exist_ok=True)
        (stg_val / cls).mkdir(parents=True, exist_ok=True)

        tf = vf = 0
        for bid in train_ids:                      # train: semua copy
            for src in pool[cls][bid]:
                shutil.copy2(src, stg_train / cls / src.name)
                tf += 1
        for bid in val_ids:                        # val: dedupe 1 copy/base
            src = sorted(pool[cls][bid])[0]
            shutil.copy2(src, stg_val / cls / src.name)
            vf += 1

        grand["train_files"] += tf
        grand["val_files"] += vf
        grand["train_base"] += len(train_ids)
        grand["val_base"] += len(val_ids)
        print(f"{cls:<14} {len(base_ids):>5} {len(train_ids):>10} {len(val_ids):>9} "
              f"{tf:>11} {vf:>9}")

    print("-" * 62)
    print(f"{'TOTAL':<14} {'':>5} {grand['train_base']:>10} {grand['val_base']:>9} "
          f"{grand['train_files']:>11} {grand['val_files']:>9}")

    # ---- SWAP: semua copy udah sukses, baru ganti folder lama ----
    print("\n[swap] Copy sukses. Mengganti folder lama dengan hasil baru ...")
    for old, stg in ((train_dir, stg_train), (val_dir, stg_val)):
        if old.exists():
            shutil.rmtree(old)
        stg.rename(old)

    print("[OK] Re-split selesai. Leakage antar-split = 0 (split by base-image).")
    print("     Validation sudah di-dedupe -> skor val sekarang jujur.")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--val-ratio", type=float, default=0.2)
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--no-backup", action="store_true", help="Skip bikin zip backup")
    args = ap.parse_args()

    resplit(config.DATASET_DIR, args.val_ratio, args.seed, do_backup=not args.no_backup)
