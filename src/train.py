"""
Training script v3.

Yang baru dibanding v2:
- Transfer learning sekarang 2-FASE:
    Fase 1 (HEAD_EPOCHS): backbone freeze, latih head doang.
    Fase 2 (FINETUNE_EPOCHS): unfreeze backbone, fine-tune SEMUA pakai
      discriminative LR (backbone LR kecil, head LR sedikit lebih besar).
  Ini kunci biar model belajar fitur khusus IKAN, bukan cuma ngandelin ImageNet
  -> generalisasi ke foto asli jauh lebih bagus.
- Best model di-track LINTAS fase (val_acc terbaik overall yang disimpan).
- cnn_scratch tetap 1-fase seperti biasa.

Usage:
    python -m src.train --model transfer          # 2-fase fine-tuning
    python -m src.train --model cnn_scratch       # 1-fase
    python -m src.train --model transfer --backbone resnet50
"""
import argparse
import json
import time

import numpy as np
import torch
import torch.nn as nn
from torch.optim import AdamW
from torch.optim.lr_scheduler import CosineAnnealingLR, LinearLR, SequentialLR
from tqdm import tqdm

from src import config
from src.data_loader import get_dataloaders
from src.models.cnn_scratch import build_cnn_scratch
from src.models.transfer_learning import (
    build_transfer_model,
    unfreeze_backbone,
    get_param_groups,
)


def mixup_batch(images, labels, alpha: float):
    """Mixup: campur 2 gambar + label-nya secara linear."""
    lam = float(np.random.beta(alpha, alpha))
    idx = torch.randperm(images.size(0), device=images.device)
    mixed = lam * images + (1.0 - lam) * images[idx]
    return mixed, labels, labels[idx], lam


def train_one_epoch(model, loader, optimizer, criterion, device):
    model.train()
    running_loss, correct, total = 0.0, 0, 0
    pbar = tqdm(loader, desc="Train", leave=False)
    for images, labels in pbar:
        images, labels = images.to(device), labels.to(device)

        use_mixup = (
            config.MIXUP_ALPHA > 0
            and np.random.rand() < config.MIXUP_PROB
            and images.size(0) > 1
        )
        optimizer.zero_grad()
        if use_mixup:
            mixed, y_a, y_b, lam = mixup_batch(images, labels, config.MIXUP_ALPHA)
            outputs = model(mixed)
            loss = lam * criterion(outputs, y_a) + (1.0 - lam) * criterion(outputs, y_b)
        else:
            outputs = model(images)
            loss = criterion(outputs, labels)

        loss.backward()
        nn.utils.clip_grad_norm_(model.parameters(), config.GRAD_CLIP_NORM)
        optimizer.step()

        running_loss += loss.item() * images.size(0)
        _, preds = outputs.max(1)
        correct += (preds == labels).sum().item()
        total += labels.size(0)
        pbar.set_postfix(loss=loss.item(), acc=correct / total)

    return running_loss / total, correct / total


@torch.no_grad()
def validate(model, loader, criterion, device):
    model.eval()
    running_loss, correct, total = 0.0, 0, 0
    for images, labels in tqdm(loader, desc="Val", leave=False):
        images, labels = images.to(device), labels.to(device)
        outputs = model(images)
        loss = criterion(outputs, labels)
        running_loss += loss.item() * images.size(0)
        _, preds = outputs.max(1)
        correct += (preds == labels).sum().item()
        total += labels.size(0)
    return running_loss / total, correct / total


def build_scheduler(optimizer, epochs: int, warmup_epochs: int):
    """Linear warmup lalu cosine decay."""
    warmup_epochs = min(warmup_epochs, max(1, epochs - 1))
    warmup = LinearLR(optimizer, start_factor=0.1, end_factor=1.0, total_iters=warmup_epochs)
    cosine = CosineAnnealingLR(optimizer, T_max=max(1, epochs - warmup_epochs), eta_min=1e-6)
    return SequentialLR(optimizer, [warmup, cosine], milestones=[warmup_epochs])


class BestTracker:
    """Simpan checkpoint terbaik (val_acc tertinggi) lintas fase + early stopping."""

    def __init__(self, save_path, model_name, class_to_idx, backbone, patience):
        self.save_path = save_path
        self.model_name = model_name
        self.class_to_idx = class_to_idx
        self.backbone = backbone
        self.patience = patience
        self.best_val_acc = 0.0
        self.counter = 0

    def update(self, model, va_acc, epoch, phase):
        if va_acc > self.best_val_acc:
            self.best_val_acc = va_acc
            self.counter = 0
            torch.save({
                "model_state": model.state_dict(),
                "model_name": self.model_name,
                "backbone": self.backbone,
                "class_to_idx": self.class_to_idx,
                "val_acc": va_acc,
                "epoch": epoch,
                "phase": phase,
            }, self.save_path)
            print(f"  ✓ Saved best (val_acc={va_acc:.4f}, {phase})")
            return False
        self.counter += 1
        return self.counter >= self.patience


