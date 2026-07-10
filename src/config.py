"""
Central configuration for Indonesian Fish Classification project.
Semua hyperparameter dan path diatur di sini biar modular.
"""
from pathlib import Path

# ===== PATH CONFIG =====
ROOT_DIR = Path(__file__).resolve().parent.parent
DATASET_DIR = ROOT_DIR / "dataset"
TRAIN_DIR = DATASET_DIR / "train"
VAL_DIR = DATASET_DIR / "validation"
MODELS_DIR = ROOT_DIR / "models"
LOGS_DIR = ROOT_DIR / "logs"

MODELS_DIR.mkdir(exist_ok=True)
LOGS_DIR.mkdir(exist_ok=True)

# ===== CLASS NAMES =====
# Diambil dari nama folder di dataset/train, sorted alfabet (default ImageFolder)
CLASS_NAMES = ["Bawal Putih", "Nila", "Pari", "Tongkol", "Tuna"]
NUM_CLASSES = len(CLASS_NAMES)

# ===== IMAGE CONFIG =====
IMG_SIZE = 224  # Standard untuk pretrained models (MobileNet, ResNet, dll)
IMG_CHANNELS = 3

# Mean & std ImageNet (dipake buat normalize transfer learning)
IMAGENET_MEAN = [0.485, 0.456, 0.406]
IMAGENET_STD = [0.229, 0.224, 0.225]

# ===== TRANSFER LEARNING BACKBONE =====
# Pilihan: "efficientnet_b0" (default, akurat & masih ringan), "mobilenet_v2",
# "resnet18", "resnet50". Karena lu punya GPU, efficientnet_b0 sweet-spot.
BACKBONE = "efficientnet_b0"

# ===== TRAINING CONFIG =====
BATCH_SIZE = 32             # turun dari 64: dataset kecil, batch kecil = generalisasi lebih baik
NUM_WORKERS = 4             # Set 0 di Windows kalau ada error multiprocessing
EPOCHS = 60                 # dipakai kalau training 1-fase (cnn_scratch)
LEARNING_RATE = 1e-3
WEIGHT_DECAY = 5e-4          # AdamW decoupled weight decay
EARLY_STOPPING_PATIENCE = 10

# ===== 2-PHASE FINE-TUNING (buat transfer learning) =====
# Fase 1: backbone di-freeze, cuma head yang dilatih (warm-up head).
# Fase 2: backbone di-unfreeze, dilatih pelan pakai LR kecil -> ini yang bikin
#         model belajar fitur khusus IKAN, bukan cuma ngandelin fitur ImageNet.
HEAD_EPOCHS = 5             # fase 1
FINETUNE_EPOCHS = 25        # fase 2
HEAD_LR = 1e-3             # LR fase 1 (head only)
FINETUNE_LR = 1e-4        # LR fase 2 (backbone, kecil biar gak ngerusak pretrained)
FINETUNE_HEAD_LR = 5e-4   # LR head di fase 2 (boleh sedikit lebih besar dari backbone)

# ===== CLASS IMBALANCE =====
# Seberapa agresif nyeimbangin kelas kecil (Tuna 69) lewat oversampling.
# 1.0 = paksa rata (bikin model over-tebak Tuna -> Tongkol ketuker Tuna).
# 0.5 = sqrt sampling (REKOMENDASI, ngurangin bias Tuna). 0.0 = distribusi asli.
SAMPLER_POWER = 0.5

# ===== TRAINING TRICKS =====
WARMUP_EPOCHS = 2            # linear warmup sebelum cosine decay (per fase)
LABEL_SMOOTHING = 0.1        # soft target, kurangi overconfidence
MIXUP_ALPHA = 0.2            # Beta(alpha, alpha) buat mixup; 0 = off
MIXUP_PROB = 0.3             # peluang batch di-mixup (turun dari 0.5)
GRAD_CLIP_NORM = 1.0         # gradient clipping biar stabil
RANDOM_ERASING_P = 0.25      # augmentasi: hapus patch random

# ===== INFERENCE =====
# Kalau confidence prediksi < threshold, dianggap "Tidak yakin / bukan ikan yang dikenali".
# Penting karena lu tes campur foto HP + Google (out-of-distribution).
CONFIDENCE_THRESHOLD = 0.55
USE_TTA = True               # test-time augmentation (rata-rata beberapa crop) saat inference

# ===== DEVICE =====
import torch
DEVICE = torch.device("cuda" if torch.cuda.is_available() else "cpu")

# ===== MODEL CHECKPOINTS =====
CNN_SCRATCH_PATH = MODELS_DIR / "cnn_scratch_best.pth"
TRANSFER_PATH = MODELS_DIR / "transfer_best.pth"

# ===== API CONFIG =====
API_HOST = "0.0.0.0"
API_PORT = 8000

# Default model yang dipake API (bisa di-override via env)
DEFAULT_MODEL = "transfer"  # "transfer" atau "cnn_scratch"
