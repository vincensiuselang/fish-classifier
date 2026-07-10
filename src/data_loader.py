"""
Data loader module: load dataset, transforms, augmentation, dan handle class imbalance.
"""
import torch
from torch.utils.data import DataLoader, WeightedRandomSampler
from torchvision import datasets, transforms
from collections import Counter
from src import config


def get_transforms(train: bool = True):
    """
    Bikin transforms buat train atau validation.
    Train pake augmentation, val cuma resize + normalize.
    """
    if train:
        return transforms.Compose([
            # RandomResizedCrop lebih agresif dari resize+crop: scale & aspect ratio random
            transforms.RandomResizedCrop(config.IMG_SIZE, scale=(0.6, 1.0), ratio=(0.75, 1.33)),
            transforms.RandomHorizontalFlip(p=0.5),
            # TrivialAugmentWide: auto-augmentation policy (rotate, color, shear, dll)
            # terbukti lebih bagus dari manual ColorJitter+Rotation, tanpa tuning
            transforms.TrivialAugmentWide(),
            transforms.ToTensor(),
            transforms.Normalize(mean=config.IMAGENET_MEAN, std=config.IMAGENET_STD),
            # RandomErasing: paksa model gak bergantung ke satu bagian gambar doang
            transforms.RandomErasing(p=config.RANDOM_ERASING_P, scale=(0.02, 0.15)),
        ])
    else:
        # PENTING: Resize(256) -> CenterCrop(224), BUKAN Resize((224,224)).
        # Resize((224,224)) nge-squish aspect ratio -> ikan jadi gepeng/melar,
        # beda dari cara model pretrained ImageNet "melihat" gambar -> inference
        # real-world jelek. Resize sisi pendek ke 256 lalu crop tengah = standar
        # ImageNet, aspect ratio kejaga.
        return transforms.Compose([
            transforms.Resize(256),
            transforms.CenterCrop(config.IMG_SIZE),
            transforms.ToTensor(),
            transforms.Normalize(mean=config.IMAGENET_MEAN, std=config.IMAGENET_STD),
        ])


def make_weighted_sampler(dataset, power: float = None):
    """
    Bikin WeightedRandomSampler buat handle class imbalance.

    weight per kelas = (1 / count) ** power
        power=1.0 -> full inverse (kelas kecil di-oversample sampai PARITY).
                     Efek samping: model over-predict kelas kecil (Tuna).
        power=0.5 -> sqrt sampling (REKOMENDASI): imbalance dikurangi tapi gak
                     dipaksa rata -> bias ke Tuna jauh berkurang.
        power=0.0 -> distribusi natural (gak nyeimbangin sama sekali).
    """
    power = config.SAMPLER_POWER if power is None else power
    targets = [label for _, label in dataset.samples]
    class_counts = Counter(targets)
    class_weights = {cls: (1.0 / count) ** power for cls, count in class_counts.items()}
    sample_weights = [class_weights[t] for t in targets]
    sampler = WeightedRandomSampler(
        weights=sample_weights,
        num_samples=len(sample_weights),
        replacement=True,
    )
    return sampler


def get_class_weights(dataset):
    """
    Alternatif: class weights buat dipake di CrossEntropyLoss.
    Return tensor dengan shape (num_classes,).
    """
    targets = [label for _, label in dataset.samples]
    class_counts = Counter(targets)
    total = sum(class_counts.values())
    weights = torch.zeros(config.NUM_CLASSES)
    for cls, count in class_counts.items():
        weights[cls] = total / (config.NUM_CLASSES * count)
    return weights


def get_dataloaders(use_weighted_sampler: bool = True):
    """
    Return train_loader, val_loader, dan info tambahan (class_weights, class_to_idx).
    """
    train_dataset = datasets.ImageFolder(
        root=str(config.TRAIN_DIR),
        transform=get_transforms(train=True),
    )
    val_dataset = datasets.ImageFolder(
        root=str(config.VAL_DIR),
        transform=get_transforms(train=False),
    )

    if use_weighted_sampler:
        sampler = make_weighted_sampler(train_dataset)
        train_loader = DataLoader(
            train_dataset,
            batch_size=config.BATCH_SIZE,
            sampler=sampler,
            num_workers=config.NUM_WORKERS,
            pin_memory=True,
        )
    else:
        train_loader = DataLoader(
            train_dataset,
            batch_size=config.BATCH_SIZE,
            shuffle=True,
            num_workers=config.NUM_WORKERS,
            pin_memory=True,
        )

    val_loader = DataLoader(
        val_dataset,
        batch_size=config.BATCH_SIZE,
        shuffle=False,
        num_workers=config.NUM_WORKERS,
        pin_memory=True,
    )

    info = {
        "class_to_idx": train_dataset.class_to_idx,
        "idx_to_class": {v: k for k, v in train_dataset.class_to_idx.items()},
        "class_weights": get_class_weights(train_dataset),
        "train_size": len(train_dataset),
        "val_size": len(val_dataset),
    }
    return train_loader, val_loader, info


if __name__ == "__main__":
    train_loader, val_loader, info = get_dataloaders()
    print(f"Train size: {info['train_size']}, Val size: {info['val_size']}")
    print(f"Classes: {info['class_to_idx']}")
    print(f"Class weights: {info['class_weights']}")
    images, labels = next(iter(train_loader))
    print(f"Batch shape: {images.shape}, Labels: {labels[:5]}")