def run_phase(model, name, epochs, param_groups, warmup_epochs,
              train_loader, val_loader, criterion, tracker, history):
    """Jalanin satu fase training. Return True kalau early stop."""
    optimizer = AdamW(param_groups, weight_decay=config.WEIGHT_DECAY)
    scheduler = build_scheduler(optimizer, epochs, warmup_epochs)
    n_trainable = sum(p.numel() for p in model.parameters() if p.requires_grad)
    print(f"\n----- {name} | {epochs} epochs | trainable params: {n_trainable:,} -----")

    for epoch in range(1, epochs + 1):
        cur_lr = optimizer.param_groups[0]["lr"]
        print(f"[{name}] Epoch {epoch}/{epochs} (lr={cur_lr:.2e})")
        tr_loss, tr_acc = train_one_epoch(model, train_loader, optimizer, criterion, config.DEVICE)
        va_loss, va_acc = validate(model, val_loader, criterion, config.DEVICE)
        scheduler.step()

        history["train_loss"].append(tr_loss)
        history["train_acc"].append(tr_acc)
        history["val_loss"].append(va_loss)
        history["val_acc"].append(va_acc)
        history["lr"].append(cur_lr)
        history["phase"].append(name)

        print(f"  train_loss={tr_loss:.4f} acc={tr_acc:.4f} | val_loss={va_loss:.4f} acc={va_acc:.4f}")
        if tracker.update(model, va_acc, epoch, name):
            print(f"  Early stopping di {name} epoch {epoch}")
            return True
    return False


def train(model_name: str, backbone: str = None, epochs: int = None):
    print(f"\n{'=' * 60}")
    print(f"Training: {model_name.upper()} | Device: {config.DEVICE}")
    print(f"{'=' * 60}\n")

    train_loader, val_loader, info = get_dataloaders(use_weighted_sampler=True)
    print(f"Train: {info['train_size']} | Val: {info['val_size']}")
    print(f"Classes: {info['idx_to_class']}\n")

    criterion = nn.CrossEntropyLoss(label_smoothing=config.LABEL_SMOOTHING)
    history = {k: [] for k in ("train_loss", "train_acc", "val_loss", "val_acc", "lr", "phase")}
    start = time.time()

    if model_name == "transfer":
        backbone = backbone or config.BACKBONE
        model = build_transfer_model(freeze_backbone=True, backbone=backbone).to(config.DEVICE)
        save_path = config.TRANSFER_PATH
        tracker = BestTracker(save_path, model_name, info["class_to_idx"],
                              backbone, config.EARLY_STOPPING_PATIENCE)

        # FASE 1: head only
        groups = get_param_groups(model, backbone_lr=config.HEAD_LR, head_lr=config.HEAD_LR)
        stop = run_phase(model, "Phase1-head", config.HEAD_EPOCHS, groups,
                         config.WARMUP_EPOCHS, train_loader, val_loader,
                         criterion, tracker, history)

        # FASE 2: unfreeze backbone, discriminative LR
        if not stop:
            unfreeze_backbone(model)
            groups = get_param_groups(model, backbone_lr=config.FINETUNE_LR,
                                      head_lr=config.FINETUNE_HEAD_LR)
            run_phase(model, "Phase2-finetune", config.FINETUNE_EPOCHS, groups,
                      config.WARMUP_EPOCHS, train_loader, val_loader,
                      criterion, tracker, history)

    elif model_name == "cnn_scratch":
        model = build_cnn_scratch().to(config.DEVICE)
        save_path = config.CNN_SCRATCH_PATH
        n_epochs = epochs or config.EPOCHS
        tracker = BestTracker(save_path, model_name, info["class_to_idx"],
                              "cnn_scratch", config.EARLY_STOPPING_PATIENCE)
        groups = [{"params": [p for p in model.parameters() if p.requires_grad],
                   "lr": config.LEARNING_RATE}]
        run_phase(model, "scratch", n_epochs, groups, config.WARMUP_EPOCHS,
                  train_loader, val_loader, criterion, tracker, history)
    else:
        raise ValueError(f"Model '{model_name}' gak dikenal. Pake 'cnn_scratch' atau 'transfer'")

    elapsed = time.time() - start
    print(f"\nTraining selesai dalam {elapsed/60:.1f} menit. Best val_acc: {tracker.best_val_acc:.4f}")

    history_path = config.LOGS_DIR / f"history_{model_name}.json"
    with open(history_path, "w") as f:
        json.dump(history, f, indent=2)
    print(f"History saved: {history_path}")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", choices=["cnn_scratch", "transfer"], default="transfer")
    parser.add_argument("--backbone", default=None,
                        help="Override config.BACKBONE (efficientnet_b0/mobilenet_v2/resnet18/resnet50)")
    parser.add_argument("--epochs", type=int, default=None, help="Khusus cnn_scratch")
    args = parser.parse_args()
    train(args.model, backbone=args.backbone, epochs=args.epochs)
