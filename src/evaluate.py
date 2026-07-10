"""
Evaluation: confusion matrix + classification report + per-class accuracy.
Usage:
    python -m src.evaluate --model transfer
"""
import argparse

import matplotlib.pyplot as plt
import numpy as np
import seaborn as sns
import torch
from sklearn.metrics import classification_report, confusion_matrix

from src import config
from src.data_loader import get_dataloaders
from src.models.cnn_scratch import build_cnn_scratch
from src.models.transfer_learning import build_transfer_model


def load_model(model_name: str):
    if model_name == "cnn_scratch":
        ckpt_path = config.CNN_SCRATCH_PATH
        ckpt = torch.load(ckpt_path, map_location=config.DEVICE)
        model = build_cnn_scratch()
    else:
        ckpt_path = config.TRANSFER_PATH
        ckpt = torch.load(ckpt_path, map_location=config.DEVICE)
        # Backbone diambil dari checkpoint biar arsitektur cocok sama bobot
        backbone = ckpt.get("backbone", config.BACKBONE)
        model = build_transfer_model(freeze_backbone=False, backbone=backbone)

    model.load_state_dict(ckpt["model_state"])
    model = model.to(config.DEVICE)
    model.eval()
    return model, ckpt


@torch.no_grad()
def evaluate(model_name: str):
    model, ckpt = load_model(model_name)
    _, val_loader, info = get_dataloaders(use_weighted_sampler=False)

    all_preds, all_labels = [], []
    for images, labels in val_loader:
        images = images.to(config.DEVICE)
        outputs = model(images)
        preds = outputs.argmax(dim=1).cpu().numpy()
        all_preds.extend(preds)
        all_labels.extend(labels.numpy())

    all_preds = np.array(all_preds)
    all_labels = np.array(all_labels)

    # Classification report
    target_names = [info["idx_to_class"][i] for i in range(config.NUM_CLASSES)]
    print(f"\n=== Classification Report: {model_name} ===")
    print(classification_report(all_labels, all_preds, target_names=target_names, digits=4))

    # Confusion matrix
    cm = confusion_matrix(all_labels, all_preds)
    plt.figure(figsize=(8, 6))
    sns.heatmap(cm, annot=True, fmt="d", cmap="Blues",
                xticklabels=target_names, yticklabels=target_names)
    plt.title(f"Confusion Matrix - {model_name}")
    plt.ylabel("True Label")
    plt.xlabel("Predicted Label")
    plt.tight_layout()
    out_path = config.LOGS_DIR / f"confusion_matrix_{model_name}.png"
    plt.savefig(out_path, dpi=120)
    print(f"Confusion matrix saved: {out_path}")

    # Per-class accuracy
    print("\n=== Per-class Accuracy ===")
    for i, name in enumerate(target_names):
        mask = all_labels == i
        if mask.sum() > 0:
            acc = (all_preds[mask] == all_labels[mask]).mean()
            print(f"  {name}: {acc:.4f} ({int(mask.sum())} samples)")


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", choices=["cnn_scratch", "transfer"], default="transfer")
    args = parser.parse_args()
    evaluate(args.model)
