"""
Transfer learning model.

Default backbone: EfficientNet-B0 (config.BACKBONE) - fitur ImageNet-nya jauh lebih
kuat dari MobileNetV2 tapi masih ringan buat GPU lokal. Tetap support mobilenet_v2,
resnet18, resnet50 lewat argumen `backbone`.

Alur pakai:
    model = build_transfer_model(freeze_backbone=True)   # Fase 1: latih head only
    unfreeze_backbone(model)                             # Fase 2: fine-tune semua
"""
import torch
import torch.nn as nn
from torchvision import models

from src import config


def _make_head(in_features: int, num_classes: int) -> nn.Sequential:
    """Classifier head standar buat semua backbone."""
    return nn.Sequential(
        nn.Dropout(0.4),
        nn.Linear(in_features, 256),
        nn.ReLU(inplace=True),
        nn.Dropout(0.3),
        nn.Linear(256, num_classes),
    )


def _set_backbone_requires_grad(model: nn.Module, backbone: str, requires_grad: bool):
    """Freeze/unfreeze bagian feature extractor (bukan head)."""
    if backbone in ("mobilenet_v2", "efficientnet_b0"):
        for p in model.features.parameters():
            p.requires_grad = requires_grad
    elif backbone in ("resnet18", "resnet50"):
        for name, p in model.named_parameters():
            if not name.startswith("fc."):
                p.requires_grad = requires_grad


def build_transfer_model(
    num_classes: int = config.NUM_CLASSES,
    freeze_backbone: bool = True,
    backbone: str = None,
) -> nn.Module:
    """
    Build pretrained model dengan custom classifier head.

    Args:
        num_classes: jumlah kelas output
        freeze_backbone: True = fase 1 (head only), False = fine-tune semua
        backbone: override config.BACKBONE kalau diisi

    Returns:
        nn.Module + atribut `.backbone_name` biar train/predict tau tipe backbone
    """
    backbone = backbone or config.BACKBONE

    if backbone == "efficientnet_b0":
        model = models.efficientnet_b0(weights=models.EfficientNet_B0_Weights.IMAGENET1K_V1)
        in_features = model.classifier[-1].in_features
        model.classifier = _make_head(in_features, num_classes)

    elif backbone == "mobilenet_v2":
        model = models.mobilenet_v2(weights=models.MobileNet_V2_Weights.IMAGENET1K_V1)
        in_features = model.classifier[-1].in_features
        model.classifier = _make_head(in_features, num_classes)

    elif backbone == "resnet18":
        model = models.resnet18(weights=models.ResNet18_Weights.IMAGENET1K_V1)
        model.fc = _make_head(model.fc.in_features, num_classes)

    elif backbone == "resnet50":
        model = models.resnet50(weights=models.ResNet50_Weights.IMAGENET1K_V1)
        model.fc = _make_head(model.fc.in_features, num_classes)

    else:
        raise ValueError(
            f"Backbone '{backbone}' belum di-support. "
            "Pake: efficientnet_b0 / mobilenet_v2 / resnet18 / resnet50"
        )

    if freeze_backbone:
        _set_backbone_requires_grad(model, backbone, requires_grad=False)

    # Simpan nama backbone di model biar gampang di-referensi
    model.backbone_name = backbone
    return model


def unfreeze_backbone(model: nn.Module):
    """
    Fase 2 fine-tuning: unfreeze SEMUA params. Pakai LR kecil (config.FINETUNE_LR).
    """
    backbone = getattr(model, "backbone_name", config.BACKBONE)
    _set_backbone_requires_grad(model, backbone, requires_grad=True)
    return model


def get_param_groups(model: nn.Module, backbone_lr: float, head_lr: float):
    """
    Discriminative learning rate: backbone (fitur ImageNet) di-tune pelan,
    head (baru, random init) di-tune lebih cepat. Return buat optimizer.
    """
    head_prefixes = ("classifier.", "fc.")
    backbone_params, head_params = [], []
    for name, p in model.named_parameters():
        if not p.requires_grad:
            continue
        if name.startswith(head_prefixes):
            head_params.append(p)
        else:
            backbone_params.append(p)

    groups = []
    if backbone_params:
        groups.append({"params": backbone_params, "lr": backbone_lr})
    if head_params:
        groups.append({"params": head_params, "lr": head_lr})
    return groups


if __name__ == "__main__":
    model = build_transfer_model()
    x = torch.randn(2, 3, 224, 224)
    out = model(x)
    print(f"Backbone: {model.backbone_name} | Output shape: {out.shape}")
    n_trainable = sum(p.numel() for p in model.parameters() if p.requires_grad)
    n_total = sum(p.numel() for p in model.parameters())
    print(f"Trainable: {n_trainable:,} / Total: {n_total:,}")
